<?php

declare(strict_types=1);

namespace Modules\Transactions\Reports;

use Illuminate\Support\Facades\DB;
use Modules\Core\Deals\DealOutcomes;
use Modules\Core\Support\Connections;

/**
 * Deals for the given contacts, with commission (GCI) and the brokerage's company dollar from the final
 * commission calculation (cap entries), for lead return-on-spend reporting.
 */
final class ContactDealOutcomes implements DealOutcomes
{
    public function forContacts(array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }
        $db = DB::connection(Connections::cell());
        $out = [];
        foreach (array_chunk($contactIds, 500) as $chunk) {
            $deals = $db->table('tx_deals')->whereIn('contact_id', $chunk)->where('status', '!=', 'fell_through')
                ->get(['id', 'contact_id', 'status', 'created_at', 'closed_on', 'gci_cents', 'calculation_id']);
            $company = $db->table('comp_cap_entries')->whereIn('calculation_id', $deals->pluck('calculation_id')->filter()->all())
                ->groupBy('calculation_id')->selectRaw('calculation_id, sum(company_dollar_cents) as cents')->pluck('cents', 'calculation_id');
            foreach ($deals as $d) {
                $out[] = ['contact_id' => (int) $d->contact_id, 'status' => (string) $d->status, 'created_at' => (string) $d->created_at,
                    'closed_on' => $d->closed_on !== null ? (string) $d->closed_on : null, 'gci_cents' => (int) $d->gci_cents,
                    'company_dollar_cents' => $d->status === 'closed' && $d->calculation_id !== null ? (int) ($company[$d->calculation_id] ?? 0) : 0];
            }
        }

        return $out;
    }
}
