<?php

declare(strict_types=1);

namespace Modules\Marketing;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

/** Marketing (SG1, specs/marketing/01): RightAlly's own public website, edited from the HQ console. */
final class MarketingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/marketing.php', 'marketing');
        $this->app->singleton(Website::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'marketing');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/hq.php');
        $this->app->make(NavigationRegistry::class)->add(new NavItem('HQ', 'RightAlly website', '/hq/website', 'house', 19, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff]));
        if ($this->app->runningInConsole()) {
            $this->commands([Console\StarterContent::class]);
        }
    }
}
