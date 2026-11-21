<?php

declare(strict_types=1);

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\Builder\AddOns;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Idx\BoardFees;

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
                // IDX7: MLS boards beyond the one the package includes.
                if (class_exists(BoardFees::class)) {
                    $total += $tenants->runAs($tenant, fn (): int => app(BoardFees::class)->bill());
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info('Extras billed: $'.number_format($total / 100, 2));

        return self::SUCCESS;
    }
}
