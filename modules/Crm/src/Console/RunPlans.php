<?php

declare(strict_types=1);

namespace Modules\Crm\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\FollowUpPlans;
use Modules\Crm\Plans\Reactions;

/**
 * Every 15 minutes: create the follow-up plan tasks whose day has come, for every brokerage, and move people who went
 * quiet after a plan to the monthly plan (SO2).
 */
final class RunPlans extends Command
{
    protected $signature = 'crm:run-plans';

    protected $description = 'Create due follow-up plan tasks';

    public function handle(TenantContext $tenants, FollowUpPlans $plans): int
    {
        $processed = 0;
        $moved = 0;
        foreach (Tenant::query()->where('status', 'active')->get() as $tenant) {
            $processed += $tenants->runAs($tenant, fn (): int => $plans->processDue());
            $moved += $tenants->runAs($tenant, fn (): int => app(Reactions::class)->keepGoing());
        }
        $this->info("{$processed} plan enrolment(s) processed, {$moved} quiet contact(s) moved to the monthly plan.");

        return self::SUCCESS;
    }
}
