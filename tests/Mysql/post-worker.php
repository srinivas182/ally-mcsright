<?php

declare(strict_types=1);

/*
| Worker for the ledger concurrency test: boots the application in its own process and posts
| journals for one tenant. Usage: php post-worker.php {tenantId} {count} {keyPrefix} {cents}
*/

use Illuminate\Contracts\Console\Kernel;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\JournalDraft;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Money\Money;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $tenantId, $count, $prefix, $cents] = $argv;

$app->make(TenantContext::class)->set(Tenant::query()->findOrFail((int) $tenantId));
$chart = $app->make(ChartOfAccounts::class);
$ledger = $app->make(LedgerService::class);
$cash = $chart->account('1000');
$equity = $chart->account('3000');

for ($i = 0; $i < (int) $count; $i++) {
    $ledger->post(
        JournalDraft::make('stress', "{$prefix}-{$i}")
            ->debit($cash, Money::of((int) $cents))
            ->credit($equity, Money::of((int) $cents)),
    );
}

echo "ok\n";
