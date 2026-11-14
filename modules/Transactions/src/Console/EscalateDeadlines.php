<?php

declare(strict_types=1);

namespace Modules\Transactions\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Deadlines\Deadlines;

/** Hourly: texts, automated calls and broker alerts for key dates and blockers near closing (Deal-IQ). */
#[CrossTenantAccess('Escalate deal deadlines for every brokerage')]
final class EscalateDeadlines extends Command
{
    protected $signature = 'transactions:escalate';

    protected $description = 'Escalate deal deadlines: texts, calls and broker alerts';

    public function handle(TenantContext $tenants): int
    {
        $total = ['texts' => 0, 'calls' => 0, 'broker' => 0];
        foreach (Tenant::query()->where('status', 'active')->cursor() as $tenant) {
            $tenants->runAs($tenant, function () use (&$total): void {
                foreach (app(Deadlines::class)->escalate() as $k => $n) {
                    $total[$k] += $n;
                }
            });
        }
        $this->info("Sent {$total['texts']} text(s), placed {$total['calls']} call(s), alerted brokers {$total['broker']} time(s).");

        return self::SUCCESS;
    }
}
