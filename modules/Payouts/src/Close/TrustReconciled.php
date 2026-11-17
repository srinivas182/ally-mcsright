<?php

declare(strict_types=1);

namespace Modules\Payouts\Close;

use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Ledger\Close\CloseCheck;
use Modules\Ledger\Close\Finding;
use Modules\Ledger\Models\LedgerPeriod;

/** A brokerage that holds money in trust has reconciled and approved the trust account for the month. */
final class TrustReconciled implements CloseCheck
{
    public function key(): string
    {
        return 'trust_reconciled';
    }

    public function label(): string
    {
        return 'The trust account is reconciled and approved';
    }

    public function run(LedgerPeriod $period): array
    {
        $db = DB::connection(Connections::cell());
        if (! $db->table('tx_deposits')->where('tenant_id', $period->tenant_id)->where('held_by', 'trust')->exists()) {
            return [];
        }
        $month = $period->starts_on->format('Y-m');
        if ($db->table('trust_statements')->where('tenant_id', $period->tenant_id)->where('month', $month)->where('status', 'approved')->exists()) {
            return [];
        }

        return [Finding::check('The trust account isn\'t reconciled for '.$period->starts_on->format('F Y'),
            'Most states require a monthly three-way reconciliation, approved by a second person. Reconcile it, or write why it is OK to close without it.', $month, '/finance/trust')];
    }
}
