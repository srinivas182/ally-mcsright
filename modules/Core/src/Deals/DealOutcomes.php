<?php

declare(strict_types=1);

namespace Modules\Core\Deals;

/**
 * What came of the people a module tracks (Lead-IQ return on spend, grow/07). Implemented by the Transactions module
 * so the CRM never reads deal or commission tables directly; without it, reports show no deals.
 */
interface DealOutcomes
{
    /**
     * @param  list<int>  $contactIds
     * @return list<array{contact_id: int, status: string, created_at: string, closed_on: ?string, gci_cents: int, company_dollar_cents: int}>
     */
    public function forContacts(array $contactIds): array;
}
