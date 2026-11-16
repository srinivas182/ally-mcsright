<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Core\Workflows\Workflows;

/** Every five minutes: automations whose wait is over carry on (specs/core/06, W1). */
#[CrossTenantAccess('Carry on waiting automations for every brokerage')]
final class RunWorkflows extends Command
{
    protected $signature = 'core:workflows';

    protected $description = 'Carry on automations whose wait is over';

    public function handle(TenantContext $tenants): int
    {
        $n = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $n += $tenants->runAs($tenant, fn (): int => app(Workflows::class)->resumeDue());
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("{$n} automation run(s) carried on.");

        return self::SUCCESS;
    }
}
