<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Audit\FlushAuditOutbox;

/**
 * Safety net: seal any audit events still waiting in the outbox (runs every minute).
 */
final class FlushAudit extends Command
{
    protected $signature = 'audit:flush';

    protected $description = 'Seal waiting audit events into the hash chain';

    public function handle(): int
    {
        (new FlushAuditOutbox)->handle();
        $this->info('Audit outbox flushed.');

        return self::SUCCESS;
    }
}
