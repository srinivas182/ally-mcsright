<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A tenant finished its closing period; data is kept until purge_after.
 */
final class TenantClosed
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly ?int $byUserId = null,
    ) {}
}
