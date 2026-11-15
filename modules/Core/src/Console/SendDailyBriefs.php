<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/** Hourly on weekdays: the daily brief email at 7am in each person's time zone (specs/core/09). */
#[CrossTenantAccess('Send the daily brief email for every brokerage')]
final class SendDailyBriefs extends Command
{
    protected $signature = 'core:daily-briefs';

    protected $description = 'Send the morning daily brief emails';

    public function handle(TenantContext $tenants): int
    {
        $n = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $n += $tenants->runAs($tenant, fn (): int => app(DailyBriefs::class)->sendDue());
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("{$n} brief(s) sent.");

        return self::SUCCESS;
    }
}
