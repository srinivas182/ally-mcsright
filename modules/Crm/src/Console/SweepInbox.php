<?php

declare(strict_types=1);

namespace Modules\Crm\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Inbox\Inbox;

/** Every minute: nudge agents about replies waiting 15 minutes, and flag an hour's wait to the broker. */
#[CrossTenantAccess('Nudge agents about unanswered replies for every brokerage')]
final class SweepInbox extends Command
{
    protected $signature = 'crm:inbox-sweep';

    protected $description = 'Nudge agents about replies waiting too long';

    public function handle(TenantContext $tenants): int
    {
        $n = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $n += array_sum($tenants->runAs($tenant, fn (): array => app(Inbox::class)->sweep()));
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("{$n} conversation(s) flagged.");

        return self::SUCCESS;
    }
}
