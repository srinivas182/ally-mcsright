<?php

declare(strict_types=1);

namespace Modules\Forms\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Forms\Signing;

/** Daily: signers who have not signed get a reminder every 2 days, up to 3. */
final class SigningReminders extends Command
{
    protected $signature = 'forms:reminders';

    protected $description = 'Remind signers who have not signed yet';

    public function handle(TenantContext $tenants): int
    {
        $sent = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            $host = $tenant->primaryDomain()?->host;
            if ($host !== null) {
                $sent += $tenants->runAs($tenant, fn (): int => app(Signing::class)->remind('https://'.$host));
            }
        }
        $this->info("{$sent} reminder(s) sent.");

        return self::SUCCESS;
    }
}
