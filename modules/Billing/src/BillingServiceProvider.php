<?php

declare(strict_types=1);

namespace Modules\Billing;

use App\Models\User;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Billing\Http\RequireFeature;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Catalogue::class);
        $this->app->scoped(Pricing::class);
        $this->app->scoped(Entitlements::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->app->make(Router::class)->aliasMiddleware('feature', RequireFeature::class);

        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('Settings', 'Agent plans', '/settings/agent-plans', 'badge-dollar-sign', 70, kinds: [UserKind::Owner]));
        // Agents only see Plans when their brokerage uses the agent-pays model.
        Gate::define('billing.plans.view', fn (User $user): bool => $user->kind === UserKind::Agent
            && $this->app->make(Entitlements::class)->model() === 'agent_pays');
        $nav->add(new NavItem('Overview', 'Plans', '/plans', 'gift', 90, ability: 'billing.plans.view'));

        if ($this->app->runningInConsole()) {
            $this->commands([Console\ApplyPriceChanges::class]);
        }
    }
}
