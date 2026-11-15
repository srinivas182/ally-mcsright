<?php

declare(strict_types=1);

namespace Modules\Connect\Console;

use Illuminate\Console\Command;
use Modules\Connect\PaidApps;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/** Hourly: renews paid-app add-ons whose month is over, ends cancelled ones, and pauses unpaid ones (specs/connect/14). */
final class RenewAddons extends Command
{
    protected $signature = 'connect:renew-addons';

    protected $description = 'Renew, end or pause paid-app add-ons';

    public function handle(TenantContext $tenants, AppCatalog $catalog): int
    {
        $total = ['renewed' => 0, 'ended' => 0, 'lapsed' => 0];
        foreach (Tenant::query()->where('status', 'active')->get() as $tenant) {
            $r = $tenants->runAs($tenant, fn (): array => app(PaidApps::class)->renewDue(fn (string $key) => $catalog->find($key)));
            foreach ($r as $k => $v) {
                $total[$k] += $v;
            }
        }
        $this->info("{$total['renewed']} renewed, {$total['ended']} ended, {$total['lapsed']} paused for payment.");

        return self::SUCCESS;
    }
}
