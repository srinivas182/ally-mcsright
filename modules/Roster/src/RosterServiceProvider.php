<?php

declare(strict_types=1);

namespace Modules\Roster;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

final class RosterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ([Roster::class, LicenceGuard::class, Compliance::class, ExpiryReminders::class, Agreements::class, Flows::class, UpdateRequests::class, ContinuingEducation::class, Verification::class] as $service) {
            $this->app->scoped($service);
        }
        $this->app->tag([LicenceGuard::class], 'transactions.deal_guards');
        // Automatic verification is off until the ARELLO adapter is bound (with the data agreement).
        $this->app->bindIf(Verification\LicenceVerifier::class, Verification\NotConfiguredVerifier::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\RosterReminders::class, Console\RosterVerify::class]);
        }

        $this->app->make(PermissionRegistry::class)->register('People', [
            'roster.manage' => ['Manage the agent roster: licences, E&O, memberships and documents'],
        ]);
        Gate::define('roster.view', fn (User $user): bool => Roster::manages($user));
        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('People', 'Roster and compliance', '/roster', 'id-card', 12, ability: 'roster.view'));
        $nav->add(new NavItem('Overview', 'My licences and onboarding', '/roster/me', 'id-card', 33, kinds: [UserKind::Agent]));
        $nav->add(new NavItem('People', 'Directory', '/directory', 'users', 11, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent]));
    }
}
