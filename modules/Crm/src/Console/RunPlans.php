<?php

declare(strict_types=1);

namespace Modules\Crm\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\FollowUpPlans;

/**
 * Every 15 minutes: create the follow-up plan tasks whose day has come, for every brokerage.
 */
final class RunPlans extends Command
{
    protected $signature = 'crm:run-plans';

    protected $description = 'Create due follow-up plan tasks';

    public function handle(TenantContext $tenants, FollowUpPlans $plans): int
    {
        $processed = 0;
        foreach (Tenant::query()->where('status', 'active')->get() as $tenant) {
            $processed += $tenants->runAs($tenant, fn (): int => $plans->processDue());
        }
        $this->info("{$processed} plan enrolment(s) processed.");

        return self::SUCCESS;
    }
}
