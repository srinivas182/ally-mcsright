<?php

declare(strict_types=1);

namespace Modules\Listings;

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Listings\MlsStatus;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;
use Modules\Core\Search\SearchRegistry;

/** Listings: MLS ingestion, display rules and licence enforcement (specs/websites/02). */
final class ListingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/listings.php', 'listings');
        $this->app->bindIf(Feed\ResoFeed::class, Feed\FeedRouter::class); // IDX3: Repliers or the RESO Web API, per feed
        $this->app->scoped(Media\Photos::class);
        $this->app->scoped(Search\ListingSearch::class);
        $this->app->scoped(Search\MarketStats::class);
        $this->app->scoped(Sync\ListingSync::class);
        $this->app->singleton(Access\MlsAccess::class);
        $this->app->bind(MlsStatus::class, Sync\FeedMlsStatus::class); // the MLS status guard (Deal-IQ)
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        // SR1: cross-MLS duplicates and the fast search server follow every sync.
        Event::listen(Events\ListingsChanged::class, fn (Events\ListingsChanged $e) => $e->listingIds !== [] ? Jobs\RefreshListingSearch::dispatch($e->listingIds) : null);
        // PW1 (specs/core/17): listings in universal search, by MLS number or street, from licensed MLSs only.
        $this->app->make(SearchRegistry::class)->register('listings', ['en' => 'Listings', 'es' => 'Propiedades'], 30,
            function (User $user, string $q, int $limit): array {
                if (! in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant], true)) {
                    return [];
                }
                $words = preg_split('/\s+/', $q) ?: [];
                $number = ctype_digit($words[0] ?? '') ? $words[0] : null;
                $street = trim(implode(' ', $number !== null ? array_slice($words, 1) : $words));

                return array_values(Models\Listing::query()->visibleTo((int) $user->tenant_id)
                    ->where(function ($w) use ($q, $number, $street): void {
                        $w->where('listing_id', $q);
                        if ($street !== '') {
                            $w->orWhere(function ($a) use ($number, $street): void {
                                $a->where('street_name', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $street).'%');
                                if ($number !== null) {
                                    $a->where('street_number', $number);
                                }
                            });
                        }
                    })
                    ->limit($limit)->get()
                    ->map(fn (Models\Listing $l): array => ['title' => $l->address(), 'subtitle' => trim(($l->city ?? '').' · '.($l->standard_status ?? ''), ' ·'),
                        'href' => '/listings/'.$l->ulid])->all());
            });
        if ($this->app->runningInConsole()) {
            $this->commands([Console\SyncListings::class, Console\ReconcileListings::class, Console\RebuildSearch::class, Console\TagPhotosAndPlaces::class, Console\RemindIdxSignatures::class]);
        }

        Gate::define('hq.mls.manage', fn (User $user): bool => $user->kind === UserKind::HqStaff);
        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('HQ', 'MLS connections', '/hq/mls', 'building-2', 18, ability: 'hq.mls.manage', kinds: [UserKind::HqStaff]));
        $nav->add(new NavItem('HQ', 'IDX coverage', '/hq/idx', 'map', 18, ability: 'hq.mls.manage', kinds: [UserKind::HqStaff])); // IDX1
        $nav->add(new NavItem('Settings', 'MLS', '/settings/mls', 'building-2', 66, kinds: [UserKind::Owner]));
        $nav->add(new NavItem('Grow', 'Listings', '/listings', 'home', 8, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant], mobile: true));
    }
}
