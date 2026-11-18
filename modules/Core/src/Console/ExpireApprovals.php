<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Approvals\Approvals;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/** Closes approval requests nobody answered within 7 days (FL1, specs/core/16), for every brokerage. */
final class ExpireApprovals extends Command
{
    protected $signature = 'core:expire-approvals';

    protected $description = 'Close approval requests nobody answered within 7 days';

    public function handle(TenantContext $tenants): int
    {
        $total = 0;
        foreach (Tenant::query()->where('status', 'active')->get() as $tenant) {
            $total += $tenants->runAs($tenant, fn (): int => app(Approvals::class)->expire());
        }
        $this->info("{$total} request(s) closed.");

        return self::SUCCESS;
    }
}
