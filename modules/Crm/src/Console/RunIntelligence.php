<?php

declare(strict_types=1);

namespace Modules\Crm\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\DatabaseIntelligence;
use Throwable;

/**
 * Nightly for every brokerage: re-score contacts, create follow-ups for intents coming due, and
 * send new notes to be read at batch price.
 */
final class RunIntelligence extends Command
{
    protected $signature = 'crm:intelligence {--limit=500 : Contacts to read per brokerage}';

    protected $description = 'Nightly database intelligence: scores, intent follow-ups, note reading';

    public function handle(TenantContext $tenants, DatabaseIntelligence $intelligence): int
    {
        foreach (Tenant::query()->where('status', 'active')->get() as $tenant) {
            try {
                $tenants->runAs($tenant, function () use ($intelligence): void {
                    $intelligence->scoreAll();
                    $intelligence->createAlerts();
                    $intelligence->analyseInBatch((int) $this->option('limit'));
                });
            } catch (Throwable $e) {
                $this->warn("{$tenant->slug}: ".class_basename($e));
            }
        }
        $this->info('Database intelligence run complete.');

        return self::SUCCESS;
    }
}
