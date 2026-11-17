<?php

declare(strict_types=1);

namespace Modules\Core\Safeguards\Console;

use Illuminate\Console\Command;
use Modules\Core\Safeguards\BrokerageKeys;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/** Re-seals sensitive fields with each brokerage's own current key (DS2). Safe to run again. */
final class Rekey extends Command
{
    protected $signature = 'safeguards:rekey {tenant? : a brokerage slug; all when left out}';

    protected $description = 'Re-seal sensitive fields with each brokerage\'s own key';

    public function handle(TenantContext $tenants, BrokerageKeys $keys): int
    {
        $query = Tenant::query()->where('status', 'active');
        if ($this->argument('tenant') !== null) {
            $query->where('slug', (string) $this->argument('tenant'));
        }
        foreach ($query->get() as $tenant) {
            $n = $tenants->runAs($tenant, fn (): int => $keys->rekey());
            $this->info("{$tenant->slug}: {$n} value(s) re-sealed.");
        }

        return self::SUCCESS;
    }
}
