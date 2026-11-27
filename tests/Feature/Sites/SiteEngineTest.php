<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Tenancy\DomainResolver;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Models\Lead;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;
use Modules\Sites\PoweredBy;
use Modules\Sites\SiteSetup;

require_once __DIR__.'/../Contracts/contract-helpers.php';

/* Sprint 17C-1: the website engine — website hosts, templates, SEO and AEO, lead forms, Powered by RightAlly. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Bea', 'last_name' => 'Broker']);
    $this->agent = User::factory()->create();
    $this->web = 'http://acme.rightally.site';
});

function makeSite(object $t, string $template = 'aurum', bool $live = true): Site
{
    $sites = app(SiteSetup::class);
    $site = $sites->create($template, ['tagline' => 'Boutique homes in Austin', 'area' => 'Greater Austin', 'phone' => '(512) 555-0100', 'email' => 'hello@acme.test',
        'city' => 'Austin', 'state' => 'tx', 'about' => 'Family-owned since 1998.'], $t->owner);
    if ($live) {
        $sites->publish($site, true, $t->owner);
    }

    return $site->refresh();
}

it('creates a starter site with a website address, and only owners and staff can', function (): void {
    expect(fn () => app(SiteSetup::class)->create('modern', [], $this->agent))->toThrow(ValidationException::class);
    $site = makeSite($this, 'modern', false);

    expect($site->status)->toBe('draft')->and($site->info['state'])->toBe('TX')->and($site->info['name'])->toBe($this->tenant->name)
        ->and(SitePage::query()->orderBy('position')->pluck('path')->all())->toBe(['/', '/buy', '/sell', '/communities', '/about', '/join-us', '/contact']);
    $domain = TenantDomain::query()->where('host', 'acme.rightally.site')->sole();
    expect($domain->purpose)->toBe('website')->and($domain->is_primary)->toBeFalse()
        ->and($this->tenant->refresh()->primaryDomain()?->host)->toBe('acme.rightally.app');
    expect(fn () => makeSite($this))->toThrow(ValidationException::class); // one brokerage site
});

it('serves a draft site as a private coming-soon page that search engines are told to skip', function (): void {
    makeSite($this, 'aurum', false);

    $this->get($this->web.'/')->assertOk()->assertSee('Coming soon')->assertHeader('X-Robots-Tag', 'noindex');
    expect($this->get($this->web.'/robots.txt')->assertOk()->getContent())->toContain("User-agent: *\nDisallow: /");
    expect($this->get($this->web.'/sitemap.xml')->getContent())->not->toContain('<url>');
    $this->get($this->web.'/llms.txt')->assertNotFound();
});

it('renders live pages as complete, cacheable HTML with SEO tags and structured data', function (): void {
    makeSite($this);

    $home = $this->get($this->web.'/')->assertOk();
    $html = (string) $home->getContent();
    expect($home->headers->get('Cache-Control'))->toContain('s-maxage=300')->toContain('public')
        ->and($home->headers->getCookies())->toBe([]) // no session cookie: cacheable at the edge
        ->and($html)->toContain('<link rel="canonical" href="http://acme.rightally.site/">')
        ->toContain('<meta property="og:title"')->toContain('theme-aurum')
        ->toContain('"@type":"RealEstateAgent"')->toContain('"@type":"WebSite"')
        ->toContain('Boutique homes in Austin')->toContain('Equal Housing Opportunity');

    $about = (string) $this->get($this->web.'/about')->assertOk()->getContent();
    expect($about)->toContain('"@type":"BreadcrumbList"')->toContain('Family-owned since 1998.');
    $this->get($this->web.'/nope')->assertNotFound();
});

it('adds FAQ structured data for FAQ sections, which AI answer engines quote directly', function (): void {
    $site = makeSite($this);
    $page = SitePage::query()->where('site_id', $site->id)->where('path', '/about')->sole();
    $page->forceFill(['sections' => [...$page->sections, ['type' => 'faq', 'props' => ['title' => 'Questions', 'items' => [
        ['q' => 'Do you charge buyers?', 'a' => 'No, our buyer services are free to you.'],
    ]]]]])->save();

    $html = (string) $this->get($this->web.'/about')->assertOk()->getContent();
    expect($html)->toContain('"@type":"FAQPage"')->toContain('Do you charge buyers?')->toContain('No, our buyer services are free to you.');
});

it('welcomes AI crawlers in robots.txt unless the brokerage opts out, and publishes a sitemap and llms.txt', function (): void {
    $site = makeSite($this);
    SitePage::query()->where('path', '/contact')->update(['noindex' => true]);

    $robots = (string) $this->get($this->web.'/robots.txt')->getContent();
    expect($robots)->toContain("User-agent: GPTBot\nAllow: /")->toContain("User-agent: PerplexityBot\nAllow: /")->toContain("User-agent: ClaudeBot\nAllow: /")
        ->toContain('Sitemap: http://acme.rightally.site/sitemap.xml')->toContain('Disallow: /_preview/');
    $sitemap = (string) $this->get($this->web.'/sitemap.xml')->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
    expect($sitemap)->toContain('<loc>http://acme.rightally.site/</loc>')->toContain('/about</loc>')->not->toContain('/contact</loc>');
    $llms = (string) $this->get($this->web.'/llms.txt')->assertOk()->getContent();
    expect($llms)->toStartWith('# '.$this->tenant->name)->toContain('> Boutique homes in Austin')->toContain('- Areas served: Greater Austin')
        ->toContain('[About](http://acme.rightally.site/about)');

    app(SiteSetup::class)->update($site, ['ai_crawlers' => false], $this->owner);
    expect((string) $this->get($this->web.'/robots.txt')->getContent())->toContain("User-agent: GPTBot\nDisallow: /")->toContain("User-agent: *\nAllow: /");
});

it('keeps website hosts and the back office apart', function (): void {
    makeSite($this);

    // The website never serves the back office; DM1: it sends back-office addresses on to it.
    $this->actingAs($this->owner)->get($this->web.'/settings/website')->assertRedirect('https://acme.rightally.app/settings/website');
    $this->actingAs($this->owner)->get($this->web.'/login')->assertRedirect('https://acme.rightally.app/login');
    $this->actingAs($this->owner)->get('/settings/website')->assertOk(); // back office unaffected
    expect(app(DomainResolver::class)->isWebsite('acme.rightally.site'))->toBeTrue()->and(app(DomainResolver::class)->isWebsite('acme.rightally.app'))->toBeFalse();

    TenantDomain::query()->create(['tenant_id' => Tenant::factory()->create(['slug' => 'gone', 'status' => 'suspended'])->id, 'host' => 'gone.rightally.site', 'kind' => 'platform',
        'verification_status' => 'verified', 'ssl_status' => 'active', 'purpose' => 'website']);
    $this->get('http://gone.rightally.site/')->assertNotFound();
});

it('turns website enquiries into CRM leads with their channel, and quietly drops bots', function (): void {
    makeSite($this);
    $form = ['name' => 'Jane Doe', 'email' => 'jane@x.test', 'phone' => '(512) 555-0199', 'intent' => 'buy', 'message' => 'Looking in 78704',
        'first_referrer' => 'https://chatgpt.com/', 'landing_url' => 'http://acme.rightally.site/', 'return' => '/contact', 't' => Crypt::encryptString((string) (time() - 30))];

    $this->post($this->web.'/_lead', $form)->assertRedirect($this->web.'/contact?sent=1#contact');
    $lead = Lead::query()->sole();
    expect($lead->channel)->toBe('ai_search');

    $this->post($this->web.'/_lead', [...$form, 'email' => 'bot@x.test', 'website' => 'http://spam'])->assertRedirect($this->web.'/contact?sent=1'); // honeypot
    $this->post($this->web.'/_lead', [...$form, 'email' => 'fast@x.test', 't' => Crypt::encryptString((string) time())])->assertRedirect($this->web.'/contact?sent=1'); // too fast
    $this->post($this->web.'/_lead', [...$form, 'email' => 'forged@x.test', 't' => (string) (time() - 30)])->assertRedirect($this->web.'/contact?sent=1'); // unreadable trap
    expect(Lead::query()->count())->toBe(1);

    $this->get($this->web.'/contact?sent=1')->assertOk()->assertSee('Thank you. An agent will be in touch shortly.');
});

it('shows the HQ-managed Powered by credit, which brokerages cannot remove but HQ can switch off per brokerage', function (): void {
    makeSite($this);
    expect((string) $this->get($this->web.'/')->getContent())->toContain('href="https://rightally.io"')->toContain('Powered by RightAlly');

    $powered = app(PoweredBy::class);
    $powered->save(true, 'Website by RightAlly', 'https://rightally.io/websites', User::factory()->hqStaff()->make());
    expect((string) $this->get($this->web.'/')->getContent())->toContain('Website by RightAlly')->toContain('https://rightally.io/websites');

    $powered->setTenant($this->tenant->id, false);
    expect((string) $this->get($this->web.'/')->getContent())->not->toContain('Website by RightAlly');
    $powered->setTenant($this->tenant->id, null);
    $powered->save(false, 'Website by RightAlly', 'https://rightally.io/websites', User::factory()->hqStaff()->make());
    expect($powered->forTenant($this->tenant->id))->toBeNull();
    expect(fn () => $powered->save(true, 'x', 'http://insecure.test', User::factory()->hqStaff()->make()))->toThrow(ValidationException::class);
});

it('previews drafts with a signed link that expires, even before the site is live', function (): void {
    $site = makeSite($this, 'modern', false);
    $page = SitePage::query()->where('site_id', $site->id)->where('path', '/')->sole();
    $page->forceFill(['draft_sections' => [['type' => 'text', 'props' => ['title' => 'Draft headline', 'body' => 'Not yet live.']]]])->save();

    $url = (string) app(SiteSetup::class)->previewUrl($page);
    expect($url)->toStartWith('https://acme.rightally.site/_preview/'.$page->ulid.'?expires=');
    $path = (string) parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

    $this->get($this->web.$path)->assertOk()->assertSee('Draft headline')->assertHeader('X-Robots-Tag', 'noindex');
    $this->get($this->web.str_replace('signature=', 'signature=0', $path))->assertForbidden();
    $this->travel(2)->hours();
    $this->get($this->web.$path)->assertForbidden();
});

it('lets the website editor show the preview in a frame on the brokerage\'s own address, and marks the sections to click', function (): void {
    $site = makeSite($this, 'modern', false);
    $page = SitePage::query()->where('site_id', $site->id)->where('path', '/')->sole();
    $page->forceFill(['draft_sections' => [['type' => 'text', 'id' => 'text-ab12cd', 'props' => ['title' => 'Draft headline', 'body' => 'Not yet live.']]]])->save();
    $url = (string) app(SiteSetup::class)->previewUrl($page);
    $path = (string) parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

    $res = $this->get($this->web.$path)->assertOk();
    $csp = (string) ($res->headers->get('Content-Security-Policy') ?? $res->headers->get('Content-Security-Policy-Report-Only'));
    expect($res->headers->get('X-Frame-Options'))->toBeNull()->and($res->headers->get('X-Allow-Embed'))->toBeNull()
        ->and($csp)->toContain("frame-ancestors 'self'");
    foreach (TenantDomain::query()->where('tenant_id', $this->tenant->id)->pluck('host') as $host) {
        expect($csp)->toContain('https://'.$host);
    }
    expect((string) $res->getContent())->toContain('data-ra-block="text-ab12cd"')->toContain('rightally-builder');

    // The live website is never framed, and has no editing marks.
    $page->forceFill(['sections' => $page->draft_sections])->save();
    $site->forceFill(['status' => 'live', 'published_at' => now()])->save();
    $live = $this->get($this->web.'/')->assertOk();
    expect((string) $live->getContent())->not->toContain('data-ra-block')->not->toContain('rightally-builder')
        ->and($live->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
});

it('connects custom domains that serve the site once DNS is verified', function (): void {
    makeSite($this);
    $sites = app(SiteSetup::class);

    foreach (['not a domain', 'other.rightally.app', 'x.rightally.site', 'acme.rightally.site'] as $bad) {
        expect(fn () => $sites->addDomain($bad, $this->owner))->toThrow(ValidationException::class);
    }
    expect(fn () => $sites->addDomain('www.joneshomes.com', $this->agent))->toThrow(ValidationException::class);
    $d = $sites->addDomain('WWW.JonesHomes.com', $this->owner);
    expect($d->host)->toBe('www.joneshomes.com')->and($d->purpose)->toBe('website')->and($sites->liveHost())->toBe('acme.rightally.site');
    $this->get('http://www.joneshomes.com/')->assertNotFound(); // not verified yet

    $d->forceFill(['verification_status' => 'verified'])->save();
    app(DomainResolver::class)->forget('www.joneshomes.com');
    expect($sites->liveHost())->toBe('www.joneshomes.com');
    expect((string) $this->get('http://www.joneshomes.com/')->assertOk()->getContent())->toContain('<link rel="canonical" href="http://www.joneshomes.com/">');
});

it('lets owners and staff manage the website in Settings, and not agents', function (): void {
    assertPageContract($this->actingAs($this->owner)->get('/settings/website'), 'settings/website');
    $this->actingAs($this->agent)->get('/settings/website')->assertForbidden();

    $this->actingAs($this->owner)->post('/settings/website', ['template' => 'modern', 'info' => ['tagline' => 'Hi', 'state' => 'TX']])->assertSessionHasNoErrors();
    $site = Site::query()->sole();
    $this->actingAs($this->owner)->patch('/settings/website', ['template' => 'aurum', 'ai_crawlers' => false, 'brand' => ['primary' => '#112233', 'accent' => '#AABBCC', 'logo_url' => 'javascript:alert(1)'],
        'seo' => ['title_suffix' => 'Acme', 'description' => '<b>Austin</b> homes']])->assertSessionHasErrors('brand.logo_url');
    $this->actingAs($this->owner)->patch('/settings/website', ['template' => 'aurum', 'ai_crawlers' => false, 'brand' => ['primary' => '#112233', 'accent' => '#AABBCC'],
        'seo' => ['title_suffix' => 'Acme', 'description' => '<b>Austin</b> homes']])->assertSessionHasNoErrors();
    $site->refresh();
    expect($site->template)->toBe('aurum')->and($site->ai_crawlers)->toBeFalse()->and($site->brand['accent'])->toBe('#aabbcc')->and($site->seo['description'])->toBe('Austin homes');

    $this->actingAs($this->owner)->post('/settings/website/publish', ['live' => true])->assertSessionHasNoErrors();
    expect($site->refresh()->status)->toBe('live');
    $about = SitePage::query()->where('path', '/about')->sole();
    $this->actingAs($this->owner)->patch('/settings/website/pages/'.$about->ulid, ['title' => 'Our story', 'meta_description' => 'Who we are', 'noindex' => true, 'in_menu' => false])->assertSessionHasNoErrors();
    expect($about->refresh()->only(['title', 'noindex', 'in_menu']))->toBe(['title' => 'Our story', 'noindex' => true, 'in_menu' => false]);
    $home = SitePage::query()->where('path', '/')->sole();
    $this->actingAs($this->owner)->patch('/settings/website/pages/'.$home->ulid, ['title' => 'Home', 'noindex' => true, 'in_menu' => true]);
    expect($home->refresh()->noindex)->toBeFalse(); // the home page always stays indexable

    $preview = $this->actingAs($this->owner)->postJson('/settings/website/pages/'.$home->ulid.'/preview')->assertOk()->json('url');
    expect($preview)->toContain('/_preview/'.$home->ulid);
    $this->actingAs($this->owner)->post('/settings/website/domains', ['host' => 'www.acmehomes.com'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->get('/settings/website')->assertInertia(fn (Assert $p) => $p->component('settings/website')
        ->where('site.status', 'live')->where('hosts.1.host', 'www.acmehomes.com')->where('hosts.1.verified', false)->where('cname_target', 'sites.rightally.site'));
    expect(DB::table('audit_events')->where('action', 'sites.published')->exists())->toBeTrue();
});

it('lets HQ manage the Powered by credit for every brokerage', function (): void {
    makeSite($this);
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq')->assertSuccessful();
    $hq = 'http://app.rightally.io';
    $admin = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());

    assertPageContract($this->actingAs($admin)->get($hq.'/hq/sites'), 'hq/sites');
    $this->actingAs($admin)->get($hq.'/hq/sites')->assertInertia(fn (Assert $p) => $p->where('powered_by.enabled', true)->where('powered_by.text', 'Powered by RightAlly'));
    $this->actingAs($admin)->post($hq.'/hq/sites/powered-by', ['enabled' => true, 'text' => 'Built on RightAlly', 'url' => 'https://rightally.io/w'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post($hq.'/hq/sites/powered-by/'.$this->tenant->ulid, ['enabled' => false])->assertSessionHasNoErrors();
    expect(app(PoweredBy::class)->forTenant($this->tenant->id))->toBeNull();
    $this->actingAs($this->owner)->get('/hq/sites')->assertNotFound();
});

it('resolves the brokerage on public CRM endpoints without a pre-set tenant', function (): void {
    app(TenantContext::class)->forget();
    $this->postJson('/api/leads', ['name' => 'X', 'email' => 'x@x.test'], ['Authorization' => 'Bearer nope'])->assertUnauthorized();
});
