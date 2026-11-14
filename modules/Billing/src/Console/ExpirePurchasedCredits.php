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

/** Daily: ends whatever is left of credit packs bought 12 months ago (specs/billing/04). */
#[CrossTenantAccess('Expires bought credits for every brokerage once their 12 months are up')]
final class ExpirePurchasedCredits extends Command
{
    protected $signature = 'credits:expire-purchases';

    protected $description = 'End credits left from packs bought more than 12 months ago';

    public function handle(TenantContext $tenants): int
    {
        $due = DB::connection(Connections::cell())->table('credit_purchases')->where('status', 'paid')->whereNull('expired_at')->where('expires_at', '<=', now())
            ->orderBy('id')->limit(5000)->get(['tenant_id', 'ulid']);
        $expired = 0;
        foreach ($due->groupBy('tenant_id') as $tenantId => $rows) {
            $tenant = Tenant::query()->whereKey((int) $tenantId)->first();
            if ($tenant === null) {
                continue;
            }
            $tenants->runAs($tenant, function () use ($rows, &$expired): void {
                foreach ($rows as $row) {
                    $expired += app(CreditLedger::class)->expirePurchase((string) $row->ulid);
                }
            });
        }
        $this->info("{$due->count()} pack(s) checked, {$expired} credit(s) expired.");

        return self::SUCCESS;
    }
}
