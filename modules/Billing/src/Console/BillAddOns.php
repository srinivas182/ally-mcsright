<?php

declare(strict_types=1);

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\Builder\AddOns;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/** PB2, on the 1st: add each brokerage's extras for the month to its invoice. */
final class BillAddOns extends Command
{
    protected $signature = 'billing:bill-addons';

    protected $description = 'Add this month\'s extras to each brokerage\'s invoice';

    public function handle(TenantContext $tenants): int
    {
        $total = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $total += $tenants->runAs($tenant, fn (): int => app(AddOns::class)->bill());
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info('Extras billed: $'.number_format($total / 100, 2));

        return self::SUCCESS;
    }
}
