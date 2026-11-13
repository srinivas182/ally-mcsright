<?php

declare(strict_types=1);

namespace Modules\Roster\Verification;

/** Test double: answers from a table keyed by "STATE:number". */
final class FakeVerifier implements LicenceVerifier
{
    /**
     * @param  array<string, array{status: string, expires_on: string|null, last_name: string|null}>  $records
     */
    public function __construct(public array $records = []) {}

    public function configured(): bool
    {
        return true;
    }

    public function lookup(string $state, string $number, string $lastName): array
    {
        return $this->records[$state.':'.$number] ?? ['status' => 'not_found', 'expires_on' => null, 'last_name' => null];
    }
}
