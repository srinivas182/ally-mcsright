<?php

declare(strict_types=1);

namespace Modules\Forms\Identity;

/** For tests: returns whatever record is set. */
final class FakeOwnerRecords implements OwnerRecords
{
    /** @var array{owners: list<string>, mailing_state: ?string, vacant_land: bool, owner_occupied: ?bool, estimated_value_cents: ?int, source: string}|null */
    public ?array $record = null;

    public function lookup(string $street, ?string $city, string $state, ?string $postalCode): ?array
    {
        return $this->record;
    }
}
