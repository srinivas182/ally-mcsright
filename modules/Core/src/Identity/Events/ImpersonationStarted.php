<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Audit: "log in as" (specs/core/02, 4A-3).
 */
final class ImpersonationStarted
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $byUserId,
        public readonly string $byKind,
        public readonly int $targetUserId,
        public readonly string $reason,
    ) {}
}
