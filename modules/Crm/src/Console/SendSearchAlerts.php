<?php

declare(strict_types=1);

namespace Modules\Crm\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Searches\SavedSearches;

/** Every 15 minutes: saved-search alerts that are due (instant, daily or weekly at 8am brokerage time). */
#[CrossTenantAccess('Send saved-search alerts for every brokerage')]
final class SendSearchAlerts extends Command
{
    protected $signature = 'crm:search-alerts';

    protected $description = 'Send due saved-search alerts';

    public function handle(TenantContext $tenants): int
    {
        $n = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $n += $tenants->runAs($tenant, fn (): int => app(SavedSearches::class)->sendDue());
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("{$n} alert(s) sent.");

        return self::SUCCESS;
    }
}
