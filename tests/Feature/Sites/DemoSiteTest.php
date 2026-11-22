<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Idx\DemoListings;
use Modules\Listings\Samples\SampleHomes;
use Modules\Sites\Content\DemoSite;
use Modules\Sites\Content\DemoView;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;

/*
| TP4: the design preview at sign-up is a complete demo website (every page, search, property pages, blog) in the
| design being previewed, with the visitor's own name and area. Nothing is saved; it is never indexed or cached.
*/

beforeEach(function (): void {
    Notification::fake();
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
});

it('creates the demo website once, live, with every starter page and no photo of its own in the home banner', function (): void {
    $this->artisan('sites:demo-site')->assertSuccessful();
    $this->artisan('sites:demo-site')->assertSuccessful();
    $tenant = Tenant::query()->where('slug', DemoSite::SLUG)->sole();
    $site = app(TenantContext::class)->runAs($tenant, fn () => Site::query()->where('kind', 'brokerage')->sole());
    expect($site->status)->toBe('live')->and($site->ai_crawlers)->toBeFalse();
    $pages = app(TenantContext::class)->runAs($tenant, fn () => SitePage::query()->where('site_id', $site->id)->pluck('sections', 'path'));
    expect($pages->keys()->all())->toContain('/', '/buy', '/sell', '/communities', '/about', '/contact')
        ->and($pages['/'][0]['props']['image'])->toBeNull();
    expect(DemoSite::previewUrl('aurum', 'Lone Star', 'Greater Houston', 'TX'))->toBe('https://demo.rightally.site/?design=aurum&name=Lone+Star&area=Greater+Houston&state=TX');
});

it('shows the demo in the chosen design with the visitor\'s name and area on every page, remembered across links, never indexed', function (): void {
    app(DemoSite::class)->install();
    $web = 'http://demo.rightally.site';

    $first = $this->get($web.'/?design=aurum&name=Lone+Star+%3Cb%3ERealty%3C%2Fb%3E&area=Greater+Houston&state=TX')->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertCookie(DemoView::COOKIE);
    expect($first->headers->get('Cache-Control'))->toContain('no-store');
    $html = (string) $first->getContent();
    expect($html)->toContain('class="theme-aurum hdr-centered')->toContain('Find your place in Greater Houston')->toContain('Lone Star Realty')
        ->not->toContain('<b>Realty')->not->toContain(DemoView::NAME)
        ->toContain('--hero: url('); // Aurum's own banner photo

    // A link inside the demo keeps the choice (from the cookie).
    $cookie = $first->headers->getCookies()[0]->getValue();
    $buy = (string) $this->withUnencryptedCookie(DemoView::COOKIE, $cookie)->get($web.'/buy')->assertOk()->getContent();
    expect($buy)->toContain('class="theme-aurum')->toContain('Buying a home in Greater Houston');

    // Nothing was saved: the demo website itself is unchanged.
    $tenant = Tenant::query()->where('slug', DemoSite::SLUG)->sole();
    expect(app(TenantContext::class)->runAs($tenant, fn () => Site::query()->where('kind', 'brokerage')->value('template')))->toBe('modern');
});

it('shows the sample homes of the state being previewed, only for that request', function (): void {
    config(['listings.sample_homes' => true]);
    Http::fake(['images.pexels.com/*' => Http::response('not a photo', 200)]); // photos are not what this checks
    app(SampleHomes::class)->install();
    config(['listings.sample_state' => 'FL']);
    expect(app(DemoListings::class)->sourcesFor(999))->toBe(SampleHomes::sourceIds('FL'));
    config(['listings.sample_state' => null]);
    expect(app(DemoListings::class)->sourcesFor(999))->toEqualCanonicalizing(SampleHomes::sourceIds());
});

it('sends the old one-page preview to the demo website once it exists', function (): void {
    $this->get('https://app.rightally.io/start/designs/estate?name=Oak&area=Austin')->assertOk(); // before: one page in memory
    app(DemoSite::class)->install();
    $this->get('https://app.rightally.io/start/designs/estate?name=Oak&area=Austin&state=tx')
        ->assertRedirect('https://demo.rightally.site/?design=estate&name=Oak&area=Austin&state=TX');
});
