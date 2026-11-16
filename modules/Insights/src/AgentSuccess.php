<?php

declare(strict_types=1);

namespace Modules\Insights;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Compensation\Insights as CompInsights;
use Modules\Crm\Leads\LeadReports;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Task;
use Modules\Crm\SmartLists;
use Modules\Transactions\Deals;
use Modules\Transactions\Models\Deal;

/**
 * Each agent's success dashboard (specs/insights/01, K1): their year against their goal and last year, cap progress,
 * speed to lead, follow-up habits, the deals on their way, and "this week": the few things that move the year.
 */
final class AgentSuccess
{
    public function __construct(private readonly Numbers $numbers) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $agent, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $ytd = $this->numbers->totals($this->numbers->closed([$agent->id], $today->startOfYear(), $today)->get());
        $last = $this->numbers->totals($this->numbers->closed([$agent->id], $today->subYear()->startOfYear(), $today->subYear())->get());
        $goal = Goals::for($agent, (int) $today->format('Y'));
        $share = round($today->dayOfYear / ($today->isLeapYear() ? 366 : 365), 3);

        $plan = app(CompInsights::class)->myPlan($agent);
        $speed = collect(app(LeadReports::class)->scorecard($today->subDays(90), $today)['agents'])->firstWhere('id', $agent->id);
        $overdue = Task::query()->where('assigned_to', $agent->id)->whereNull('completed_at')->where('due_at', '<', CarbonImmutable::now())->count();
        $due = app(SmartLists::class)->apply(Contact::query()->where('owner_user_id', $agent->id), ['rhythm_due' => true])->count();
        $waiting = Lead::query()->where('assigned_user_id', $agent->id)->whereNull('responded_at')->whereNotIn('status', ['needs_review', 'closed', 'lost'])->count();
        $pending = Deal::query()->where('agent_user_id', $agent->id)->whereIn('status', ['pre_contract', 'under_contract'])->orderBy('closing_date')->get();

        $week = [];
        if ($waiting > 0) {
            $week[] = ['text' => "{$waiting} new ".($waiting === 1 ? 'lead is' : 'leads are').' waiting for your first reply', 'href' => '/leads', 'urgent' => true];
        }
        foreach ($pending->filter(fn (Deal $d): bool => $d->status === 'under_contract' && $d->closing_date !== null && $d->closing_date->lte($today->addDays(7))) as $d) {
            $missing = count(app(Deals::class)->missing($d));
            $week[] = ['text' => 'Closing '.$d->closing_date?->format('l').': '.$d->street.($missing > 0 ? " ({$missing} still needed)" : ''), 'href' => '/deals/'.$d->ulid, 'urgent' => $missing > 0];
        }
        if ($overdue > 0) {
            $week[] = ['text' => "{$overdue} overdue ".($overdue === 1 ? 'task' : 'tasks'), 'href' => '/tasks', 'urgent' => false];
        }
        if ($due > 0) {
            $week[] = ['text' => "{$due} ".($due === 1 ? 'person is' : 'people are').' due a check-in', 'href' => '/contacts?list=keep-in-touch', 'urgent' => false];
        }
        if ($goal['sides'] !== null && $ytd['sides'] < (int) floor($goal['sides'] * $share)) {
            $behind = (int) floor($goal['sides'] * $share) - $ytd['sides'];
            $week[] = ['text' => "You are {$behind} ".($behind === 1 ? 'closing' : 'closings').' behind your goal pace: plan two more appointments this week', 'href' => '/contacts', 'urgent' => false];
        }

        return [
            'agent' => ['ulid' => $agent->ulid, 'name' => $agent->name],
            'year' => (int) $today->format('Y'),
            'ytd' => $ytd,
            'last_year' => $last,
            'goal' => $goal + ['year_share' => $share],
            'cap' => ($plan['plan'] ?? null) === null ? null : ['plan' => $plan['plan'], 'cap_cents' => $plan['cap_cents'], 'paid_cents' => $plan['paid_cents'],
                'to_cap_cents' => $plan['to_cap_cents'], 'capped' => $plan['capped']],
            'speed' => $speed === null ? null : ['leads' => $speed['leads'], 'median_minutes' => $speed['median_minutes'], 'within_target' => $speed['within_target']],
            'habits' => ['overdue_tasks' => $overdue, 'check_ins_due' => $due, 'leads_waiting' => $waiting],
            'pipeline' => $pending->map(fn (Deal $d): array => ['ulid' => $d->ulid, 'street' => (string) $d->street, 'status' => $d->status,
                'closing_date' => $d->closing_date?->toDateString(), 'price_cents' => $d->price_cents, 'gci_cents' => $d->gci_cents])->values()->all(),
            'week' => $week,
        ];
    }
}
