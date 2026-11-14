<?php

declare(strict_types=1);

namespace Modules\Forms\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Forms\Library\Libraries;

/** Daily: refresh every connected association library and tell brokerages about new editions of their forms. */
#[CrossTenantAccess('Check association form libraries for new editions for every brokerage')]
final class SyncLibraries extends Command
{
    protected $signature = 'forms:library-sync';

    protected $description = 'Check association form libraries for new editions';

    public function handle(TenantContext $tenants): int
    {
        $updates = 0;
        foreach (Tenant::query()->where('status', 'active')->cursor() as $tenant) {
            $tenants->runAs($tenant, function () use (&$updates): void {
                foreach (DB::connection(Connections::cell())->table('form_libraries')->pluck('ulid') as $ulid) {
                    $updates += count(app(Libraries::class)->sync((string) $ulid));
                }
            });
        }
        $this->info("{$updates} new edition(s) found.");

        return self::SUCCESS;
    }
}
