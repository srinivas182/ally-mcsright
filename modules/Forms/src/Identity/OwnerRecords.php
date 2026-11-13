<?php

declare(strict_types=1);

namespace Modules\Forms\Identity;

/** Owner of record and basic facts for a property, from a property-data provider. */
interface OwnerRecords
{
    /**
     * @return array{owners: list<string>, mailing_state: ?string, vacant_land: bool, owner_occupied: ?bool, estimated_value_cents: ?int, source: string}|null
     */
    public function lookup(string $street, ?string $city, string $state, ?string $postalCode): ?array;
}
