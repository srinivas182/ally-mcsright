<?php

declare(strict_types=1);

namespace Modules\Ads;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\AI\FeatureCatalog;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Branding\BrandGuideApproved;
use Modules\Core\Branding\EmailSignature;
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
        $this->app->bind(EmailSignature::class, Studio\BrandSignature::class); // MK2
        $this->app->scoped(ListingAds::class);
        $this->app->scoped(Review\AdCheck::class);
        $this->app->scoped(Review\MarketingReviews::class);
        $this->app->scoped(AdCopy::class);
        $this->app->scoped(Boost::class);
        $this->app->scoped(Lifecycle::class);
        $this->app->scoped(Returns::class);
        $this->app->tag([Meta\BoostLeads::class], 'connect.unrouted');
        $this->app->scoped(Social\SocialPosts::class);
        $this->app->scoped(Studio\StudioPlans::class);
        $this->app->scoped(Studio\BrandKits::class);
        $this->app->scoped(Studio\Designer::class);
        $this->app->scoped(Studio\Autopilot::class);
        $this->app->scoped(Studio\StylePreviews::class);
        $this->mergeConfigFrom(__DIR__.'/../config/studio.php', 'studio');
        $this->app->bind(Studio\Render\StudioRenderer::class, fn () => config('studio.renderer') === 'fake' ? new Studio\Render\FakeRenderer : new Studio\Render\ChromeRenderer);
        $this->app->bind(Studio\Enhance\PhotoEnhancer::class, Studio\Enhance\Autoenhance::class);
        $this->app->bind(Studio\Enhance\PhotoUpscaler::class, Studio\Enhance\Replicate::class);
        $this->app->bind(Studio\Voice\VoiceVendor::class, fn () => config('studio.voice') === 'fake' ? new Studio\Voice\FakeVoice : new Studio\Voice\ElevenLabs);
        $this->app->scoped(Studio\Voice\Narration::class);
        $this->app->scoped(Studio\Replies\StudioReplies::class);
        // MK3: Shotstack or Creatomate when HQ chose one in Outside services; our own ffmpeg renderer otherwise.
        $this->app->bind(Studio\Video\VideoMaker::class, fn () => config('studio.video') === 'fake' ? new Studio\Video\FakeVideoMaker
            : new Studio\Video\ServiceVideoMaker(new Studio\Video\FfmpegVideoMaker));
    }

    public function boot(): void
    {
        // BV1: an approved brand guide's colours and fonts go into the brokerage's Studio brand kit.
        Event::listen(BrandGuideApproved::class,
            fn (BrandGuideApproved $e) => $this->app->make(Studio\BrandKits::class)->fromBrandGuide($e));
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ads');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\RefreshBoosts::class, Console\SendSocialPosts::class, Console\RunStudioAutopilot::class, Console\ReadSocialResults::class, Console\SendStudioReports::class, Console\SweepStudioVoices::class]);
        }
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../prompts');
        $this->app->make(FeatureCatalog::class)->declare(AdCopy::FEATURE, 'ai.ads_copy', 'AI listing ad copy');
        $this->app->make(FeatureCatalog::class)->declare(Review\MarketingReviews::FEATURE, 'ai.ad_compliance', 'Marketing check AI rewrite'); // MK3
        $this->app->make(PermissionRegistry::class)->register('Marketing', [
            'marketing.review' => ['Approve agents\' ads, flyers and posts before they go out'],
        ]);
        $this->app->make(FeatureCatalog::class)->declare(Studio\Autopilot::FEATURE, 'ai.studio_caption', 'Ally Studio captions (included in each post)');
        $this->app->make(FeatureCatalog::class)->declare(Studio\Replies\StudioReplies::FEATURE, 'ai.studio_caption', 'Ally Studio reply drafts (included)');
        $this->app->make(FeatureCatalog::class)->declare(Studio\Voice\Narration::FEATURE, 'ai.studio_caption', 'Ally Studio voice-over scripts (included in each narration)');
        Studio\StudioBriefItems::register($this->app->make(DailyBriefs::class));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Grow', 'Ads', '/ads', 'megaphone', 30, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent]));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Grow', 'Marketing check', '/marketing-check', 'shield-check', 31, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent]));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Grow', 'Social posts', '/social', 'send', 27, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent]));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Grow', 'Ally Studio', '/studio', 'sparkles', 24, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent]));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Platform', 'Ally Studio', '/hq/studio', 'sparkles', 38, kinds: [UserKind::HqStaff]));
        $this->app->make(McpToolbox::class)->add(Mcp\SocialPostTool::class);
        $this->app->make(McpToolbox::class)->add(Mcp\StudioQueueTool::class);
        $this->app->make(McpToolbox::class)->add(Mcp\StudioApproveTool::class);
        $this->app->make(McpToolbox::class)->add(Mcp\StudioResultsTool::class);
    }
}
