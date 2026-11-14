<?php

declare(strict_types=1);

namespace Modules\Crm\Leads;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\CrmAccess;
use Modules\Crm\Events\ContactCreated;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\RoutingRule;

/**
 * Who gets each lead (specs/grow/04): an existing contact's agent, else the first matching rule (agent, round
 * robin, first to claim or pond), else the fallback. Unanswered leads move on after the response window, which
 * only counts between 8am and 9pm brokerage time.
 */
final class LeadRouting
{
    public const DEFAULTS = ['respond_minutes' => 5, 'max_passes' => 2, 'fallback' => 'broker', 'fallback_user_id' => null];

    public function __construct(private readonly TenantContext $tenants, private readonly Timeline $timeline) {}

    /**
     * @return array{respond_minutes: int, max_passes: int, fallback: string, fallback_user_id: int|null}
     */
    public function settings(): array
    {
        $row = DB::connection(Connections::cell())->table('crm_lead_settings')->where('tenant_id', $this->tenants->id())->first();

        return $row === null ? self::DEFAULTS : ['respond_minutes' => (int) $row->respond_minutes, 'max_passes' => (int) $row->max_passes,
            'fallback' => (string) $row->fallback, 'fallback_user_id' => $row->fallback_user_id !== null ? (int) $row->fallback_user_id : null];
    }

