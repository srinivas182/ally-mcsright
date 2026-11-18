<?php

declare(strict_types=1);

namespace Modules\Core\Launch;

use Illuminate\Console\Command;

/** Sprint 63: `php artisan launch:check`: is this server set up for real customers? Fails when any check fails. */
final class LaunchCheck extends Command
{
    protected $signature = 'launch:check';

    protected $description = 'Check this server is set up for real customers (production settings, databases, HQ, scheduler)';

    public function handle(LaunchReadiness $readiness): int
    {
        $checks = $readiness->checks();
        foreach ($checks as $c) {
            $this->line(($c['ok'] ? '<fg=green>✓</> ' : '<fg=red>✗</> ').$c['check'].': '.$c['detail']);
        }
        $ready = LaunchReadiness::ready($checks);
        $this->line('');
        $ready ? $this->info('Ready for customers.') : $this->error('Not ready: fix the items marked ✗.');

        return $ready ? self::SUCCESS : self::FAILURE;
    }
}
