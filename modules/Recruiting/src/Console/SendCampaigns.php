<?php

declare(strict_types=1);

namespace Modules\Recruiting\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Recruiting\Campaigns;

/** Every 15 minutes: send recruiting campaign steps that are due (RC2). */
final class SendCampaigns extends Command
{
    protected $signature = 'recruiting:campaigns';

    protected $description = 'Send recruiting campaign emails and texts that are due';

    public function handle(TenantContext $tenants): int
    {
        $sent = 0;
        foreach (Tenant::query()->where('status', 'active')->get() as $tenant) {
            $sent += $tenants->runAs($tenant, fn (): int => app(Campaigns::class)->sendDue());
        }
        $this->info("{$sent} message(s) sent.");

        return self::SUCCESS;
    }
}
