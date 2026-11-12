<?php

declare(strict_types=1);

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\AgentPayments;
use Modules\Billing\BrokeragePayments;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * Daily: plans unpaid after the 7-day grace period end; the brokerage's no-plan level applies.
 */
final class EndGrace extends Command
{
    protected $signature = 'billing:end-grace';

    protected $description = 'End plans whose payment grace period has passed';

    public function handle(TenantContext $tenants): int
    {
        $total = 0;
        foreach (Tenant::query()->where('status', 'active')->get() as $tenant) {
            $total += $tenants->runAs($tenant, fn (): int => app(AgentPayments::class)->endExpiredGrace());
        }
        $total += app(BrokeragePayments::class)->endExpiredGrace();
        $this->info("{$total} plan(s) ended after the grace period.");

        return self::SUCCESS;
    }
}
