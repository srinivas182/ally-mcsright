<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Access\Roles;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * Removes expired role grants (temporary access) for every brokerage, recording each removal.
 * Expired grants are already ignored by the permission engine; this tidies them up.
 */
final class ExpireAccess extends Command
{
    protected $signature = 'core:expire-access';

    protected $description = 'Remove expired temporary access';

    public function handle(TenantContext $tenants): int
    {
        $total = 0;
        foreach (Tenant::query()->where('status', 'active')->get() as $tenant) {
            $total += $tenants->runAs($tenant, fn (): int => app(Roles::class)->expire());
        }
        $this->info("{$total} expired grant(s) removed.");

        return self::SUCCESS;
    }
}
