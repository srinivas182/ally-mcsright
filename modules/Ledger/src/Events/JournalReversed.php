<?php

declare(strict_types=1);

namespace Modules\Ledger\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class JournalReversed
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $originalUlid,
        public readonly string $reversalUlid,
        public readonly string $reason,
    ) {}
}
