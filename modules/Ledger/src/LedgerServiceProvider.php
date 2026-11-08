<?php

declare(strict_types=1);

namespace Modules\Ledger;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Tenancy\Events\TenantProvisioned;
use Modules\Ledger\Console\ReconcileBalances;
use Modules\Ledger\Listeners\SeedChartOfAccounts;

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

        Event::listen(TenantProvisioned::class, SeedChartOfAccounts::class);
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // DENY BY DEFAULT. Users are not yet bound to a tenant (Identity, Sprints 3-4) and roles do
        // not exist yet (Sprints 5-6), so no one may open the finance screens until both ship and
        // replace these gates with real permissions (specs/core/03).
        foreach (['finance.ledger.view', 'finance.ledger.adjust', 'finance.ledger.export', 'finance.periods.close'] as $ability) {
            Gate::define($ability, fn (): bool => false);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileBalances::class]);
        }
    }
}
