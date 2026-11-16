<?php

declare(strict_types=1);

namespace Modules\Insights;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

/** Reports and KPIs (specs/insights/01). */
final class InsightsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Numbers::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        $this->app->make(PermissionRegistry::class)->register('Reports', [
            'insights.view' => ['See brokerage numbers: sales, pipeline, lead sources and agents', true],
        ]);
        Gate::define('insights.numbers', fn (User $user): bool => Numbers::people($user) !== false);
        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('Overview', 'Numbers', '/insights', 'chart-column', 28, ability: 'insights.numbers', kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent]));
        $nav->add(new NavItem('Overview', 'My numbers', '/insights/me', 'target', 28, kinds: [UserKind::Agent], mobile: true));
    }
}
