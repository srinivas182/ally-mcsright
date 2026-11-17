<?php

declare(strict_types=1);

namespace Modules\Payouts\Close;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Ledger\Close\CloseCheck;
use Modules\Ledger\Close\Finding;
use Modules\Ledger\Models\LedgerPeriod;

/**
 * Every payout run sent this month is in the books, for exactly what the run paid. 1099 totals come from
 * the same run lines, so this is also what ties the 1099s to the books.
 */
final class PayoutRunsMatch implements CloseCheck
{
    public function key(): string
    {
        return 'payout_runs_match';
    }

    public function label(): string
    {
        return 'Every payout run is in the books for what it paid (this is what 1099s are built from)';
    }

    public function run(LedgerPeriod $period): array
    {
        $db = DB::connection(Connections::cell());
        $out = [];
        $runs = $db->table('pay_runs')->where('tenant_id', $period->tenant_id)->where('status', 'sent')
            ->whereBetween('pay_on', [$period->starts_on->toDateString(), $period->ends_on->toDateString()])->get(['id', 'ulid', 'pay_on', 'journal_id']);
        foreach ($runs as $run) {
            $label = 'The payout run of '.(string) $run->pay_on;
            if ($run->journal_id === null) {
                $out[] = Finding::stop("{$label} isn't in the books", 'It was sent but never recorded. Contact RightAlly support.', 'n'.$run->ulid, '/finance/payouts');

                continue;
            }
            $paid = (int) $db->table('pay_run_lines')->where('run_id', $run->id)->where('status', 'included')
                ->selectRaw('coalesce(sum(commission_cents + revenue_share_cents + referral_cents), 0) as t')->value('t');
            $booked = (int) $db->table('ledger_lines')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_lines.account_id')
                ->where('ledger_lines.journal_id', (int) $run->journal_id)
                ->where(fn ($q) => $q->where('ledger_accounts.code', 'like', '2000-%')->orWhere('ledger_accounts.code', 'like', '2100-%')->orWhere('ledger_accounts.code', 'like', '2200-%'))
                ->selectRaw('coalesce(sum(ledger_lines.debit_cents - ledger_lines.credit_cents), 0) as t')->value('t');
            if ($paid !== $booked) {
                $out[] = Finding::stop("{$label} doesn't match the books",
                    'The run paid $'.number_format($paid / 100, 2).' but the books show $'.number_format($booked / 100, 2).'. 1099 totals would be wrong. Contact RightAlly support.', 'm'.$run->ulid, '/finance/payouts');
            }
        }

        return $out;
    }
}
