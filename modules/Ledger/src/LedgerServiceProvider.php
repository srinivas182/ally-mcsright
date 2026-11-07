<?php

declare(strict_types=1);

namespace Modules\Ledger;

use Illuminate\Support\ServiceProvider;
use Modules\Ledger\Console\ReconcileBalances;

/**
 * Ledger module: money primitives and the immutable double-entry ledger.
 */
final class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(LedgerService::class);
        $this->app->scoped(ChartOfAccounts::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileBalances::class]);
        }
    }
}
