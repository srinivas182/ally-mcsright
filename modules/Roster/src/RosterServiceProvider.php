<?php

declare(strict_types=1);

namespace Modules\Roster;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

final class RosterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ([Roster::class, LicenceGuard::class, Compliance::class, ExpiryReminders::class] as $service) {
            $this->app->scoped($service);
        }
        $this->app->tag([LicenceGuard::class], 'transactions.deal_guards');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\RosterReminders::class]);
        }

        $this->app->make(PermissionRegistry::class)->register('People', [
            'roster.manage' => ['Manage the agent roster: licences, E&O, memberships and documents'],
        ]);
        Gate::define('roster.view', fn (User $user): bool => Roster::manages($user));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('People', 'Roster and compliance', '/roster', 'id-card', 12, ability: 'roster.view'));
    }
}