    /**
     * @param  array<string, mixed>  $in
     */
    public function saveSettings(array $in, User $by): void
    {
        self::guard($by);
        $minutes = (int) ($in['respond_minutes'] ?? 5);
        $passes = (int) ($in['max_passes'] ?? 2);
        $fallback = (string) ($in['fallback'] ?? 'broker');
        $userId = is_numeric($in['fallback_user_id'] ?? null) ? (int) $in['fallback_user_id'] : null;
        $errors = [];
        if ($minutes < 1 || $minutes > 240) {
            $errors['respond_minutes'] = 'Give agents between 1 and 240 minutes.';
        }
        if ($passes < 0 || $passes > 10) {
            $errors['max_passes'] = 'Pass a lead on at most 10 times.';
        }
        if (! in_array($fallback, ['broker', 'pond'], true)) {
            $errors['fallback'] = 'Choose the broker or the pond.';
        }
        if ($userId !== null && ! $this->eligible([$userId], false)->contains('id', $userId)) {
            $errors['fallback_user_id'] = 'Choose an active owner, staff member or agent.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        DB::connection(Connections::cell())->table('crm_lead_settings')->updateOrInsert(['tenant_id' => $this->tenants->id()],
            ['respond_minutes' => $minutes, 'max_passes' => $passes, 'fallback' => $fallback, 'fallback_user_id' => $userId, 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('crm.leads.settings_saved', metadata: ['respond_minutes' => $minutes, 'max_passes' => $passes, 'fallback' => $fallback], severity: 'notice');
    }

    /**
     * @param  array<string, mixed>  $in
     */
    public function saveRule(array $in, User $by, ?RoutingRule $rule = null): RoutingRule
    {
        self::guard($by);
        $method = (string) ($in['method'] ?? '');
        $agents = array_values(array_unique(array_map('intval', (array) ($in['agent_ids'] ?? []))));
        $list = static fn ($v): ?array => ($l = array_values(array_filter(array_map(static fn ($x): string => trim((string) $x), is_array($v) ? $v : (preg_split('/[\s,]+/', (string) $v) ?: []))))) !== [] ? $l : null;
        $zips = $list($in['zips'] ?? null);
        $sources = $list($in['sources'] ?? null);
        $errors = [];
        if (trim((string) ($in['name'] ?? '')) === '') {
            $errors['name'] = 'Name the rule.';
        }
        if (! in_array($method, RoutingRule::METHODS, true)) {
            $errors['method'] = 'Choose how leads are given out.';
        }
        if ($method !== 'pond' && ($agents === [] || $this->eligible($agents, false)->count() !== count($agents))) {
            $errors['agent_ids'] = 'Choose active agents.';
        }
        if ($method === 'agent' && count($agents) !== 1) {
            $errors['agent_ids'] = 'Choose one agent.';
        }
        if ($zips !== null && array_filter($zips, static fn (string $z): bool => preg_match('/^\d{5}$/', $z) !== 1) !== []) {
            $errors['zips'] = 'ZIP codes are 5 digits.';
        }
        $min = is_numeric($in['price_min'] ?? null) ? (int) $in['price_min'] : null;
        $max = is_numeric($in['price_max'] ?? null) ? (int) $in['price_max'] : null;
        if ($min !== null && $max !== null && $min > $max) {
            $errors['price_max'] = 'The highest price is below the lowest.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $rule ??= new RoutingRule(['position' => (int) RoutingRule::query()->max('position') + 1]);
        $rule->fill(['name' => mb_substr(trim((string) $in['name']), 0, 80), 'method' => $method, 'agent_ids' => $method === 'pond' ? [] : $agents,
            'zips' => $zips, 'sources' => $sources, 'price_min' => $min, 'price_max' => $max,
            'intent' => in_array($in['intent'] ?? 'any', RoutingRule::INTENTS, true) ? (string) ($in['intent'] ?? 'any') : 'any', 'active' => (bool) ($in['active'] ?? true)])->save();
        app(AuditLogger::class)->record('crm.leads.rule_saved', $rule, metadata: ['method' => $method]);

        return $rule;
    }

    /**
     * @param  list<string>  $ulids
     */
    public function reorder(array $ulids, User $by): void
    {
        self::guard($by);
        foreach ($ulids as $i => $ulid) {
            RoutingRule::query()->where('ulid', $ulid)->update(['position' => $i + 1]);
        }
    }

    public function route(Lead $lead): void
    {
        $contact = $lead->contact;
        $owner = $contact?->owner_user_id !== null ? $this->eligible([(int) $contact->owner_user_id], false)->first() : null;
        if ($owner !== null) {
            $this->assign($lead, $owner, 'their agent');

            return;
        }

        foreach (RoutingRule::query()->where('active', true)->orderBy('position')->orderBy('id')->get() as $rule) {
            if (! self::matches($rule, $lead)) {
                continue;
            }
            $lead->forceFill(['rule_id' => $rule->id])->save();
            match ($rule->method) {
                'pond' => $this->pond($lead, "rule \"{$rule->name}\""),
                'first_claim' => $this->offer($lead, $rule),
                default => ($agent = $this->next($rule, [])) !== null ? $this->assign($lead, $agent, "rule \"{$rule->name}\"") : $this->fallback($lead),
            };

            return;
        }
        $this->fallback($lead);
    }

    public static function matches(RoutingRule $rule, Lead $lead): bool
    {
        if ($rule->sources !== null && ! in_array($lead->source, $rule->sources, true) && ! in_array($lead->channel, $rule->sources, true)) {
            return false;
        }
        if ($rule->zips !== null && ! in_array((string) $lead->zip, $rule->zips, true)) {
            return false;
        }
        if ($rule->intent !== 'any' && $lead->intent !== $rule->intent) {
            return false;
        }
        $price = $lead->price_max ?? $lead->price_min;
        if (($rule->price_min !== null || $rule->price_max !== null) && $price === null) {
            return false;
        }

        return ! (($rule->price_min !== null && $price < $rule->price_min) || ($rule->price_max !== null && $price > $rule->price_max));
    }

    public function assign(Lead $lead, User $agent, string $why): void
    {
        $now = CarbonImmutable::now();
        $lead->forceFill(['assigned_user_id' => $agent->id, 'assigned_at' => $now, 'status' => 'assigned', 'offered_to' => null])->save();
        $contact = $lead->contact;
        if ($contact !== null && $contact->owner_user_id !== $agent->id) {
            $first = $contact->owner_user_id === null && $lead->new_contact && $lead->passes === 0;
            $contact->forceFill(['owner_user_id' => $agent->id, 'owner_assigned_at' => $now, 'in_pond_since' => null])->save();
            if ($first) {
                ContactCreated::dispatch($contact->tenant_id, $contact->id, $agent->id); // follow-up plans by source
            }
        }
        if ($contact !== null) {
            $this->timeline->system($contact, "Lead given to {$agent->name} ({$why})");
        }
        app(SpeedToLead::class)->alert($lead, [$agent]);
    }

    public function offer(Lead $lead, RoutingRule $rule): void
    {
        $agents = $this->eligible($rule->agent_ids, true);
        if ($agents->isEmpty()) {
            $this->fallback($lead);

            return;
        }
        $lead->forceFill(['status' => 'offered', 'assigned_user_id' => null, 'assigned_at' => CarbonImmutable::now(), 'offered_to' => $agents->pluck('id')->all()])->save();
        if ($lead->contact !== null) {
            $this->timeline->system($lead->contact, 'Lead offered to '.$agents->count().' agents: first to claim gets it');
        }
        app(SpeedToLead::class)->alert($lead, $agents->all());
    }

    public function claim(Lead $lead, User $agent): void
    {
        if ($lead->status !== 'offered' || ! in_array($agent->id, $lead->offered_to ?? [], true)) {
            throw ValidationException::withMessages(['lead' => 'This lead is not offered to you.']);
        }
        $won = Lead::query()->whereKey($lead->id)->where('status', 'offered')->whereNull('assigned_user_id')->update(['assigned_user_id' => $agent->id]);
        if ($won === 0) {
            throw ValidationException::withMessages(['lead' => 'Someone claimed this lead first.']);
        }
        $this->assign($lead->refresh(), $agent, 'claimed first');
    }

    public function pond(Lead $lead, string $why): void
    {
        $lead->forceFill(['status' => 'pond', 'assigned_user_id' => null, 'offered_to' => null])->save();
        $contact = $lead->contact;
        if ($contact !== null && $contact->origin->value === 'company') {
            $contact->forceFill(['owner_user_id' => null, 'in_pond_since' => CarbonImmutable::now()])->save();
            $this->timeline->system($contact, "Lead sent to the lead pond ({$why})");
        }
    }

    public function fallback(Lead $lead): void
    {
        $s = $this->settings();
        if ($s['fallback'] === 'pond') {
            $this->pond($lead, 'no agent took it');

            return;
        }
        $broker = $s['fallback_user_id'] !== null ? $this->eligible([$s['fallback_user_id']], false)->first() : null;
        $broker ??= User::query()->where('kind', UserKind::Owner)->where('status', 'active')->orderBy('id')->first();
        if ($broker === null) {
            $this->pond($lead, 'no broker to give it to');

            return;
        }
        if ($lead->assigned_user_id !== $broker->id || $lead->status !== 'assigned') {
            $this->assign($lead, $broker, 'fallback');
        }
        // The broker is the end of the line: stop the response clock so it is not moved again.
        $lead->forceFill(['assigned_at' => null])->save();
    }

    /**
     * Moves leads nobody answered in time (every minute). Returns how many moved.
     */
    public function sweep(): int
    {
        $s = $this->settings();
        $tenant = $this->tenants->current();
        $tz = (string) ($tenant->getAttribute('timezone') ?: 'America/Chicago');
        $local = CarbonImmutable::now()->setTimezone($tz);
        if ($local->hour < 8 || $local->hour >= 21) {
            return 0; // the clock pauses overnight
        }
        $dayStart = $local->setTime(8, 0)->utc();
        $moved = 0;
        foreach (Lead::query()->whereIn('status', Lead::OPEN)->whereNull('responded_at')->whereNotNull('assigned_at')->with('contact')->get() as $lead) {
            $from = $lead->assigned_at !== null && $lead->assigned_at->gt($dayStart) ? $lead->assigned_at : $dayStart;
            if ($from->diffInMinutes(CarbonImmutable::now()) < $s['respond_minutes']) {
                continue;
            }
            $moved += app(AuditLogger::class)->asAutomation('lead_routing', $lead->assigned_user_id, "Move leads not answered within {$s['respond_minutes']} minutes", fn (): int => $this->pass($lead, $s));
        }

        return $moved;
    }

    /**
     * @param  array{respond_minutes: int, max_passes: int, fallback: string, fallback_user_id: int|null}  $s
     */
    private function pass(Lead $lead, array $s): int
    {
        $previous = $lead->assigned_user_id;
        $passed = array_values(array_unique(array_merge($lead->passed_over ?? [], $previous !== null ? [$previous] : [])));
        $lead->forceFill(['passed_over' => $passed, 'passes' => $lead->passes + 1])->save();
        $rule = $lead->rule_id !== null ? RoutingRule::query()->find($lead->rule_id) : null;

        if ($lead->status === 'assigned' && $previous !== null) {
            if ($lead->contact !== null) {
                $this->timeline->system($lead->contact, "No response within {$s['respond_minutes']} minutes; lead moved on");
            }
            $old = User::query()->find($previous);
            if ($old !== null) {
                app(SpeedToLead::class)->movedOn($lead, $old);
            }
        }

        $next = $rule !== null && $rule->method === 'round_robin' && $lead->passes <= $s['max_passes'] ? $this->next($rule, $passed) : null;
        if ($next !== null) {
            $this->assign($lead, $next, "rule \"{$rule->name}\", passed on");
        } else {
            $this->fallback($lead);
        }

        return 1;
    }

    /**
     * Next agent in the rule's rotation, skipping agents who are away, inactive or already passed over.
     *
     * @param  list<int>  $skip
     */
    private function next(RoutingRule $rule, array $skip): ?User
    {
        $ids = $rule->agent_ids;
        $eligible = $this->eligible($ids, true)->keyBy('id');
        $count = count($ids);
        for ($i = 0; $i < $count; $i++) {
            $cursor = (int) RoutingRule::query()->whereKey($rule->id)->value('cursor');
            RoutingRule::query()->whereKey($rule->id)->update(['cursor' => $cursor + 1]);
            $id = $ids[$cursor % $count];
            if ($eligible->has($id) && ! in_array($id, $skip, true)) {
                return $eligible->get($id);
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    public function eligible(array $ids, bool $skipAway): Collection
    {
        $away = $skipAway ? DB::connection(Connections::cell())->table('crm_lead_alert_prefs')->where('tenant_id', $this->tenants->id())
            ->where('away_until', '>', now())->pluck('user_id')->map(fn ($v): int => (int) $v)->all() : [];

        return User::query()->whereIn('id', $ids)->where('status', 'active')->whereIn('kind', [UserKind::Agent, UserKind::Owner, UserKind::Staff])
            ->whereNotIn('id', $away)->get();
    }

    private static function guard(User $by): void
    {
        if (! CrmAccess::manager($by)) {
            throw ValidationException::withMessages(['leads' => 'Only owners and staff can change lead routing.']);
        }
    }
}
