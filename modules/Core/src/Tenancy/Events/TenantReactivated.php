<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A suspended or closing tenant was reactivated.
 */
final class TenantReactivated
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly ?int $byUserId = null,
    ) {}
}
