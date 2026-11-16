<?php

declare(strict_types=1);

namespace Modules\Movewise\Program;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Credits\CreditLedger;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Movewise\Models\Agent;
use Modules\Movewise\Models\Package;
use Modules\Movewise\Models\State;

/**
 * Movewise packages (specs/movewise/00 §6.2, 03). An agent package is bought and worked by the agent and paid from
 * their credits; a broker package is bought by the brokerage, lists which of its agents may be shown, and is paid
 * from the brokerage's credit pool. A package goes live only when its agents are live, the state offers its pricing
 * model, and the wallet holds at least one introduction's worth of credits.
 */
final class Packages
{
    /** New agents get a small cap until 5 connections are rated (tiers arrive with the scorecard, MW6). */
    public const NEW_AGENT_CAP = 5;

    public const MAX_CAP = 200;

    public const TIMEZONES = ['America/Chicago', 'America/Denver'];

    public function __construct(private readonly TenantContext $tenants) {}

    /** @param  array<string, mixed>  $in */
    public function save(?Package $package, User $by, array $in): Package
    {
        $tenantId = $this->tenants->id();
        abort_if($package !== null && $package->tenant_id !== $tenantId, 404);
        $broker = $package !== null ? $package->agent_id === null : (bool) ($in['broker'] ?? false);
        abort_if($broker && ! $by->isKind(UserKind::Owner), 403);
        $state = State::query()->find($package !== null ? $package->state : strtoupper((string) ($in['state'] ?? ''))); // a package stays in its state
        if ($state === null) {
            throw ValidationException::withMessages(['state' => 'Choose a Movewise state.']);
        }
        $agentIds = $broker ? $this->brokerAgents($tenantId, $state->code, (array) ($in['agents'] ?? [])) : [$this->ownAgent($by, $state->code)->id];
        $model = (string) ($in['model'] ?? '');
        if (! in_array($model, Pricing::offered($state), true)) {
            throw ValidationException::withMessages(['model' => 'Choose a pricing model offered in '.$state->name.'.']);
        }
        $leadTypes = array_values(array_intersect(['buyer', 'seller'], array_map('strval', (array) ($in['lead_types'] ?? []))));
        $languages = array_values(array_intersect(['en', 'es'], array_map('strval', (array) ($in['languages'] ?? ['en']))));
        if ($leadTypes === [] || $languages === []) {
            throw ValidationException::withMessages(['lead_types' => 'Choose buyers, sellers or both, and at least one language.']);
        }
        $min = max(0, (int) ($in['price_min'] ?? 0));
        $max = max(0, (int) ($in['price_max'] ?? 0));
        if ($max > 0 && $max <= $min) {
            throw ValidationException::withMessages(['price_max' => 'The highest price must be above the lowest.']);
        }
        $cap = (int) ($in['monthly_cap'] ?? 0);
        $limit = $this->capLimit($agentIds);
        if ($cap < 1 || $cap > $limit) {
            throw ValidationException::withMessages(['monthly_cap' => 'Choose a monthly cap from 1 to '.$limit.($limit === self::NEW_AGENT_CAP ? ' (new agents start small until 5 introductions are rated)' : '').'.']);
        }
        $daily = isset($in['daily_cap']) && $in['daily_cap'] !== '' ? max(1, min($cap, (int) $in['daily_cap'])) : null;

        $data = ['tenant_id' => $tenantId, 'agent_id' => $broker ? null : $agentIds[0], 'payer' => $broker ? 'brokerage' : 'agent', 'payer_user_id' => $broker ? null : $by->id,
            'name' => mb_substr(trim((string) ($in['name'] ?? '')) ?: $state->name.' '.implode(' and ', $leadTypes), 0, 120), 'state' => $state->code,
            'areas' => $this->areas($in), 'price_min' => $min, 'price_max' => $max, 'lead_types' => $leadTypes, 'languages' => $languages,
            'monthly_cap' => $cap, 'daily_cap' => $daily, 'hours' => $this->hours($in), 'model' => $model];
        $package ??= new Package(['status' => 'draft', 'created_by' => $by->id]);
        DB::connection(Connections::control())->transaction(function () use ($package, $data, $broker, $agentIds): void {
            $package->fill($data)->save();
            if ($broker) {
                $db = DB::connection(Connections::control())->table('movewise_package_agents');
                $db->where('package_id', $package->id)->delete();
                $db->insert(array_map(fn (int $a): array => ['package_id' => $package->id, 'agent_id' => $a], $agentIds));
            }
        });
        app(AuditLogger::class)->record('movewise.package.saved', $package, metadata: ['state' => $state->code, 'model' => $model, 'cap' => $cap], label: $package->name);

        return $package;
    }

    /** @return list<string> what stops this package going live */
    public function missing(Package $package): array
    {
        $state = State::query()->findOrFail($package->state);
        $out = [];
        if (! $state->on) {
            $out[] = $state->name.' isn\'t live on Movewise yet';
        }
        if (! in_array($package->model, Pricing::offered($state), true)) {
            $out[] = 'The '.(Pricing::MODELS[$package->model] ?? $package->model).' model isn\'t offered in '.$state->name.' now';
        }
        $live = Agent::query()->whereIn('id', $package->agentIds())->where('status', 'active')->count();
        if ($live === 0) {
            $out[] = $package->agent_id !== null ? 'You\'re not live in the program yet' : 'None of the package\'s agents is live in the program yet';
        }
        $need = max(array_map(fn (string $t): int => Pricing::upfront($state, $package->model, $t), $package->lead_types) ?: [0]);
        if ($need > 0 && $this->balance($package) < $need) {
            $out[] = 'At least '.number_format($need).' credits in the '.($package->payer === 'agent' ? 'agent\'s' : 'brokerage\'s').' wallet';
        }

        return $out;
    }

