<?php

declare(strict_types=1);

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\BrokeragePayments;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * Monthly: each brokerage's subsidies and sponsored plans go on its next invoice (once per month).
 */
final class BillSubsidies extends Command
{
    protected $signature = 'billing:bill-subsidies';

    protected $description = 'Add agent subsidies and sponsorships to brokerages\' invoices';

    public function handle(TenantContext $tenants): int
    {
        $total = 0;
        foreach (Tenant::query()->where('status', 'active')->get() as $tenant) {
            $total += $tenants->runAs($tenant, fn (): int => app(BrokeragePayments::class)->billSubsidies());
        }
        $this->info('Subsidies billed: $'.number_format($total / 100, 2));

        return self::SUCCESS;
    }
}
