<?php

declare(strict_types=1);

namespace Modules\Crm\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Leads\LongNurture;

/** Hourly: start long follow-up for leads that went quiet, and send the touches that are due (specs/grow/09). */
#[CrossTenantAccess('Run Lead-IQ long follow-up for every brokerage')]
final class RunNurture extends Command
{
    protected $signature = 'crm:nurture';

    protected $description = 'Start and send Lead-IQ long follow-up';

    public function handle(TenantContext $tenants): int
    {
        $started = 0;
        $sent = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $tenants->runAs($tenant, function () use (&$started, &$sent): void {
                    $nurture = app(LongNurture::class);
                    $started += $nurture->enrol();
                    $sent += $nurture->runDue();
                });
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("{$started} started, {$sent} message(s) sent.");

        return self::SUCCESS;
    }
}
