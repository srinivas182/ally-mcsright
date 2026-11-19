<?php

declare(strict_types=1);

namespace Modules\Crm\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Practice\PracticeClients;

/** SX5, daily: remove made-up practice people older than two weeks, in every brokerage. */
final class CleanupPractice extends Command
{
    protected $signature = 'crm:practice-cleanup';

    protected $description = 'Remove practice people older than two weeks';

    public function handle(TenantContext $tenants, PracticeClients $practice): int
    {
        $removed = 0;
        foreach (Tenant::query()->get() as $tenant) {
            $removed += $tenants->runAs($tenant, fn (): int => $practice->cleanup());
        }
        $this->info("{$removed} practice people removed.");

        return self::SUCCESS;
    }
}
