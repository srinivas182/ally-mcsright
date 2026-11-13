<?php

declare(strict_types=1);

namespace Modules\Forms;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

final class FormsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ([Templates::class, Packets::class, Signing::class, Sealer::class] as $service) {
            $this->app->scoped($service);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\SigningReminders::class, Console\SealKey::class]);
        }
        $this->app->make(PermissionRegistry::class)->register('Transactions', [
            'forms.manage' => ['Manage forms: templates, fields and clauses'],
        ]);
        Gate::define('forms.templates', fn (User $user): bool => Templates::manages($user));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Settings', 'Forms', '/forms/templates', 'file-text', 77, ability: 'forms.templates'));
    }
}
