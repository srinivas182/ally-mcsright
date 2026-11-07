<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A tenant changed its address; the old platform host redirects for 90 days.
 */
final class TenantSlugChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $oldSlug,
        public readonly string $newSlug,
        public readonly ?int $byUserId = null,
    ) {}
}
