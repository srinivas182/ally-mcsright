<?php

declare(strict_types=1);

namespace Modules\Transactions\Playbooks;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Models\Deal;

/**
 * The agent's Monday summary across all their open deals (specs/ai/02, §19): what is due this week, what is overdue,
 * who they are waiting on, emails ready to send, and MLS statuses to update. One email; nothing when there is nothing.
 */
final class AgentWeekly
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** Sends on Monday mornings (8am–noon, the brokerage's time zone), once a week. */
    public function run(): int
    {
        $tenant = $this->tenants->current();
        $now = CarbonImmutable::now($tenant->timezone !== '' ? $tenant->timezone : 'America/Chicago');
        if (! $now->isMonday() || $now->hour < 8 || $now->hour >= 12) {
            return 0;
        }
        $week = $now->format('o-\WW');
        $sent = 0;
        $agents = Deal::query()->whereIn('status', ['pre_contract', 'under_contract'])->distinct()->pluck('agent_user_id');
        foreach ($agents as $agentId) {
            $agent = User::query()->whereKey((int) $agentId)->first();
            if ($agent === null || ! $agent->isActive() || cache()->has("agent-weekly:{$tenant->id}:{$agent->id}:{$week}")) {
                continue;
            }
            $summary = $this->summary($agent, $now);
            if (array_sum(array_map('count', $summary)) === 0) {
                continue;
            }
            $agent->notify(new AgentWeeklyMail($summary));
            cache()->put("agent-weekly:{$tenant->id}:{$agent->id}:{$week}", true, now()->addDays(8));
            $sent++;
        }

        return $sent;
    }

    /**
     * @return array{due: list<string>, overdue: list<string>, waiting: list<string>, ready: list<string>, mls: list<string>}
     */
    public function summary(User $agent, CarbonImmutable $now): array
    {
        $db = DB::connection(Connections::cell());
        $deals = Deal::query()->where('agent_user_id', $agent->id)->whereIn('status', ['pre_contract', 'under_contract'])->get()->keyBy('id');
        $ids = $deals->keys()->all();
        $street = fn (int $id): string => (string) ($deals->get($id)->street ?? '');
        $today = $now->toDateString();
        $dates = $db->table('tx_dates')->whereIn('deal_id', $ids)->whereNull('done_at')->where('due_on', '<=', $now->addDays(7)->toDateString())->orderBy('due_on')->get();
        $out = ['due' => [], 'overdue' => [], 'waiting' => [], 'ready' => [], 'mls' => []];
        foreach ($dates as $d) {
            $line = $street((int) $d->deal_id).': '.$d->label.' ('.CarbonImmutable::parse((string) $d->due_on)->format('D M j').')';
            substr((string) $d->due_on, 0, 10) < $today ? $out['overdue'][] = $line : $out['due'][] = $line;
        }
        foreach ($db->table('tx_upload_requests')->whereIn('deal_id', $ids)->whereNull('used_at')->where('expires_at', '>', now())->get() as $r) {
            $out['waiting'][] = $street((int) $r->deal_id).': '.$r->what.' from '.$r->name;
        }
        foreach ($db->table('tx_portal_questions')->whereIn('deal_id', $ids)->where('status', 'to_agent')->get() as $q) {
            $out['waiting'][] = $street((int) $q->deal_id).': a client question needs your reply';
        }
        foreach ($db->table('tx_playbook_steps')->whereIn('deal_id', $ids)->where('status', 'ready')->get() as $s) {
            $out['ready'][] = $street((int) $s->deal_id).': '.str_replace('_', ' ', (string) $s->step).' email';
        }
        foreach ($db->table('tx_mls_checks')->whereIn('deal_id', $ids)->whereNull('resolved_at')->get() as $c) {
            $out['mls'][] = $street((int) $c->deal_id).': MLS should show '.$c->expected.' (shows '.$c->seen.')';
        }

        return $out;
    }
}
