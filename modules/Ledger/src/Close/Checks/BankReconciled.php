<?php

declare(strict_types=1);

namespace Modules\Ledger\Close\Checks;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Ledger\Close\CloseCheck;
use Modules\Ledger\Close\Finding;
use Modules\Ledger\Models\LedgerPeriod;

/** Once a brokerage reconciles its operating bank account, every later month must be reconciled too. */
final class BankReconciled implements CloseCheck
{
    public function key(): string
    {
        return 'bank_reconciled';
    }

    public function label(): string
    {
        return 'The operating bank account is reconciled';
    }

    public function run(LedgerPeriod $period): array
    {
        $month = $period->starts_on->format('Y-m');
        $db = DB::connection(Connections::cell())->table('acct_bank_statements')->where('tenant_id', $period->tenant_id);
        if (! $db->clone()->where('month', '<=', $month)->exists()) {
            return []; // this brokerage hasn't started reconciling here
        }
        if ($db->clone()->where('month', $month)->where('status', 'approved')->exists()) {
            return [];
        }
        $label = $period->starts_on->format('F Y');

        return [Finding::check("The bank account isn't reconciled for {$label}",
            'Upload the bank statement, match each line and have it approved, or write why it is OK to close without it.', $month, '/finance/bank?month='.$month)];
    }
}
