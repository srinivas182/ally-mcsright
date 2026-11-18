<?php

declare(strict_types=1);

namespace Modules\Ledger\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\Accounting\BankFeeds\BankFeeds;

/** BK1: every morning, brings each brokerage's bank-feed transactions in (read-only). */
final class SyncBankFeeds extends Command
{
    protected $signature = 'ledger:bank-feeds';

    protected $description = 'Bring in new transactions from each brokerage\'s live bank feed';

    public function handle(TenantContext $tenants): int
    {
        $n = 0;
        foreach (Tenant::query()->where('status', 'active')->where('type', '!=', 'hq')->get() as $tenant) {
            try {
                $n += $tenants->runAs($tenant, fn (): int => DB::connection(Connections::cell())->table('acct_bank_feeds')->where('tenant_id', $tenant->id)->exists()
                    ? app(BankFeeds::class)->sync() : 0);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->info("{$n} transaction(s) brought in.");

        return self::SUCCESS;
    }
}
