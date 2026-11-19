<?php

declare(strict_types=1);

namespace Modules\Crm\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Brief\MorningList;

/** SX6, every 15 minutes on weekdays: the morning text with today's list, for agents who turned it on. */
final class SendMorningTexts extends Command
{
    protected $signature = 'crm:morning-texts';

    protected $description = 'Send the morning list texts agents opted in to';

    public function handle(TenantContext $tenants): int
    {
        $sent = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $sent += $tenants->runAs($tenant, fn (): int => app(MorningList::class)->textDue());
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("{$sent} morning text(s) sent.");

        return self::SUCCESS;
    }
}
