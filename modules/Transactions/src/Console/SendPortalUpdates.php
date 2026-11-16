<?php

declare(strict_types=1);

namespace Modules\Transactions\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Portal\Updates;

/** Hourly: milestone updates, the Monday summary and the post-closing check-in for clients in the deal portal. */
#[CrossTenantAccess('Send deal portal updates for every brokerage')]
final class SendPortalUpdates extends Command
{
    protected $signature = 'transactions:portal-updates';

    protected $description = 'Send deal portal updates to clients: milestones, the weekly summary and the check-in after closing';

    public function handle(TenantContext $tenants): int
    {
        $total = ['milestones' => 0, 'weekly' => 0, 'checkins' => 0, 'texts' => 0];
        foreach (Tenant::query()->where('status', 'active')->cursor() as $tenant) {
            $tenants->runAs($tenant, function () use (&$total): void {
                foreach (app(Updates::class)->run() as $k => $n) {
                    $total[$k] += $n;
                }
            });
        }
        $this->info("Sent {$total['milestones']} milestone update(s), {$total['weekly']} weekly summaries, {$total['checkins']} check-in(s) and {$total['texts']} text(s).");

        return self::SUCCESS;
    }
}
