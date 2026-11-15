<?php

declare(strict_types=1);

namespace Modules\Sites\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Funnels\HomeReports;

/** Hourly: monthly home-value reports that are due (from 8am brokerage time). */
#[CrossTenantAccess('Send monthly home value reports for every brokerage')]
final class SendHomeReports extends Command
{
    protected $signature = 'sites:home-reports';

    protected $description = 'Send due monthly home value reports';

    public function handle(TenantContext $tenants): int
    {
        $n = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $n += $tenants->runAs($tenant, fn (): int => app(HomeReports::class)->sendDue());
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("{$n} report(s) sent.");

        return self::SUCCESS;
    }
}
