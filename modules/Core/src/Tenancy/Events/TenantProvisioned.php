<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A tenant finished provisioning. Modules listen to seed their defaults (ledger chart of accounts now; roles, settings, workflow template and owner invitation as those specs ship).
 */
final class TenantProvisioned
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $ownerEmail,
        public readonly string $ownerName,
        public readonly ?int $byUserId = null,
    ) {}
}
