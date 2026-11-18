<?php

declare(strict_types=1);

namespace Modules\Outreach\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Outreach\MarketReports;

/** MK3 (D09): sends due monthly market report emails for every brokerage. */
final class SendMarketReports extends Command
{
    protected $signature = 'outreach:market-reports';

    protected $description = 'Send due monthly market report emails';

    public function handle(TenantContext $tenants): int
    {
        $n = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $n += $tenants->runAs($tenant, fn (): int => app(MarketReports::class)->sendDue());
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("{$n} market report(s) sent.");

        return self::SUCCESS;
    }
}
