<?php

declare(strict_types=1);

namespace Modules\Insights\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Insights\Anomalies;
use Modules\Insights\DatabaseValue;
use Modules\Insights\RoiReport;
use Modules\Insights\SavedReports;

/** Daily: scheduled reports that are due, the monthly ROI and database reports on the 1st, and anomaly alerts on Mondays (specs/insights/01). */
#[CrossTenantAccess('Send scheduled reports and anomaly alerts for every brokerage')]
final class InsightsDaily extends Command
{
    protected $signature = 'insights:daily';

    protected $description = 'Send scheduled reports and, on Mondays, anomaly alerts';

    public function handle(TenantContext $tenants): int
    {
        $reports = 0;
        $anomalies = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $tenants->runAs($tenant, function () use (&$reports, &$anomalies): void {
                    $reports += app(SavedReports::class)->sendDue() + app(RoiReport::class)->sendDue() + app(DatabaseValue::class)->sendDue();
                    if (now()->isMonday()) {
                        $anomalies += app(Anomalies::class)->alert();
                    }
                });
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("{$reports} report(s) and {$anomalies} alert(s) sent.");

        return self::SUCCESS;
    }
}
