<?php

declare(strict_types=1);

namespace Modules\Sites;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\AI\FeatureCatalog;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Leads\VisitorJourneys;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;
use Modules\Core\Tenancy\TenantContext;

/** Websites: site engine, templates, SEO and AEO (specs/websites/05). */
final class SitesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sites.php', 'sites');
        $this->app->scoped(SiteSetup::class);
        $this->app->singleton(PoweredBy::class);
        $this->app->scoped(Maps::class);
        $this->app->scoped(Visitors\Visitors::class);
        $this->app->scoped(Content\MediaLibrary::class);
        $this->app->scoped(Rendering\SiteContext::class);
        $this->app->scoped(Visitors\SignIn::class);
        $this->app->scoped(Visitors\JourneySummary::class);
        $this->app->scoped(Visitors\Valuation::class);
        // The CRM shows website journeys on leads through Core's interface (specs/websites/07).
        $this->app->bind(VisitorJourneys::class, Visitors\SiteVisitorJourneys::class);
        // Listing links in the back office, alerts and texts point at the brokerage's live website.
        $this->app->bind('listings.public_url', fn ($app) => function (string $ulid, ?string $label = null) use ($app): ?string {
            // Outside a brokerage (Movewise, HQ) there is no brokerage website to link to.
            $host = $app->make(TenantContext::class)->check() ? $app->make(SiteSetup::class)->publicHost() : null;

            return $host !== null ? 'https://'.$host.Rendering\Urls::listing($ulid, $label) : null;
        });
        $this->app->scoped(Http\SiteRouter::class);
        $this->app->singleton(Http\SiteExtensions::class);
    }

    public function boot(): void
    {
        Brief\ChatBriefItems::register($this->app->make(DailyBriefs::class));
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'sites');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\WriteMarketUpdates::class, Console\AillyWeekly::class, Console\SendHomeReports::class]);
        }
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../prompts');
        $features = $this->app->make(FeatureCatalog::class);
        $features->declare(Visitors\JourneySummary::FEATURE, 'ai.site_journey', 'Website visitor journey summary on new leads');
        $features->declare(Ai\ContentStudio::POST, 'ai.site_post', 'AI blog post drafts for websites');
        $features->declare(Ai\ContentStudio::PAGE, 'ai.site_page', 'AI page drafts for websites');
        $features->declare(Ai\ContentStudio::ASSIST, 'ai.site_assist', 'AI editing help on websites (rewrite, FAQ, meta, bio)');
        $features->declare(Ai\ContentStudio::MARKET, 'ai.site_market', 'Monthly AI market update drafts');
        $features->declare(Ailly\Assistant::FEATURE, 'ai.ailly', 'AIlly website assistant replies');
        $features->declare(Visitors\ClosingBrief::FEATURE, 'ai.closing_brief', 'Closing brief on website leads (chat and journey)');

        Gate::define('hq.sites.manage', fn (User $user): bool => $user->kind === UserKind::HqStaff);
        $this->app->make(PermissionRegistry::class)->register('Websites', [
            'sites.manage' => ['Manage the brokerage website, AIlly assistant and reviews, and take over live chats'],
        ]);
        Gate::define('sites.admin', fn (User $user): bool => SiteAccess::manager($user));
        $nav = $this->app->make(NavigationRegistry::class);
        $nav->add(new NavItem('Grow', 'Buyer demand', '/listings/demand', 'trending-up', 9, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent]));
        $nav->add(new NavItem('Grow', 'Funnels', '/funnels', 'filter', 25, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent]));
        $nav->add(new NavItem('Grow', 'Reviews', '/reviews', 'star', 26, kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent]));
        $nav->add(new NavItem('Settings', 'Website', '/settings/website', 'layout-grid', 64, ability: 'sites.admin', kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant]));
        $nav->add(new NavItem('Settings', 'My website', '/settings/my-website', 'layout-grid', 65, kinds: [UserKind::Agent]));
        $nav->add(new NavItem('Settings', 'AIlly assistant', '/settings/ailly', 'sparkles', 66, ability: 'sites.admin', kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant]));
        $nav->add(new NavItem('HQ', 'Websites', '/hq/sites', 'layout-grid', 17, ability: 'hq.sites.manage', kinds: [UserKind::HqStaff]));
    }
}
