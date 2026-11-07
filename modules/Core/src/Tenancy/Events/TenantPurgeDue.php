<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A closed tenant reached its purge date. Each module purges its own data in response (built per module before any real purge runs).
 */
final class TenantPurgeDue
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly ?int $byUserId = null,
    ) {}
}
