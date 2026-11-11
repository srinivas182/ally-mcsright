<?php

declare(strict_types=1);

namespace Modules\Crm\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\ContactBriefs;
use Throwable;

/**
 * Nightly: refresh AI briefs for contacts with recent activity, at batch price, for every brokerage.
 */
final class RefreshBriefs extends Command
{
    protected $signature = 'crm:refresh-briefs {--limit=500 : Contacts per brokerage}';

    protected $description = 'Refresh out-of-date AI contact briefs overnight';

    public function handle(TenantContext $tenants, ContactBriefs $briefs): int
    {
        $batches = 0;
        foreach (Tenant::query()->where('status', 'active')->get() as $tenant) {
            try {
                $batch = $tenants->runAs($tenant, fn () => $briefs->refreshInBatch((int) $this->option('limit')));
                $batches += $batch !== null ? 1 : 0;
            } catch (Throwable $e) {
                $this->warn("{$tenant->slug}: ".class_basename($e));
            }
        }
        $this->info("{$batches} batch(es) submitted.");

        return self::SUCCESS;
    }
}
