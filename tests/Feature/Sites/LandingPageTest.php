<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Modules\Roster\Verification\ServiceVerifier;
use Modules\Sites\Content\DemoSite;
use Modules\Sites\Models\SitePage;
use Modules\Sites\SiteSetup;
use Modules\Sites\Support\Designs;

/*
| LP1: brokerages and agents without an MLS board get a landing page website: one polished page about them (how they
| help, the areas they serve, a home value request, reviews), plus About and Contact, in one of five designs. No home
| search, listings, market reports or visitor accounts; those addresses go home.
*/

beforeEach(function (): void {
    Notification::fake();
});

it('offers five landing page designs, apart from the fifteen full website designs', function (): void {
    expect(Designs::landing())->toBe(['signature', 'advisor', 'shoreline', 'legacy', 'studio'])
        ->and(Designs::full())->toHaveCount(15)->not->toContain('signature');
});

it('builds a landing page website without home search, listings or the MLS block', function (): void {
    $this->onTenantHost('acme');
    $owner = User::factory()->owner()->create();
    $site = app(SiteSetup::class)->create('shoreline', ['name' => 'Acme Realty', 'area' => 'Greater Houston', 'state' => 'TX', 'licence' => 'TX 0654321'], $owner);
    $site->update(['status' => 'live']);
    expect(SitePage::query()->where('site_id', $site->id)->orderBy('position')->pluck('path')->all())->toBe(['/', '/about', '/join-us', '/contact']);

    $web = 'http://acme.rightally.site';
    $html = (string) $this->get($web.'/')->assertOk()->getContent();
    expect($html)->toContain('class="theme-shoreline')->toContain('How we can help')->toContain('Areas we serve')->toContain('Houston, Austin, Dallas and San Antonio')
        ->toContain('TX 0654321')->toContain('href="/home-value"')
        ->not->toContain('href="/homes"')->not->toContain('Search homes')->not->toContain('class="mls-disclosure"')->not->toContain('data-account>Sign in');
    $this->get($web.'/homes')->assertRedirect('/');
    $this->get($web.'/market/houston-tx')->assertRedirect('/');
    $this->get($web.'/about')->assertOk()->assertSee('About Acme Realty');
});

it('previews each landing design on the demo website with the visitor\'s name', function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    Http::fake();
    app(DemoSite::class)->install();
    $html = (string) $this->get('http://demo.rightally.site/?design=legacy&name=Oak+Realty&area=Greater+Houston&state=TX')->assertOk()->getContent();
    expect($html)->toContain('class="theme-legacy')->toContain('<h1>Oak Realty</h1>')->toContain('How we can help')
        ->not->toContain('Featured homes')->not->toContain('href="/homes"');
});

it('downloads the Texas and Florida licence lists every day and keeps the last list when a download fails (LV1)', function (): void {
    Http::fake([
        'data.texas.gov/*' => Http::response("License Type,License Number,Full Name,Last Name,License Status,License Expiration Date\nSAL,0654321,Dana Smith,Smith,Active,01/31/2028\n"),
        'www2.myfloridalicense.com/*' => Http::response("2501,\"SMITH, PAT\",,SL,1 Main St,,,Miami,FL,33101,Dade,SL3456789,Current,Active,01/01/2020,01/01/2024,03/31/2027,,,,\n"),
    ]);
    $this->artisan('roster:licence-sync')->assertSuccessful();
    $verifier = app(ServiceVerifier::class);
    expect($verifier->lookup('TX', '654321', 'Smith')['status'])->toBe('active')
        ->and($verifier->lookup('FL', 'SL3456789', 'Smith'))->toMatchArray(['status' => 'active', 'last_name' => 'SMITH', 'expires_on' => '2027-03-31']);

    Http::fake(['*' => Http::response('nothing here', 200)]);
    $this->artisan('roster:licence-sync --state=TX')->assertFailed();
    expect($verifier->lookup('TX', '0654321', 'Smith')['status'])->toBe('active'); // last list kept
});
