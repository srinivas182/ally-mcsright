<?php

declare(strict_types=1);

namespace Modules\Ledger\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Models\LedgerAccount;
use Modules\Ledger\Models\LedgerBalance;

/**
 * Nightly check that every running balance equals the sum of its lines.
 * Exit code 1 when any difference is found (should never happen).
 */
final class ReconcileBalances extends Command
{
    protected $signature = 'ledger:reconcile {--tenant= : Tenant ULID; all tenants when omitted}';

    protected $description = 'Verify ledger running balances against journal lines';

    public function handle(TenantContext $context, LedgerService $ledger): int
    {
        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($q, $ulid) => $q->where('ulid', $ulid))
            ->get();

        $differences = 0;

        foreach ($tenants as $tenant) {
            $context->runAs($tenant, function () use ($ledger, $tenant, &$differences): void {
                foreach (LedgerAccount::query()->get() as $account) {
                    $stored = (int) (LedgerBalance::query()->whereKey($account->id)->value('net_debit_cents') ?? 0);
                    $computed = $ledger->netDebitFromLines($account);

                    if ($stored !== $computed) {
                        $differences++;
                        $this->error("Tenant {$tenant->ulid} account {$account->code}: stored {$stored}, lines {$computed}");
                    }
                }
            });
        }

        if ($differences > 0) {
            $this->error("{$differences} balance difference(s) found.");

            return self::FAILURE;
        }

        $this->info('All ledger balances reconcile.');

        return self::SUCCESS;
    }
}
