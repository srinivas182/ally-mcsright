<?php

declare(strict_types=1);

namespace Modules\Sites\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Health\ContactChecks;
use Modules\Sites\Funnels\MovedFlags;

/** LN4 (E24): each day, check the next emails and phones and flag contacts whose home sold. */
final class ContactHealth extends Command
{
    protected $signature = 'crm:contact-health';

    protected $description = 'Check contact emails and phones, and flag contacts who may have moved';

    public function handle(TenantContext $tenants): int
    {
        $totals = [0, 0, 0];
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $tenants->runAs($tenant, function () use (&$totals): void {
                    $totals[0] += app(ContactChecks::class)->emails();
                    $totals[1] += app(ContactChecks::class)->phones();
                    $totals[2] += app(MovedFlags::class)->scan();
                });
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("{$totals[0]} emails and {$totals[1]} phones checked; {$totals[2]} contacts may have moved.");

        return self::SUCCESS;
    }
}
