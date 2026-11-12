<?php

declare(strict_types=1);

namespace Modules\Ledger;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Access\PermissionRegistry;
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
        // Decided by the permission engine (specs/core/03); owners have all of these.
        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('Finance', [
            'finance.ledger.view' => ['See the books (accounts, journals, periods)'],
            'finance.ledger.adjust' => ['Post adjustments to the books'],
            'finance.ledger.export' => ['Export the books'],
            'finance.periods.close' => ['Close and reopen accounting periods'],
            'finance.amounts.view' => ['See commission amounts and earnings', false, true],
            'finance.bank_details.manage' => ['Change payout and bank details', false, true],
            'finance.payouts.approve' => ['Approve payouts'],
        ]);
        $registry->conflict('finance.bank_details.manage', 'finance.payouts.approve',
            'One person cannot both change bank details and approve payouts (separation of duties).');

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileBalances::class]);
        }
    }
}
