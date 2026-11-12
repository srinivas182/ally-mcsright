<?php

declare(strict_types=1);

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\Pricing;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * Daily: brokerage price increases reach existing agents once their 30-day notice has passed.
 */
final class ApplyPriceChanges extends Command
{
    protected $signature = 'billing:apply-price-changes';

    protected $description = 'Apply brokerage price increases after the 30-day notice';

    public function handle(TenantContext $tenants): int
    {
        $total = 0;
        foreach (Tenant::query()->where('status', 'active')->get() as $tenant) {
            $total += $tenants->runAs($tenant, fn (): int => app(Pricing::class)->applyDueIncreases());
        }
        $this->info("{$total} subscription(s) moved to their new price.");

        return self::SUCCESS;
    }
}
