<?php

declare(strict_types=1);

namespace Modules\Sites\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Ai\MarketUpdates;

/** Monthly market update drafts for every brokerage that turned them on (specs/websites/10). */
#[CrossTenantAccess('Writes each brokerage\'s own market update drafts inside its own tenant context')]
final class WriteMarketUpdates extends Command
{
    protected $signature = 'sites:market-updates';

    protected $description = 'Draft this month\'s market update posts for brokerages that turned them on';

    public function handle(TenantContext $tenants): int
    {
        $n = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $n += $tenants->runAs($tenant, fn (): int => app(MarketUpdates::class)->run());
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("Drafted {$n} market updates.");

        return self::SUCCESS;
    }
}
