<?php

declare(strict_types=1);

namespace Modules\Payouts;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Compensation\Models\Calculation;
use Modules\Core\Support\Connections;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\LedgerService;
use Modules\Transactions\Models\Deal;

/**
 * An agent's money: commission payable from deals whose money has arrived (in the ledger), commission
 * still on its way (deals closed or under contract without matched money), and the revenue share wallet.
 */
final class Balances
{
    /**
     * @return array{commission_cents: int, revenue_share_cents: int, available_cents: int, pending_cents: int, pending_deals: int}
     */
    public function for(User $user): array
    {
        $accounts = app(ChartOfAccounts::class);
        $accounts->seed();
        $ledger = app(LedgerService::class);
        $commission = $ledger->balance($accounts->subAccount('2000', 'user', $user->id, 'Commission payable — '.$user->first_name))->cents;
        $revenueShare = $ledger->balance($accounts->subAccount('2100', 'user', $user->id, 'Revenue share — '.$user->first_name))->cents;

        $pending = 0;
        $deals = 0;
        $settled = DB::connection(Connections::cell())->table('pay_recognitions')->pluck('deal_id')->all();
        foreach (Deal::query()->whereIn('status', ['under_contract', 'closed'])->whereNotIn('id', $settled)->whereNotNull('calculation_id')->get() as $deal) {
            $calc = Calculation::query()->find($deal->calculation_id);
            foreach ((array) ($calc?->result['agents'] ?? []) as $row) {
                if ((int) $row['user_id'] === $user->id) {
                    $pending += (int) $row['agent_net_cents'];
                    $deals++;
                }
            }
        }

        return ['commission_cents' => $commission, 'revenue_share_cents' => $revenueShare, 'available_cents' => $commission + $revenueShare,
            'pending_cents' => $pending, 'pending_deals' => $deals];
    }
}
