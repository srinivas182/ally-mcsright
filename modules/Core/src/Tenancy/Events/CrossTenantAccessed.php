<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tenant scoping was lifted for an HQ purpose. Recorded by the audit log (Sprint 7).
 */
final class CrossTenantAccessed
{
    use Dispatchable;

    public function __construct(
        public readonly string $callerClass,
        public readonly string $modelClass,
        public readonly string $reason,
    ) {}
}
