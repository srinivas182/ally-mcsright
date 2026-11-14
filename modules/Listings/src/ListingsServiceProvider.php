<?php

declare(strict_types=1);

namespace Modules\Listings;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

/** Listings: MLS ingestion, display rules and licence enforcement (specs/websites/02). */
final class ListingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/listings.php', 'listings');
        $this->app->bindIf(Feed\ResoFeed::class, Feed\ResoWebApi::class);
        $this->app->scoped(Media\Photos::class);
        $this->app->scoped(Search\ListingSearch::class);
        $this->app->scoped(Search\MarketStats::class);
        $this->app->scoped(Sync\ListingSync::class);
        $this->app->singleton(Access\MlsAccess::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\SyncListings::class, Console\ReconcileListings::class]);
        }

        Gate::define('hq.mls.manage', fn (User $user): bool => $user->kind === UserKind::HqStaff);
        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('HQ', 'MLS connections', '/hq/mls', 'building-2', 18, ability: 'hq.mls.manage', kinds: [UserKind::HqStaff]));
        $nav->add(new NavItem('Settings', 'MLS', '/settings/mls', 'building-2', 66, kinds: [UserKind::Owner]));
        $nav->add(new NavItem('Grow', 'Listings', '/listings', 'home', 8, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant], mobile: true));
    }
}
