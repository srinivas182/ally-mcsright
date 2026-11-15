<?php

declare(strict_types=1);

namespace Modules\Payouts;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Compensation\Insights;
use Modules\Compensation\Models\Calculation;
use Modules\Compensation\Models\CalculationAgent;
use Modules\Core\Support\Connections;
use Modules\Transactions\Models\Deal;

/**
 * The Agent Wallet (specs/finance/03): everything about an agent's money on one screen. Earned and paid this year
 * (the sum of their monthly statements, so the wallet always ties to the statements), what the brokerage owes them
 * now (the ledger), progress to cap, deals pending with expected pay, revenue share earned and pending, what get
 * paid now would give them, and what they owe.
 */
final class Wallet
{
    public function __construct(private readonly Statements $statements, private readonly Balances $balances) {}

    /** @return array<string, mixed> */
    public function for(User $agent): array
    {
        $now = CarbonImmutable::now();
        $months = [];
        $earned = 0;
        $paid = 0;
        for ($m = $now->startOfYear(); $m->lte($now); $m = $m->addMonth()) {
            $s = $this->statements->month($agent, $m->format('Y-m'));
            $earned += $s['earned'];
            $paid += $s['paid'];
            $months[] = ['month' => $s['month'], 'earned_cents' => $s['earned'], 'paid_cents' => $s['paid']];
        }
        $db = DB::connection(Connections::cell());
        $pendingCalcs = CalculationAgent::query()->where('agent_user_id', $agent->id)->where('status', 'pending')->pluck('calculation_id')->all();
        $rs = $db->table('rs_entries')->where('recipient_user_id', $agent->id)->whereIn('kind', ['share', 'root', 'bonus']);
        $deals = [];
        $open = [];
        $settled = $db->table('pay_recognitions')->pluck('deal_id')->map(fn ($v): int => (int) $v)->all(); // money received: in the balance now
        foreach (Calculation::query()->whereIn('id', $pendingCalcs)->get() as $calc) {
            foreach ((array) ($calc->result['agents'] ?? []) as $row) {
                if ((int) $row['user_id'] !== $agent->id) {
                    continue;
                }
                $deal = Deal::query()->where('calculation_id', $calc->id)->first();
                if ($deal !== null && (in_array($deal->id, $settled, true) || $deal->status === 'cancelled')) {
                    continue;
                }
                $open[] = $calc->id;
                $deals[] = ['ulid' => $deal?->ulid, 'street' => $deal !== null ? trim($deal->street.', '.$deal->city, ', ') : 'A deal', 'status' => $deal?->status,
                    'closing_date' => $deal?->closing_date?->toDateString(), 'expected_cents' => (int) $row['agent_net_cents'], 'paid_outside' => $deal !== null && $deal->agent_paid_outside];
            }
        }
        usort($deals, fn (array $a, array $b): int => strcmp((string) $a['closing_date'], (string) $b['closing_date']));
        $plan = app(Insights::class)->myPlan($agent);

        return [
            'year' => (int) $now->format('Y'),
            'earned_ytd_cents' => $earned,
            'paid_ytd_cents' => $paid,
            'months' => array_reverse($months),
            'now' => $this->balances->for($agent),
            'cap' => $plan['plan'] === null ? null : ['plan' => $plan['plan'], 'cap_cents' => $plan['cap_cents'], 'paid_cents' => $plan['paid_cents'],
                'to_cap_cents' => $plan['to_cap_cents'], 'capped' => $plan['capped'], 'forecast' => $plan['forecast'], 'period' => $plan['period']],
            'pending_deals' => $deals,
            'revenue_share' => [
                'earned_ytd_cents' => (int) (clone $rs)->whereBetween('closing_date', [$now->startOfYear()->toDateString(), $now->toDateString()])->sum('amount_cents'),
                'pending_cents' => $open === [] ? 0 : (int) (clone $rs)->whereIn('calculation_id', $open)->sum('amount_cents'),
            ],
            'get_paid_now' => app(GetPaidNow::class)->quote($agent),
            'owed_cents' => app(AgentBilling::class)->owed($agent->id),
            'as_of' => $now->toIso8601String(),
        ];
    }
}
