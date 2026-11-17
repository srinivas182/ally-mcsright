<?php

declare(strict_types=1);

namespace Modules\Recruiting;

use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Plan;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Recruiting\Models\MlsAgent;
use Modules\Recruiting\Models\Recruit;

/**
 * The MLS agent database (RC1, specs/grow/17): every agent in the MLSs the brokerage is licensed to use for agent
 * production (the `agent_production` licence scope), with closed sides and volume for the last 12 months and the 12
 * before, their current office, and when they last changed office. Worked out from closed listings; nothing is bought.
 */
final class AgentDatabase
{
    public const SCOPE = 'agent_production';

    /** A production jump worth an alert: at least this many more sides, and half as many again. */
    public const JUMP_SIDES = 3;

    public function __construct(private readonly TenantContext $tenants) {}

    /** @return list<int> MLS sources this brokerage may use for agent production */
    public function sources(): array
    {
        return app(MlsAccess::class)->licensedSources($this->tenants->id(), self::SCOPE);
    }

    /**
     * @return array{own_office_ids: list<string>, commission_rate: float, compare_plan_id: int|null}
     */
    public function settings(): array
    {
        $row = DB::connection(Connections::cell())->table('rec_settings')->where('tenant_id', $this->tenants->id())->first();
        $own = $row !== null && $row->own_office_ids !== null ? array_values(array_map('strval', (array) json_decode((string) $row->own_office_ids, true))) : null;
        // Until the broker says otherwise: the offices named on the brokerage's MLS licences.
        $own ??= array_values(array_unique(MlsAuthorization::query()->where('tenant_id', $this->tenants->id())->where('status', 'active')->get()
            ->flatMap(fn (MlsAuthorization $a): array => $a->office_mls_ids ?? [])->map(fn ($id): string => (string) $id)->all()));

        return ['own_office_ids' => $own, 'commission_rate' => $row !== null ? (float) $row->commission_rate : 0.025,
            'compare_plan_id' => $row !== null && $row->compare_plan_id !== null ? (int) $row->compare_plan_id : null];
    }

    /**
     * @param  array<string, mixed>  $in
     */
    public function saveSettings(array $in): void
    {
        preg_match_all('/[A-Za-z0-9_-]{1,30}/', (string) ($in['own_office_ids'] ?? ''), $m);
        $rate = (float) ($in['commission_rate'] ?? 2.5) / 100;
        if ($rate < 0.005 || $rate > 0.1) {
            throw ValidationException::withMessages(['commission_rate' => 'Between 0.5% and 10%.']);
        }
        $plan = isset($in['compare_plan_id']) && $in['compare_plan_id'] !== ''
            ? Plan::query()->where('type', PlanType::Commission)->find((int) $in['compare_plan_id']) : null;
        DB::connection(Connections::cell())->table('rec_settings')->updateOrInsert(['tenant_id' => $this->tenants->id()], [
            'own_office_ids' => json_encode(array_values(array_unique($m[0]))), 'commission_rate' => round($rate, 4), 'compare_plan_id' => $plan?->id,
            'updated_at' => now(), 'created_at' => now()]);
        MlsAgent::query()->update(['ours' => false]);
        if ($m[0] !== []) {
            MlsAgent::query()->whereIn('office_mls_id', $m[0])->update(['ours' => true]);
        }
        app(AuditLogger::class)->record('recruiting.settings', metadata: ['offices' => count(array_unique($m[0])), 'rate' => $rate, 'plan' => $plan?->id]);
    }

