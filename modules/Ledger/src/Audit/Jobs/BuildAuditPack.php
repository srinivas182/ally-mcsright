<?php

declare(strict_types=1);

namespace Modules\Ledger\Audit\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\Audit\AuditPack;

final class BuildAuditPack implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public readonly int $tenantId, public readonly string $ulid)
    {
        $this->afterCommit();
    }

    public function handle(TenantContext $tenants, AuditPack $packs): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant !== null) {
            $tenants->runAs($tenant, fn () => $packs->build($this->ulid));
        }
    }
}
