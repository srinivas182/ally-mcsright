<?php

declare(strict_types=1);

namespace Modules\Ads;

use Illuminate\Support\ServiceProvider;
use Modules\AI\FeatureCatalog;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Mcp\McpToolbox;
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
        $this->app->scoped(Social\SocialPosts::class);
        $this->app->scoped(Studio\StudioPlans::class);
        $this->app->scoped(Studio\BrandKits::class);
        $this->app->scoped(Studio\Designer::class);
        $this->app->scoped(Studio\Autopilot::class);
        $this->app->scoped(Studio\StylePreviews::class);
        $this->mergeConfigFrom(__DIR__.'/../config/studio.php', 'studio');
        $this->app->bind(Studio\Render\StudioRenderer::class, fn () => config('studio.renderer') === 'fake' ? new Studio\Render\FakeRenderer : new Studio\Render\ChromeRenderer);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ads');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\RefreshBoosts::class, Console\SendSocialPosts::class, Console\RunStudioAutopilot::class]);
        }
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../prompts');
        $this->app->make(FeatureCatalog::class)->declare(AdCopy::FEATURE, 'ai.ads_copy', 'AI listing ad copy');
        $this->app->make(FeatureCatalog::class)->declare(Studio\Autopilot::FEATURE, 'ai.studio_caption', 'Ally Studio captions (included in each post)');
        Studio\StudioBriefItems::register($this->app->make(DailyBriefs::class));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Grow', 'Ads', '/ads', 'megaphone', 30, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent]));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Grow', 'Social posts', '/social', 'send', 27, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent]));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Grow', 'Ally Studio', '/studio', 'sparkles', 24, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent]));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Platform', 'Ally Studio', '/hq/studio', 'sparkles', 38, kinds: [UserKind::HqStaff]));
        $this->app->make(McpToolbox::class)->add(Mcp\SocialPostTool::class);
        $this->app->make(McpToolbox::class)->add(Mcp\StudioQueueTool::class);
        $this->app->make(McpToolbox::class)->add(Mcp\StudioApproveTool::class);
    }
}
