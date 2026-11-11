<?php

declare(strict_types=1);

namespace Modules\Ledger;

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;
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

        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('Finance', 'Chart of accounts', '/finance/accounts', 'landmark', 10, ability: 'finance.ledger.view', mobile: true));
        $nav->add(new NavItem('Finance', 'Journals', '/finance/journals', 'book-open', 20, ability: 'finance.ledger.view'));
        $nav->add(new NavItem('Finance', 'Periods', '/finance/periods', 'calendar-check', 30, ability: 'finance.ledger.view'));
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Interim until roles and permissions (Sprints 5-6, specs/core/03): only the brokerage's
        // owners may use the finance screens. Users are tenant-scoped (Sprint 3A), so an owner only
        // ever sees their own brokerage's ledger.
        foreach (['finance.ledger.view', 'finance.ledger.adjust', 'finance.ledger.export', 'finance.periods.close'] as $ability) {
            Gate::define($ability, fn (User $user): bool => $user->kind === UserKind::Owner);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileBalances::class]);
        }
    }
}
