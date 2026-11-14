<?php

declare(strict_types=1);

namespace Modules\Forms\Identity;

/** Used until a property-data provider is connected: no owner record, so no ownership flags. */
final class NoOwnerRecords implements OwnerRecords
{
    public function lookup(string $street, ?string $city, string $state, ?string $postalCode): ?array
    {
        return null;
    }
}
