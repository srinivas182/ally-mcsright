<?php

declare(strict_types=1);

namespace Modules\Crm\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Prospector\Prospector;

/** Daily: new expired and withdrawn listings become prospects for the brokerage's chosen agents (LQ2). */
final class FindProspects extends Command
{
    protected $signature = 'crm:prospector';

    protected $description = 'Find expired and withdrawn listings for the Prospector';

    public function handle(TenantContext $tenants): int
    {
        $found = 0;
        foreach (Tenant::query()->where('status', 'active')->get() as $tenant) {
            $found += $tenants->runAs($tenant, fn (): int => app(Prospector::class)->find());
        }
        $this->info("{$found} prospect(s) found.");

        return self::SUCCESS;
    }
}
