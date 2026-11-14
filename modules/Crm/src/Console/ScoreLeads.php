<?php

declare(strict_types=1);

namespace Modules\Crm\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Leads\LeadScoring;

/** Nightly: rescore the last six months of leads (scores also change at once on replies and AI findings). */
#[CrossTenantAccess('Rescore leads for every brokerage')]
final class ScoreLeads extends Command
{
    protected $signature = 'crm:score-leads';

    protected $description = 'Rescore recent leads';

    public function handle(TenantContext $tenants): int
    {
        $n = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $n += $tenants->runAs($tenant, fn (): int => app(LeadScoring::class)->rescoreAll());
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("{$n} lead(s) scored.");

        return self::SUCCESS;
    }
}
