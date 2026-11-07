<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A tenant was suspended by RightAlly HQ.
 */
final class TenantSuspended
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $reason,
        public readonly ?int $byUserId = null,
    ) {}
}
