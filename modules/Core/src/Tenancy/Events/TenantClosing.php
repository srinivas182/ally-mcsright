<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A tenant entered the 30-day closing period (reversible).
 */
final class TenantClosing
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $reason,
        public readonly ?int $byUserId = null,
    ) {}
}
