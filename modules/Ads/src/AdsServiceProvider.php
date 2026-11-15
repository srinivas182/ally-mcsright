<?php

declare(strict_types=1);

namespace Modules\Ads;

use Illuminate\Support\ServiceProvider;
use Modules\AI\FeatureCatalog;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;

/** Ads: listing ads made with AI and published from RightAlly (specs/ads/01). */
final class AdsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ListingAds::class);
        $this->app->scoped(AdCopy::class);
        $this->app->scoped(Boost::class);
        $this->app->scoped(Lifecycle::class);
        $this->app->scoped(Returns::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\RefreshBoosts::class]);
        }
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../prompts');
        $this->app->make(FeatureCatalog::class)->declare(AdCopy::FEATURE, 'ai.ads_copy', 'AI listing ad copy');
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Grow', 'Ads', '/ads', 'megaphone', 30, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent]));
    }
}
