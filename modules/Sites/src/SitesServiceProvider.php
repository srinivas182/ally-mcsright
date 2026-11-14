<?php

declare(strict_types=1);

namespace Modules\Sites;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

/** Websites: site engine, templates, SEO and AEO (specs/websites/05). */
final class SitesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sites.php', 'sites');
        $this->app->scoped(SiteSetup::class);
        $this->app->singleton(PoweredBy::class);
        $this->app->scoped(Maps::class);
        // Listing links in the back office, alerts and texts point at the brokerage's live website.
        $this->app->bind('listings.public_url', fn ($app) => function (string $ulid, ?string $label = null) use ($app): ?string {
            $host = $app->make(SiteSetup::class)->publicHost();

            return $host !== null ? 'https://'.$host.Rendering\Urls::listing($ulid, $label) : null;
        });
        $this->app->scoped(Http\SiteRouter::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'sites');

        Gate::define('hq.sites.manage', fn (User $user): bool => $user->kind === UserKind::HqStaff);
        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('Settings', 'Website', '/settings/website', 'layout-grid', 64, kinds: [UserKind::Owner, UserKind::Staff]));
        $nav->add(new NavItem('HQ', 'Websites', '/hq/sites', 'layout-grid', 17, ability: 'hq.sites.manage', kinds: [UserKind::HqStaff]));
    }
}
