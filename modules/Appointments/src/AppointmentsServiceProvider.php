<?php

declare(strict_types=1);

namespace Modules\Appointments;

use Illuminate\Support\ServiceProvider;
use Modules\AI\FeatureCatalog;
use Modules\AI\Support\PromptRegistry;
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
        $this->app->scoped(Recordings::class);
        $this->app->bind(BuyerAgreementGuard::class, Consultations::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'appointments');
        // VO2: recorded appointments with notes and coaching.
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../prompts');
        $this->app->make(FeatureCatalog::class)->declare(Recordings::FEATURE, 'ai.appointments_coaching', 'Appointment notes and coaching from recorded appointments');
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Grow', 'Appointments', '/appointments', 'calendar-check', 12,
            kinds: [UserKind::Owner, UserKind::Agent]));
    }
}
