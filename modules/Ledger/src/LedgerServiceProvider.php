<?php

declare(strict_types=1);

namespace Modules\Ledger;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Identity\Events\UserActivated;
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
        $this->app->singleton(Close\CloseChecks::class);
        $this->app->singleton(Audit\AuditPack::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ledger');

        Event::listen(TenantProvisioned::class, SeedChartOfAccounts::class);

        // FH1 (specs/finance/22): the month-end checklist. Other modules add their own checks.
        $checks = $this->app->make(Close\CloseChecks::class);
        foreach ([Close\Checks\BooksBalance::class, Close\Checks\WaitingAdjustments::class, Close\Checks\BankReconciled::class] as $check) {
            $checks->register($check);
        }
        // FH2 (specs/finance/23): the audit pack and outside-auditor access.
        $this->app->make(Audit\AuditPack::class)->register(Audit\LedgerPart::class);
        Event::listen(UserActivated::class, [Audit\AuditorAccess::class, 'onActivated']);

        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('Finance', 'Chart of accounts', '/finance/accounts', 'landmark', 10, ability: 'finance.ledger.view', mobile: true));
        $nav->add(new NavItem('Finance', 'Journals', '/finance/journals', 'book-open', 20, ability: 'finance.ledger.view'));
        $nav->add(new NavItem('Finance', 'Adjustments', '/finance/adjustments', 'scale', 25, ability: 'finance.ledger.view')); // FH1
        $nav->add(new NavItem('Finance', 'Month-end close', '/finance/periods', 'calendar-check', 30, ability: 'finance.ledger.view'));
        $nav->add(new NavItem('Finance', 'Books (QuickBooks, Xero)', '/finance/books', 'book-open', 35, ability: 'finance.ledger.view'));
        $nav->add(new NavItem('Finance', 'Bills', '/finance/bills', 'receipt', 36, ability: 'finance.ledger.view')); // AC1
        $nav->add(new NavItem('Finance', 'Invoices', '/finance/invoices', 'file-text', 37, ability: 'finance.ledger.view'));
        $nav->add(new NavItem('Finance', 'Bank reconciliation', '/finance/bank', 'landmark', 38, ability: 'finance.ledger.view'));
        $nav->add(new NavItem('Finance', 'Profit and loss', '/finance/statements', 'chart-column', 39, ability: 'finance.ledger.view')); // AC2
        $nav->add(new NavItem('Finance', 'Audit pack', '/finance/audit', 'shield-check', 41, ability: 'finance.ledger.export')); // FH2
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
            'finance.ledger.approve' => ['Approve adjustments to the books entered by someone else'],
            'finance.amounts.view' => ['See commission amounts and earnings', false, true],
            'finance.bank_details.manage' => ['Change payout and bank details', false, true],
            'finance.payouts.approve' => ['Approve payouts'],
        ]);
        $registry->conflict('finance.bank_details.manage', 'finance.payouts.approve',
            'One person cannot both change bank details and approve payouts (separation of duties).');

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileBalances::class, Console\CloseCheck::class, Console\ExpireAuditPacks::class]);
        }
    }
}
