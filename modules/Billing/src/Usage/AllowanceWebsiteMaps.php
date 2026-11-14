<?php

declare(strict_types=1);

namespace Modules\Billing\Usage;

use Modules\Core\Tenancy\TenantContext;
use Modules\Core\Usage\WebsiteMaps;

/** Website map views against the brokerage's monthly allowance (specs/billing/03). */
final class AllowanceWebsiteMaps implements WebsiteMaps
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function open(): bool
    {
        if (! $this->tenants->check() || $this->tenants->current()->isHq()) {
            return true;
        }

        return app(UsageMeter::class)->openMap();
    }
}
