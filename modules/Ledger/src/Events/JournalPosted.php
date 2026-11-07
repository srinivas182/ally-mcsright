<?php

declare(strict_types=1);

namespace Modules\Ledger\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class JournalPosted
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $journalUlid,
        public readonly string $journalType,
    ) {}
}
