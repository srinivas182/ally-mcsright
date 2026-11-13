<?php

declare(strict_types=1);

namespace Modules\Roster\Verification;

use RuntimeException;

/**
 * The default until the ARELLO agreement is signed: automatic checks are off and staff verify by hand.
 * The ARELLO adapter replaces this binding once its API specification arrives with the agreement.
 */
final class NotConfiguredVerifier implements LicenceVerifier
{
    public function configured(): bool
    {
        return false;
    }

    public function lookup(string $state, string $number, string $lastName): array
    {
        throw new RuntimeException('Licence verification is not configured.');
    }
}
