<?php

declare(strict_types=1);

namespace Modules\ListIq\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\ListIq\Showings;

/** Every 15 minutes: ask buyers' agents for feedback an hour after each showing (SH1). */
final class ShowingFeedback extends Command
{
    protected $signature = 'listiq:showing-feedback';

    protected $description = 'Ask for showing feedback';

    public function handle(TenantContext $tenants): int
    {
        $sent = 0;
        foreach (Tenant::query()->where('status', 'active')->get() as $tenant) {
            $sent += $tenants->runAs($tenant, fn (): int => app(Showings::class)->askFeedback());
        }
        $this->info("{$sent} feedback request(s) sent.");

        return self::SUCCESS;
    }
}
