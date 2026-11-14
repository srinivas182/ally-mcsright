<?php

declare(strict_types=1);

namespace Modules\Billing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Billing\Credits\AutoTopUp;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/** Automatic top-up after credits were spent (specs/billing/05), off the request that spent them. */
final class TopUpCredits implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $tenantId, public readonly ?int $userId) {}

    public function uniqueId(): string
    {
        return $this->tenantId.':'.($this->userId ?? 'pool');
    }

    public function handle(TenantContext $tenants): void
    {
        $tenant = Tenant::query()->whereKey($this->tenantId)->first();
        if ($tenant !== null) {
            $tenants->runAs($tenant, fn () => app(AutoTopUp::class)->check($this->userId));
        }
    }
}
