<?php

declare(strict_types=1);

namespace Modules\Payouts\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Payouts\AgentBilling;

/** Daily: agents' recurring fee invoices, reminders and late fees for every brokerage. */
final class AgentBillingDaily extends Command
{
    protected $signature = 'payouts:agent-billing';

    protected $description = 'Issue due agent fee invoices, send reminders and apply late fees';

    public function handle(TenantContext $tenants): int
    {
        $totals = ['issued' => 0, 'reminded' => 0, 'late' => 0];
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            foreach ($tenants->runAs($tenant, fn (): array => app(AgentBilling::class)->daily()) as $k => $v) {
                $totals[$k] += $v;
            }
        }
        $this->info("Invoices issued {$totals['issued']}, reminders {$totals['reminded']}, late fees {$totals['late']}.");

        return self::SUCCESS;
    }
}
