<?php

declare(strict_types=1);

namespace Modules\Core\Deals;

/** Default when no deals module is installed. */
final class NoDealOutcomes implements DealOutcomes
{
    public function forContacts(array $contactIds): array
    {
        return [];
    }
}
