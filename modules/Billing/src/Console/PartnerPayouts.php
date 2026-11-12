<?php

declare(strict_types=1);

namespace Modules\Billing\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Billing\PartnerEarnings;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * Monthly (2nd): last month's partner statement for every brokerage, then payouts (or carry forward).
 */
final class PartnerPayouts extends Command
{
    protected $signature = 'billing:partner-payouts {--month= : YYYY-MM (default: last month)}';

    protected $description = 'Create partner statements and pay brokerages';

    public function handle(TenantContext $tenants): int
    {
        $month = (string) ($this->option('month') ?? CarbonImmutable::now()->subMonthNoOverflow()->format('Y-m'));
        $paid = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            $result = $tenants->runAs($tenant, function () use ($tenant, $month): string {
                $earnings = app(PartnerEarnings::class);
                $earnings->statement($tenant, $month);

                return $earnings->payout($tenant->fresh() ?? $tenant, $month);
            });
            $paid += $result === 'paid' ? 1 : 0;
        }
        $this->info("Statements for {$month} created; {$paid} payout(s) sent.");

        return self::SUCCESS;
    }
}
