<?php

declare(strict_types=1);

namespace Modules\Ledger\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\Audit\AuditPack;

/** Deletes audit packs older than 7 days (FH2, specs/finance/23). */
final class ExpireAuditPacks extends Command
{
    protected $signature = 'ledger:expire-audit-packs';

    protected $description = 'Delete expired audit packs';

    public function handle(TenantContext $tenants, AuditPack $packs): int
    {
        $n = 0;
        foreach (Tenant::query()->where('type', '!=', 'hq')->get() as $tenant) {
            $n += $tenants->runAs($tenant, fn (): int => $packs->expire());
        }
        $this->info("{$n} pack(s) deleted.");

        return self::SUCCESS;
    }
}
