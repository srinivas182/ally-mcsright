<?php

declare(strict_types=1);

namespace Modules\Recruiting\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Recruiting\AgentDatabase;

/** Daily: rebuild each brokerage's MLS agent database and raise recruiting alerts (RC1). */
final class RefreshAgents extends Command
{
    protected $signature = 'recruiting:refresh';

    protected $description = 'Rebuild agent production from licensed MLS data and raise recruiting alerts';

    public function handle(TenantContext $tenants): int
    {
        $agents = 0;
        foreach (Tenant::query()->where('status', 'active')->get() as $tenant) {
            $agents += $tenants->runAs($tenant, fn (): int => app(AgentDatabase::class)->refresh());
        }
        $this->info("{$agents} agent(s) refreshed.");

        return self::SUCCESS;
    }
}
