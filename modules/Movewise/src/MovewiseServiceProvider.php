<?php

declare(strict_types=1);

namespace Modules\Movewise;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

/** Movewise Homes (specs/movewise): HQ's consumer site and lead marketplace, on the control plane. */
final class MovewiseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/movewise.php', 'movewise');
        $this->app->scoped(Movewise::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'movewise');
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Platform', 'Movewise', '/hq/movewise', 'house', 39, kinds: [UserKind::HqStaff]));
    }
}