    /**
     * Daily: rebuild production from closed listings, note office changes, and raise alerts on recruits.
     *
     * @return int agents refreshed
     */
    public function refresh(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $sources = $this->sources();
        if ($sources === []) {
            return 0;
        }
        $own = $this->settings()['own_office_ids'];
        $now = CarbonImmutable::now();
        $count = 0;
        foreach ($sources as $source) {
            $agents = $this->aggregate($source, $today);
            $existing = MlsAgent::query()->where('mls_source_id', $source)->get()->keyBy('agent_mls_id');
            foreach ($agents as $id => $a) {
                $row = $existing->get($id);
                $moved = $row !== null && $row->office_mls_id !== null && $a['office_mls_id'] !== null && $row->office_mls_id !== $a['office_mls_id'];
                $values = ['name' => $a['name'] ?? $row?->name, 'office_mls_id' => $a['office_mls_id'] ?? $row?->office_mls_id, 'office_name' => $a['office_name'] ?? $row?->office_name,
                    'sides' => $a['sides'], 'list_sides' => $a['list_sides'], 'buyer_sides' => $a['buyer_sides'], 'volume_cents' => $a['volume_cents'],
                    'sides_before' => $a['sides_before'], 'volume_before_cents' => $a['volume_before_cents'], 'active_listings' => $a['active_listings'],
                    'last_closed_on' => $a['last_closed_on'], 'ours' => in_array((string) ($a['office_mls_id'] ?? $row?->office_mls_id), $own, true), 'refreshed_at' => $now];
                if ($moved) {
                    $values += ['previous_office_mls_id' => $row->office_mls_id, 'previous_office_name' => $row->office_name, 'office_changed_on' => $today->toDateString()];
                }
                if ($row === null) {
                    $row = MlsAgent::query()->create(['mls_source_id' => $source, 'agent_mls_id' => $id] + $values);
                } else {
                    $row->fill($values)->save();
                }
                $count++;
                if ($moved || $this->jumped($row)) {
                    app(Pipeline::class)->alertFor($row, $moved ? 'moved' : 'production');
                }
            }
            // Agents with nothing in two years drop out, unless someone is recruiting them.
            MlsAgent::query()->where('mls_source_id', $source)->where('refreshed_at', '<', $now)
                ->whereNotIn('id', Recruit::query()->whereNotNull('mls_agent_id')->select('mls_agent_id'))->delete();
        }

        return $count;
    }

    public function jumped(MlsAgent $a): bool
    {
        return $a->sides - $a->sides_before >= self::JUMP_SIDES && $a->sides * 2 >= $a->sides_before * 3;
    }

