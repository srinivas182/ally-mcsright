<?php

declare(strict_types=1);

namespace Modules\Roster\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Roster\MlsIds;
use Throwable;

/** ML1: nightly, after the MLS feeds sync: find agents' MLS IDs on their brokerage's listings and ask them to confirm. */
final class MatchMlsIds extends Command
{
    protected $signature = 'roster:match-mls-ids';

    protected $description = "Find agents' MLS IDs on their brokerage's MLS listings and ask them to confirm";

    public function handle(TenantContext $tenants): int
    {
        $found = 0;
        foreach (Tenant::query()->where('type', '!=', 'hq')->orderBy('id')->get() as $tenant) {
            try {
                $found += (int) $tenants->runAs($tenant, fn (): int => app(MlsIds::class)->scan());
            } catch (Throwable $e) {
                report($e);
            }
        }
        $this->info("MLS IDs checked; {$found} new.");

        return self::SUCCESS;
    }
}