    public function activate(Package $package, User $by): Package
    {
        $this->authorise($package, $by);
        $missing = $this->missing($package);
        if ($missing !== []) {
            throw ValidationException::withMessages(['package' => 'Not yet: '.implode('; ', $missing).'.']);
        }
        $package->forceFill(['status' => 'active', 'pause_reason' => null])->save();
        app(AuditLogger::class)->record('movewise.package.activated', $package, label: $package->name);

        return $package;
    }

    public function pause(Package $package, User $by, string $reason = 'Paused'): Package
    {
        $this->authorise($package, $by);
        $package->forceFill(['status' => 'paused', 'pause_reason' => mb_substr($reason, 0, 200)])->save();
        app(AuditLogger::class)->record('movewise.package.paused', $package, metadata: ['reason' => $reason], label: $package->name);

        return $package;
    }

    /** The credits in the wallet that pays: the agent's own, or the brokerage pool. */
    public function balance(Package $package): int
    {
        return app(CreditLedger::class)->balanceOf($package->payer === 'agent' ? $package->payer_user_id : null);
    }

    private function authorise(Package $package, User $by): void
    {
        abort_if($package->tenant_id !== $this->tenants->id(), 404);
        abort_unless($by->isKind(UserKind::Owner) || ($package->payer === 'agent' && $package->payer_user_id === $by->id), 403);
    }

    private function ownAgent(User $by, string $state): Agent
    {
        $agent = Agent::query()->where('tenant_id', $this->tenants->id())->where('user_id', $by->id)->where('state', $state)->first();
        if ($agent === null || $agent->status === 'removed') {
            throw ValidationException::withMessages(['state' => 'Apply to the program in this state first.']);
        }

        return $agent;
    }

    /**
     * @param  array<mixed>  $ulids
     * @return list<int>
     */
    private function brokerAgents(int $tenantId, string $state, array $ulids): array
    {
        $ids = Agent::query()->where('tenant_id', $tenantId)->where('state', $state)->whereIn('ulid', array_map('strval', $ulids))->where('status', '!=', 'removed')
            ->whereNotNull('brokerage_confirmed_at')->pluck('id')->map(fn ($i): int => (int) $i)->all();
        if ($ids === []) {
            throw ValidationException::withMessages(['agents' => 'Choose at least one of your confirmed agents in this state.']);
        }

        return array_values($ids);
    }

    /** @param  list<int>  $agentIds */
    private function capLimit(array $agentIds): int
    {
        $tiers = Agent::query()->whereIn('id', $agentIds)->pluck('tier')->all();
        if (in_array('new', $tiers, true) && count($agentIds) === 1) {
            return self::NEW_AGENT_CAP;
        }

        return in_array('preferred', $tiers, true) ? self::MAX_CAP : min(self::MAX_CAP, max(self::NEW_AGENT_CAP, 50 * count($agentIds)));
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array{zips: list<string>, cities: list<string>}
     */
    private function areas(array $in): array
    {
        $split = fn (mixed $v): array => is_array($v) ? array_map('strval', $v) : (preg_split('/[\s,;]+/', (string) $v, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $zips = array_values(array_unique(array_filter((array) $split($in['zips'] ?? []), fn (string $z): bool => preg_match('/^\d{5}$/', $z) === 1)));
        $cities = [];
        foreach (is_array($in['cities'] ?? null) ? $in['cities'] : explode(',', (string) ($in['cities'] ?? '')) as $c) {
            $c = mb_substr(trim(strip_tags((string) $c)), 0, 60);
            if ($c !== '' && ! in_array(mb_strtolower($c), array_map('mb_strtolower', $cities), true)) {
                $cities[] = $c;
            }
        }
        if ($zips === [] && $cities === []) {
            throw ValidationException::withMessages(['areas' => 'Add the ZIP codes or cities you cover.']);
        }
        if (count($zips) > 200 || count($cities) > 50) {
            throw ValidationException::withMessages(['areas' => 'Up to 200 ZIP codes and 50 cities in one package.']);
        }

        return ['zips' => $zips, 'cities' => $cities];
    }

    /**
     * Working hours, for the response timer (MW4): each day's start and end, in the state's time zone.
     *
     * @param  array<string, mixed>  $in
     * @return array{tz: string, days: array<int, array{0: string, 1: string}>}
     */
    private function hours(array $in): array
    {
        $tz = in_array($in['tz'] ?? null, self::TIMEZONES, true) ? (string) $in['tz'] : self::TIMEZONES[0];
        $days = [];
        foreach ((array) ($in['days'] ?? []) as $day => $span) {
            $span = array_values((array) $span);
            if (! in_array((string) $day, ['0', '1', '2', '3', '4', '5', '6'], true) || count($span) !== 2) {
                continue;
            }
            [$from, $to] = [(string) $span[0], (string) $span[1]];
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $from) !== 1 || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $to) !== 1 || $from >= $to) {
                throw ValidationException::withMessages(['days' => 'Each day needs a start and end time, the end after the start.']);
            }
            $days[(int) $day] = [$from, $to];
        }
        if ($days === []) {
            throw ValidationException::withMessages(['days' => 'Choose at least one working day.']);
        }
        ksort($days);

        return ['tz' => $tz, 'days' => $days];
    }
}
