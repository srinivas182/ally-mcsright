<?php

declare(strict_types=1);

namespace Modules\Roster\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Roster\ContinuingEducation;
use Modules\Roster\ExpiryReminders;

final class RosterReminders extends Command
{
    protected $signature = 'roster:reminders';

    protected $description = 'Email agents and owners about expiring licences, E&O and memberships';

    public function handle(TenantContext $tenants): int
    {
        $sent = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            $sent += $tenants->runAs($tenant, fn (): int => app(ExpiryReminders::class)->run() + app(ContinuingEducation::class)->remind());
        }
        $this->info("{$sent} reminder(s) sent.");

        return self::SUCCESS;
    }
}
