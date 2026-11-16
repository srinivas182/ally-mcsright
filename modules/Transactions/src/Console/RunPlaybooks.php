<?php

declare(strict_types=1);

namespace Modules\Transactions\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Closing\Appointments;
use Modules\Transactions\Mls\MlsGuard;
use Modules\Transactions\Playbooks\AgentWeekly;
use Modules\Transactions\Playbooks\Playbooks;

/** Hourly: playbook emails that are due, the MLS status guard, and the agents' Monday summary. */
#[CrossTenantAccess('Run deal playbooks and the MLS status guard for every brokerage')]
final class RunPlaybooks extends Command
{
    protected $signature = 'transactions:playbooks';

    protected $description = 'Deal playbooks: emails due, MLS status guard, and the Monday summary for agents';

    public function handle(TenantContext $tenants): int
    {
        $t = ['vendor_reminders' => 0, 'missed' => 0, 'ready' => 0, 'sent' => 0, 'checked' => 0, 'reminded' => 0, 'escalated' => 0, 'resolved' => 0, 'weekly' => 0];
        foreach (Tenant::query()->where('status', 'active')->cursor() as $tenant) {
            $tenants->runAs($tenant, function () use (&$t): void {
                foreach ([...app(Playbooks::class)->run(), ...app(MlsGuard::class)->run()] as $k => $n) {
                    $t[$k] += $n;
                }
                $t['weekly'] += app(AgentWeekly::class)->run();
                foreach (app(Appointments::class)->run() as $k => $n) {
                    $t[$k] += $n;
                }
            });
        }
        $this->info("Playbook emails: {$t['ready']} ready, {$t['sent']} sent. MLS: {$t['checked']} checked, {$t['reminded']} reminded, {$t['escalated']} escalated, {$t['resolved']} resolved. Weekly summaries: {$t['weekly']}. Appointments: {$t['vendor_reminders']} vendor reminder(s), {$t['missed']} missed.");

        return self::SUCCESS;
    }
}
