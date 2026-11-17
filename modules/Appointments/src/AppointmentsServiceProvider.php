<?php

declare(strict_types=1);

namespace Modules\Appointments;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Deals\BuyerAgreementGuard;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

/** Appointments (AP1, specs/listings/05): guided listing appointments, buyer consultations and the buyer-agreement guard. */
final class AppointmentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Consultations::class);
        $this->app->bind(BuyerAgreementGuard::class, Consultations::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'appointments');
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Grow', 'Appointments', '/appointments', 'calendar-check', 12,
            kinds: [UserKind::Owner, UserKind::Agent]));
    }
}
