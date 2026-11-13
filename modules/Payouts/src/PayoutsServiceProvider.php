<?php

declare(strict_types=1);

namespace Modules\Payouts;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

final class PayoutsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ([Receipts::class, BankAccounts::class, Balances::class, PayoutRuns::class, GetPaidNow::class] as $service) {
            $this->app->scoped($service);
        }
        $this->app->singleton(Rails\Rails::class);
        foreach ([Rails\RailPlatform::class, Rails\RailSetup::class, Rails\RailWebhooks::class] as $service) {
            $this->app->scoped($service);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        $this->app->make(PermissionRegistry::class)->register('Finance', [
            'finance.receipts.record' => ['Record commission received from title and resolve differences'],
        ]);

        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('Finance', 'Money received', '/finance/receipts', 'banknote', 5, ability: 'finance.receipts.record'));
        $nav->add(new NavItem('Finance', 'Payouts', '/finance/payouts', 'send', 6, ability: 'finance.receipts.record'));
        $nav->add(new NavItem('Platform', 'Payout rails', '/hq/payout-rails', 'send', 42, kinds: [UserKind::HqStaff]));
        $nav->add(new NavItem('Overview', 'My money', '/my-money', 'wallet', 32, kinds: [UserKind::Agent], mobile: true));
    }
}
