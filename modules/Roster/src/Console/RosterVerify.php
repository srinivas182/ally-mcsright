<?php

declare(strict_types=1);

namespace Modules\Roster\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Roster\Verification;

/** Re-checks licences against state records (once a month each), when automatic verification is on. */
final class RosterVerify extends Command
{
    protected $signature = 'roster:verify';

    protected $description = 'Re-check agents\' licences against state records';

    public function handle(TenantContext $tenants): int
    {
        $checked = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            $checked += $tenants->runAs($tenant, fn (): int => app(Verification::class)->recheckDue());
        }
        $this->info("{$checked} licence(s) checked.");

        return self::SUCCESS;
    }
}
