<?php

declare(strict_types=1);

namespace Modules\Crm\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Leads\LeadRouting;

/**
 * Every minute: leads nobody answered within the brokerage's response window move to the next agent, then the
 * fallback (specs/grow/04). The window only counts between 8am and 9pm brokerage time.
 */
#[CrossTenantAccess('Move unanswered leads on for every brokerage')]
final class ReassignLeads extends Command
{
    protected $signature = 'crm:leads-reassign';

    protected $description = 'Move leads that nobody answered in time to the next agent';

    public function handle(TenantContext $tenants): int
    {
        $moved = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $moved += $tenants->runAs($tenant, fn (): int => app(LeadRouting::class)->sweep());
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("{$moved} lead(s) moved on.");

        return self::SUCCESS;
    }
}
