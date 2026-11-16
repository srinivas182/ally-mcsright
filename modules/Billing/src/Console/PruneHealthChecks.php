<?php

declare(strict_types=1);

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\HealthCheck\HealthChecks;

/** Daily: deletes Health Check reports (and their logos) once they are 180 days old (specs/billing/06). */
final class PruneHealthChecks extends Command
{
    protected $signature = 'billing:prune-health-checks';

    protected $description = 'Delete Brokerage Health Check reports older than 180 days';

    public function handle(HealthChecks $checks): int
    {
        $this->info($checks->prune().' report(s) deleted.');

        return self::SUCCESS;
    }
}
