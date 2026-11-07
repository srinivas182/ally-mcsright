<?php

declare(strict_types=1);

namespace Modules\Ledger\Listeners;

use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\Events\TenantProvisioned;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\ChartOfAccounts;

/**
 * Every new brokerage gets the system chart of accounts as soon as it is provisioned.
 */
final class SeedChartOfAccounts
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ChartOfAccounts $chart,
    ) {}

    public function handle(TenantProvisioned $event): void
    {
        $tenant = Tenant::query()->findOrFail($event->tenantId);

        if ($tenant->type !== TenantType::Brokerage) {
            return;
        }

        $this->context->runAs($tenant, fn () => $this->chart->seed());
    }
}
