<?php

declare(strict_types=1);

namespace Modules\Crm\Leads;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Deals\DealOutcomes;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\CrmAccess;
use Modules\Crm\Enums\Source;
use Modules\Crm\Models\AiThread;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Lead;

/**
 * Lead reports (specs/grow/07): return on spend by source and channel down to commission and company dollar, and
 * the broker's speed scorecard by agent and source. A deal counts for the source of the contact's first lead in
 * the period (first touch).
 */
final class LeadReports
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return array{target_minutes: int, target_percent: int}
     */
    public function targets(): array
    {
        $r = DB::connection(Connections::cell())->table('crm_lead_settings')->where('tenant_id', $this->tenants->id())->first();

        return ['target_minutes' => (int) ($r->target_minutes ?? 5), 'target_percent' => (int) ($r->target_percent ?? 80)];
    }

    public function saveTargets(int $minutes, int $percent, User $by): void
    {
        if (! CrmAccess::manager($by)) {
            throw ValidationException::withMessages(['targets' => 'Only owners and staff can set targets.']);
        }
        if ($minutes < 1 || $minutes > 240 || $percent < 1 || $percent > 100) {
            throw ValidationException::withMessages(['targets' => 'Use 1–240 minutes and 1–100%.']);
        }
        $db = DB::connection(Connections::cell());
        $exists = $db->table('crm_lead_settings')->where('tenant_id', $this->tenants->id())->exists();
        $exists ? $db->table('crm_lead_settings')->where('tenant_id', $this->tenants->id())->update(['target_minutes' => $minutes, 'target_percent' => $percent, 'updated_at' => now()])
            : $db->table('crm_lead_settings')->insert(['tenant_id' => $this->tenants->id(), 'target_minutes' => $minutes, 'target_percent' => $percent, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function saveSpend(string $month, string $key, int $cents, User $by): void
    {
        if (! CrmAccess::manager($by)) {
            throw ValidationException::withMessages(['spend' => 'Only owners and staff can enter spend.']);
        }
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            throw ValidationException::withMessages(['month' => 'Choose a month.']);
        }
        $valid = array_merge(array_map(fn (Source $s): string => $s->value, Source::cases()), array_map(fn (string $c): string => 'channel:'.$c, Attribution::CHANNELS));
        if (! in_array($key, $valid, true)) {
            throw ValidationException::withMessages(['key' => 'Choose a source or channel.']);
        }
        if ($cents < 0 || $cents > 100_000_000_00) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount spent.']);
        }
        DB::connection(Connections::cell())->table('crm_lead_spend')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'month' => $month.'-01', 'key' => $key],
            ['amount_cents' => $cents, 'entered_by' => $by->id, 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('crm.leads.spend_saved', metadata: ['month' => $month, 'key' => $key, 'cents' => $cents]);
    }

    /**
     * @return array{sources: list<array<string, mixed>>, channels: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function returns(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $leads = Lead::query()->where('status', '!=', 'needs_review')->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])->orderBy('id')
            ->get(['id', 'contact_id', 'source', 'channel', 'status', 'responded_at', 'response_seconds', 'engaged_at', 'score_label', 'created_at']);
        $first = $leads->whereNotNull('contact_id')->unique('contact_id')->keyBy('contact_id'); // first touch per contact
        $contactIds = array_values(array_map('intval', $first->keys()->all()));
        $deals = collect(app(DealOutcomes::class)->forContacts($contactIds))->filter(function (array $d) use ($first, $from): bool {
            $lead = $first->get($d['contact_id']);

            return CarbonImmutable::parse($d['created_at'])->gte($lead instanceof Lead ? $lead->created_at : $from);
        });
        $spend = DB::connection(Connections::cell())->table('crm_lead_spend')->where('tenant_id', $this->tenants->id())
            ->whereBetween('month', [$from->startOfMonth()->toDateString(), $to->toDateString()])->get()->groupBy('key')->map(fn (Collection $r): int => (int) $r->sum('amount_cents'));

        $build = function (string $field, Collection $groups, string $prefix) use ($first, $deals, $spend): array {
            $rows = [];
            foreach ($groups as $key => $group) {
                $contacts = $first->filter(fn (Lead $l): bool => $l->{$field} === $key)->keys()->all();
                $d = $deals->filter(fn (array $x): bool => in_array($x['contact_id'], $contacts, true));
                $closed = $d->where('status', 'closed');
                $cost = (int) ($spend->get($prefix.$key) ?? 0);
                $company = (int) $closed->sum('company_dollar_cents');
                $rows[] = ['key' => (string) $key, 'leads' => $group->count(), 'contacted' => $group->whereNotNull('responded_at')->count(),
                    'engaged' => $group->whereNotNull('engaged_at')->count(), 'hot' => $group->where('score_label', 'hot')->count(),
                    'deals' => $d->count(), 'closed' => $closed->count(), 'gci_cents' => (int) $closed->sum('gci_cents'), 'company_dollar_cents' => $company,
                    'spend_cents' => $cost, 'cost_per_lead_cents' => $cost > 0 && $group->count() > 0 ? intdiv($cost, $group->count()) : null,
                    'cost_per_closing_cents' => $cost > 0 && $closed->count() > 0 ? intdiv($cost, $closed->count()) : null,
                    'return' => $cost > 0 ? round($company / $cost, 2) : null];
            }
            usort($rows, fn (array $a, array $b): int => [$b['company_dollar_cents'], $b['leads']] <=> [$a['company_dollar_cents'], $a['leads']]);

            return $rows;
        };

        $sources = $build('source', $leads->groupBy('source'), '');
        $channels = $build('channel', $leads->groupBy('channel'), 'channel:');
        $totalSpend = (int) $spend->sum();
        $closed = $deals->where('status', 'closed');

        return ['sources' => $sources, 'channels' => $channels, 'totals' => ['leads' => $leads->count(), 'closed' => $closed->count(),
            'gci_cents' => (int) $closed->sum('gci_cents'), 'company_dollar_cents' => (int) $closed->sum('company_dollar_cents'), 'spend_cents' => $totalSpend,
            'return' => $totalSpend > 0 ? round($closed->sum('company_dollar_cents') / $totalSpend, 2) : null]];
    }

    /**
     * @return array{agents: list<array<string, mixed>>, sources: list<array<string, mixed>>, targets: array{target_minutes: int, target_percent: int}}
     */
    public function scorecard(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $t = $this->targets();
        $limit = $t['target_minutes'] * 60;
        $leads = Lead::query()->where('status', '!=', 'needs_review')->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->get(['id', 'source', 'assigned_user_id', 'responded_by', 'response_seconds', 'passed_over', 'status']);
        $waiting = Conversation::query()->whereNotNull('needs_reply_since')->get(['assigned_user_id', 'needs_reply_since', 'escalated_at']);
        $handoffs = AiThread::query()->where('status', 'handed_off')->whereNotIn('handoff_reason', ['agent_replied'])->whereBetween('handed_off_at', [$from->startOfDay(), $to->endOfDay()])->with('lead')->get();
        $agents = User::query()->where('status', 'active')->whereIn('kind', [UserKind::Agent, UserKind::Owner, UserKind::Staff])->orderBy('first_name')->get();

        $rows = [];
        foreach ($agents as $a) {
            $mine = $leads->filter(fn (Lead $l): bool => $l->assigned_user_id === $a->id || $l->responded_by === $a->id);
            $passed = $leads->filter(fn (Lead $l): bool => in_array($a->id, $l->passed_over ?? [], true))->count();
            if ($mine->isEmpty() && $passed === 0) {
                continue;
            }
            $answered = $mine->where('responded_by', $a->id)->whereNotNull('response_seconds');
            $within = $answered->filter(fn (Lead $l): bool => $l->response_seconds <= $limit)->count();
            $total = $mine->count() + $passed;
            $rows[] = ['id' => $a->id, 'name' => $a->name, 'leads' => $total, 'contacted' => $answered->count(),
                'median_minutes' => self::median($answered->pluck('response_seconds')->all()), 'within_target' => $total > 0 ? (int) round(100 * $within / $total) : null,
                'passed_over' => $passed, 'waiting_now' => $waiting->where('assigned_user_id', $a->id)->count(),
                'waiting_over_hour' => $waiting->where('assigned_user_id', $a->id)->whereNotNull('escalated_at')->count(),
                'handoffs_open' => $handoffs->filter(fn (AiThread $h): bool => $h->lead->assigned_user_id === $a->id && $h->lead->responded_at === null)->count()];
        }
        usort($rows, fn (array $x, array $y): int => [$y['within_target'] ?? -1, $y['leads']] <=> [$x['within_target'] ?? -1, $x['leads']]);

        $bySource = [];
        foreach ($leads->groupBy('source') as $source => $group) {
            $answered = $group->whereNotNull('response_seconds');
            $bySource[] = ['key' => (string) $source, 'leads' => $group->count(), 'median_minutes' => self::median($answered->pluck('response_seconds')->all()),
                'within_target' => (int) round(100 * $answered->filter(fn (Lead $l): bool => $l->response_seconds <= $limit)->count() / max(1, $group->count()))];
        }

        return ['agents' => $rows, 'sources' => $bySource, 'targets' => $t];
    }

    /**
     * @param  array<int, mixed>  $seconds
     */
    private static function median(array $seconds): ?float
    {
        $v = array_values(array_map('intval', array_filter($seconds, fn ($s): bool => $s !== null)));
        if ($v === []) {
            return null;
        }
        sort($v);
        $mid = intdiv(count($v), 2);

        return round((count($v) % 2 === 1 ? $v[$mid] : ($v[$mid - 1] + $v[$mid]) / 2) / 60, 1);
    }
}