    /**
     * Agents outside the brokerage, for the database page.
     *
     * @param  array<string, mixed>  $f  q, min_sides, moved (days), sort (sides|volume|trend|moved)
     * @return LengthAwarePaginator<int, MlsAgent>
     */
    public function search(array $f): LengthAwarePaginator
    {
        $q = trim((string) ($f['q'] ?? ''));
        $sort = in_array($f['sort'] ?? '', ['sides', 'volume', 'trend', 'moved'], true) ? $f['sort'] : 'sides';
        $moved = (int) ($f['moved'] ?? 0);

        return MlsAgent::query()->where('ours', false)->whereIn('mls_source_id', $this->sources() ?: [0])
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('office_name', 'like', "%{$q}%")->orWhere('agent_mls_id', $q)))
            ->when((int) ($f['min_sides'] ?? 0) > 0, fn ($w) => $w->where('sides', '>=', (int) $f['min_sides']))
            ->when($moved > 0, fn ($w) => $w->where('office_changed_on', '>=', CarbonImmutable::today()->subDays($moved)->toDateString()))
            ->when($sort === 'sides', fn ($w) => $w->orderByDesc('sides')->orderByDesc('volume_cents'))
            ->when($sort === 'volume', fn ($w) => $w->orderByDesc('volume_cents'))
            ->when($sort === 'trend', fn ($w) => $w->orderByRaw('(cast(sides as signed) - cast(sides_before as signed)) desc')->orderByDesc('sides'))
            ->when($sort === 'moved', fn ($w) => $w->whereNotNull('office_changed_on')->orderByDesc('office_changed_on'))
            ->orderBy('id')->paginate(50)->withQueryString();
    }

    /**
     * Closed sides in the last 24 months and the agent's latest office, from one MLS.
     *
     * @return array<string, array{name: string|null, office_mls_id: string|null, office_name: string|null, sides: int, list_sides: int, buyer_sides: int, volume_cents: int, sides_before: int, volume_before_cents: int, active_listings: int, last_closed_on: string|null}>
     */
    private function aggregate(int $source, CarbonImmutable $today): array
    {
        $yearAgo = $today->subYear()->toDateString();
        $agents = [];
        $latest = []; // agent => [date, office id]: the office on their most recent listing or buyer side
        $offices = []; // office id => name
        $blank = ['name' => null, 'office_mls_id' => null, 'office_name' => null, 'sides' => 0, 'list_sides' => 0, 'buyer_sides' => 0, 'volume_cents' => 0,
            'sides_before' => 0, 'volume_before_cents' => 0, 'active_listings' => 0, 'last_closed_on' => null];
        $rows = Listing::query()->where('mls_source_id', $source)
            ->where(fn ($w) => $w->where('close_date', '>=', $today->subYears(2)->toDateString())->orWhereIn('standard_status', ['Active', 'Active Under Contract', 'Pending']))
            ->toBase()->select(['standard_status', 'list_agent_mls_id', 'list_agent_name', 'co_list_agent_mls_id', 'buyer_agent_mls_id', 'list_office_mls_id', 'list_office_name',
                'buyer_office_mls_id', 'close_price_cents', 'list_price_cents', 'close_date', 'listing_contract_date', 'on_market_date'])->orderBy('id')->lazy(1000);
        foreach ($rows as $r) {
            $listAgent = $r->list_agent_mls_id !== null ? (string) $r->list_agent_mls_id : null;
            if ($r->list_office_mls_id !== null && $r->list_office_name !== null) {
                $offices[(string) $r->list_office_mls_id] = (string) $r->list_office_name;
            }
            $when = (string) ($r->listing_contract_date ?? $r->on_market_date ?? $r->close_date ?? '');
            if ($listAgent !== null) {
                $agents[$listAgent] ??= $blank;
                $agents[$listAgent]['name'] = $r->list_agent_name !== null ? (string) $r->list_agent_name : $agents[$listAgent]['name'];
                if ($r->list_office_mls_id !== null && $when >= ($latest[$listAgent][0] ?? '')) {
                    $latest[$listAgent] = [$when, (string) $r->list_office_mls_id];
                }
                if ($r->standard_status !== 'Closed') {
                    $agents[$listAgent]['active_listings']++;
                }
            }
            if ($r->standard_status !== 'Closed' || $r->close_date === null) {
                continue;
            }
            $closed = (string) $r->close_date;
            $price = (int) ($r->close_price_cents ?? $r->list_price_cents ?? 0);
            $recent = $closed >= $yearAgo;
            $sides = array_filter([
                [$listAgent, 'list_sides'],
                [$r->co_list_agent_mls_id !== null ? (string) $r->co_list_agent_mls_id : null, 'list_sides'],
                [$r->buyer_agent_mls_id !== null ? (string) $r->buyer_agent_mls_id : null, 'buyer_sides'],
            ], fn (array $s): bool => $s[0] !== null);
            foreach ($sides as [$id, $kind]) {
                $agents[$id] ??= $blank;
                if ($recent) {
                    $agents[$id]['sides']++;
                    $agents[$id][$kind]++;
                    $agents[$id]['volume_cents'] += $price;
                } else {
                    $agents[$id]['sides_before']++;
                    $agents[$id]['volume_before_cents'] += $price;
                }
                $agents[$id]['last_closed_on'] = max($agents[$id]['last_closed_on'] ?? '', $closed);
                if ($kind === 'buyer_sides' && $r->buyer_office_mls_id !== null && $closed > ($latest[$id][0] ?? '')) {
                    $latest[$id] = [$closed, (string) $r->buyer_office_mls_id];
                }
            }
        }
        foreach ($latest as $id => $l) {
            if (! isset($agents[$id])) {
                continue;
            }
            $agents[$id]['office_mls_id'] = $l[1];
            $agents[$id]['office_name'] = $offices[$l[1]] ?? null;
        }

        return $agents;
    }
}
