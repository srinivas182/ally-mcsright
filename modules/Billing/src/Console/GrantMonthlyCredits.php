<?php

declare(strict_types=1);

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Credits\CreditLedger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * Grants each brokerage the credits included in its package for the month (and expires last month's unused
 * included credits). Safe to run daily: each month is granted once.
 */
#[CrossTenantAccess('Grants each brokerage its own package credits inside its own tenant context')]
final class GrantMonthlyCredits extends Command
{
    protected $signature = 'credits:grant-monthly';

    protected $description = 'Grant this month\'s included RightAlly Credits to every brokerage';

    public function handle(TenantContext $tenants): int
    {
        $granted = 0;
        $control = DB::connection(Connections::control());
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            $credits = (int) ($control->table('billing_tenant_subscriptions')->join('billing_packages', 'billing_packages.id', '=', 'billing_tenant_subscriptions.package_id')
                ->where('billing_tenant_subscriptions.tenant_id', $tenant->id)->whereIn('billing_tenant_subscriptions.status', ['active', 'grace'])
                ->orderByDesc('billing_tenant_subscriptions.id')->value('billing_packages.included_credits') ?? 0);
            try {
                $granted += $tenants->runAs($tenant, fn (): bool => app(CreditLedger::class)->grantIncluded($credits)) ? 1 : 0;
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("Granted included credits to {$granted} brokerages.");

        return self::SUCCESS;
    }
}
