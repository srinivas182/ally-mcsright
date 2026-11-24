<?php

declare(strict_types=1);

namespace Modules\Roster\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Roster\Brokerage\BrokerageLicences;
use Throwable;

/** LV2: daily, after the state lists are downloaded: every brokerage's licences checked again, owners warned before expiry. */
final class RecheckBrokerageLicences extends Command
{
    protected $signature = 'roster:brokerage-licences';

    protected $description = "Check brokerages' licences against the latest state records and warn owners before they expire";

    public function handle(TenantContext $tenants): int
    {
        $sent = 0;
        foreach (Tenant::query()->where('type', '!=', 'hq')->orderBy('id')->get() as $tenant) {
            try {
                $sent += (int) $tenants->runAs($tenant, fn (): int => app(BrokerageLicences::class)->recheck());
            } catch (Throwable $e) {
                report($e);
            }
        }
        $this->info("Brokerage licences checked; {$sent} message(s) sent to owners.");

        return self::SUCCESS;
    }
}
