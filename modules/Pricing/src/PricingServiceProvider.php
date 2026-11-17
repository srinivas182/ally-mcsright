<?php

declare(strict_types=1);

namespace Modules\Pricing;

use Illuminate\Support\ServiceProvider;
use Modules\AI\FeatureCatalog;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

/** Pricing (specs/listings/01): CMAs, seller net sheets and buyer cost estimates. */
final class PricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pricing.php', 'pricing');
        $this->app->scoped(Cmas::class);
        $this->app->scoped(Sheets::class);
        $this->app->scoped(Defaults::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'pricing');
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../prompts');
        $this->app->make(FeatureCatalog::class)->declare(Cmas::FEATURE, 'ai.cma_summary', 'CMA pricing summary for the seller');
        Brief\PricingBriefItems::register($this->app->make(DailyBriefs::class));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Grow', 'CMA and estimates', '/pricing', 'calculator', 10, kinds: [UserKind::Owner, UserKind::Agent]));
    }
}
