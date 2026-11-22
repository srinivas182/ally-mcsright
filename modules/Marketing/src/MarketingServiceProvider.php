<?php

declare(strict_types=1);

namespace Modules\Marketing;

use Illuminate\Support\ServiceProvider;
use Modules\AI\Support\PromptRegistry;
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
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Websites and MLS', 'RightAlly website', '/hq/website', 'house', 10, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff]));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Websites and MLS', 'Content desk', '/hq/content', 'newspaper', 11, ability: 'hq.tenants.manage', kinds: [UserKind::HqStaff])); // MK3
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Settings', 'AI blog writer', '/settings/blog-writer', 'pen-line', 78, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent])); // MK4
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../prompts'); // MK3: the content desk's writing prompt
        if ($this->app->runningInConsole()) {
            $this->commands([Console\StarterContent::class, Console\ContentDesk::class, Console\TenantDesks::class]);
        }
    }
}
