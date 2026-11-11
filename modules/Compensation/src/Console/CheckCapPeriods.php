<?php

declare(strict_types=1);

namespace Modules\Compensation\Console;

use Illuminate\Console\Command;
use Modules\Compensation\CommissionService;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * Nightly: every agent's cap totals equal the sum of their cap entries, for every brokerage.
 */
final class CheckCapPeriods extends Command
{
    protected $signature = 'comp:check-caps';

    protected $description = 'Check that cap period totals equal the sum of their entries';

    public function handle(TenantContext $tenants, CommissionService $service): int
    {
        $problems = [];
        foreach (Tenant::query()->get() as $tenant) {
            array_push($problems, ...$tenants->runAs($tenant, fn (): array => $service->checkCapPeriods()));
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }
        $this->info(count($problems) === 0 ? 'All cap periods reconcile.' : count($problems).' cap period(s) do not reconcile.');

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
