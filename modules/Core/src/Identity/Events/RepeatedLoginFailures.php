<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * 20 or more failed sign-ins for one email in a tenant within an hour (security alert, spec 04).
 * Carries a hash of the email, never the email itself.
 */
final class RepeatedLoginFailures
{
    use Dispatchable;

    public function __construct(public readonly int $tenantId, public readonly string $emailHash, public readonly int $failures) {}
}
