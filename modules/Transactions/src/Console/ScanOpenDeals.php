<?php

declare(strict_types=1);

namespace Modules\Transactions\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Compliance\ComplianceScan;
use Modules\Transactions\Models\Deal;

/** Daily: re-checks every open deal, so time-based risks (dates approaching or missed) stay current. */
#[CrossTenantAccess('Re-check open deals for every brokerage')]
final class ScanOpenDeals extends Command
{
    protected $signature = 'transactions:scan-open';

    protected $description = 'Re-check every open deal (Deal-IQ compliance and at-risk)';

    public function handle(TenantContext $tenants): int
    {
        $count = 0;
        foreach (Tenant::query()->where('status', 'active')->cursor() as $tenant) {
            $tenants->runAs($tenant, function () use (&$count): void {
                foreach (Deal::query()->whereIn('status', ['pre_contract', 'under_contract'])->cursor() as $deal) {
                    app(ComplianceScan::class)->run($deal);
                    $count++;
                }
            });
        }
        $this->info("Checked {$count} deal(s).");

        return self::SUCCESS;
    }
}
