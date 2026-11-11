<?php

declare(strict_types=1);

namespace Modules\Crm\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\LeadPond;

/**
 * Hourly: return uncontacted company leads to the pond, for brokerages that use it.
 */
final class SweepPonds extends Command
{
    protected $signature = 'crm:ponds';

    protected $description = 'Move uncontacted company leads to the lead pond';

    public function handle(TenantContext $tenants): int
    {
        $moved = 0;
        foreach (Tenant::query()->where('status', 'active')->where('crm_pond_enabled', true)->get() as $tenant) {
            $moved += $tenants->runAs($tenant, fn (): int => app(LeadPond::class)->sweep());
        }
        $this->info("{$moved} lead(s) moved to ponds.");

        return self::SUCCESS;
    }
}
