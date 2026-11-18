<?php

declare(strict_types=1);

namespace Modules\ListIq;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Modules\AI\FeatureCatalog;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Deals\ListingActivity;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;
use Modules\Sites\Http\SiteExtensions;

/** List-IQ (specs/listings/02): getting a listing ready. */
final class ListIqServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Preps::class);
        $this->app->scoped(Photos::class);
        $this->app->scoped(Descriptions::class);
        $this->app->scoped(Marketing::class);
        $this->app->scoped(Showings::class);
        $this->app->bind(ListingActivity::class, Showings::class);
        // MD1: the provider chosen in Outside services, falling back to the Replicate app (LI1).
        $this->app->bindIf(Staging\VirtualStager::class, fn (): Staging\VirtualStager => new Staging\ServiceStager(new Staging\ReplicateStager));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'listiq');
        // The listing's own page and its QR codes on the brokerage website (LI2).
        $this->app->make(SiteExtensions::class)->routes(function (Router $r): void {
            $r->get('listing/{listing}', [Http\PublicController::class, 'show'])->where('listing', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}');
            $r->post('_listing/{listing}', [Http\PublicController::class, 'lead'])->where('listing', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}');
            $r->get('q/{code}', [Http\PublicController::class, 'scan'])->where('code', '[a-z0-9]{7}');
            $r->post('_showing/{listing}', [Http\PublicController::class, 'showing'])->where('listing', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}');
        });
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../prompts');
        $this->app->make(FeatureCatalog::class)->declare(Descriptions::FEATURE, 'ai.listing_description', 'List-IQ listing descriptions and Spanish versions');
        Brief\ShowingBriefItems::register($this->app->make(DailyBriefs::class));
        if ($this->app->runningInConsole()) {
            $this->commands([Console\ShowingFeedback::class]);
        }
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Grow', 'List-IQ', '/list-iq', 'camera', 11, kinds: [UserKind::Owner, UserKind::Agent]));
    }
}
