<?php

declare(strict_types=1);

namespace Modules\Roster\Verification;

/** Looks a licence up in state records (ARELLO once the data agreement and its API specification are in place). */
interface LicenceVerifier
{
    public function configured(): bool;

    /** How the check was made, for the credential's record ("arello", "state_data"). */
    public function method(): string;

    /**
     * @return array{status: string, expires_on: string|null, last_name: string|null} status: active, expired, suspended, inactive, not_found, unknown (no records for that state)
     */
    public function lookup(string $state, string $number, string $lastName): array;
}
