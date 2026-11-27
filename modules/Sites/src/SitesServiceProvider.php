<?php

declare(strict_types=1);

namespace Modules\Sites;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\AI\FeatureCatalog;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Branding\OfficeBrandSource;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Leads\VisitorJourneys;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;
use Modules\Core\Privacy\PrivacyRegistry;
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
        // WS1: the brokerage's website address for Core (welcome email, "Visit website" in the back office).
        $this->app->bind('sites.address', fn ($app) => function () use ($app): ?array {
            $setup = $app->make(SiteSetup::class);
            $site = $setup->brokerageSite();
            $host = $site !== null ? $setup->liveHost() : null;

            return $host !== null ? ['host' => $host, 'live' => $site->status === 'live'] : null;
        });
        $this->app->scoped(Http\SiteRouter::class);
        $this->app->scoped(Content\DemoView::class); // TP4: one demo visitor's choices, per request
        $this->app->bind(OfficeBrandSource::class, Content\SiteOfficeBrand::class); // BR2
        $this->app->singleton(Http\SiteExtensions::class);
    }

    public function boot(): void
    {
        Brief\ChatBriefItems::register($this->app->make(DailyBriefs::class));
        // TP4: on the demo website, the site shows in the design the visitor is looking at.
        Models\Site::retrieved(fn (Models\Site $site) => $this->app->make(Content\DemoView::class)->apply($site));
        // BR2: a change to the website's brand shows in the back office straight away.
        Models\Site::saved(fn (Models\Site $site) => Content\SiteOfficeBrand::forget((int) $site->tenant_id));
        Models\SitePage::saved(fn (Models\SitePage $page) => $page->path === '/' ? Content\SiteOfficeBrand::forget((int) $page->tenant_id) : null);
        Privacy\WebsitePrivacy::register($this->app->make(PrivacyRegistry::class)); // TR1
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'sites');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\WriteMarketUpdates::class, Console\AillyWeekly::class, Console\SendHomeReports::class, Console\ContactHealth::class, Console\InstallDemoPhotos::class, Console\InstallDemoSite::class, Console\MoveToOneDomain::class, Console\RefreshStarterContent::class, Console\CheckDomains::class]);
        }
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../prompts');
        // AW1: the calculators in the sitemap and llms.txt (specs/websites/18).
        $this->app->make(Http\SiteExtensions::class)->pages(fn (): array => array_merge(
            [['path' => '/calculators', 'title' => 'Home buying calculators', 'description' => 'monthly payment, what you can afford, and rent or buy']],
            array_map(fn (string $k, array $c): array => ['path' => '/calculators/'.$k, 'title' => $c['title'], 'description' => lcfirst($c['description'])],
                array_keys(Tools\HomeCalculators::KINDS), Tools\HomeCalculators::KINDS)));
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
        $nav->add(new NavItem('Settings', 'Website builder', '/settings/website', 'layout-grid', 64, ability: 'sites.admin', kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant]));
        // MN2: the website's own pages, in the Website menu.
        $nav->add(new NavItem('Settings', 'Blog', '/settings/website/blog', 'newspaper', 64, ability: 'sites.admin', kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant]));
        $nav->add(new NavItem('Settings', 'Photos and videos', '/settings/website/media', 'camera', 64, ability: 'sites.admin', kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant]));
        $nav->add(new NavItem('Settings', 'Communities', '/settings/website/areas', 'map', 64, ability: 'sites.admin', kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant]));
        $nav->add(new NavItem('Settings', 'My website', '/settings/my-website', 'layout-grid', 65, kinds: [UserKind::Agent]));
        $nav->add(new NavItem('Settings', 'AIlly assistant', '/settings/ailly', 'sparkles', 66, ability: 'sites.admin', kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant]));
        $nav->add(new NavItem('Websites and MLS', 'Websites', '/hq/sites', 'layout-grid', 12, ability: 'hq.sites.manage', kinds: [UserKind::HqStaff]));
    }
}
