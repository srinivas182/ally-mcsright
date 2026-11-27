<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Models\Lead;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\FeedDelayedNotice;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Models\RulesProfile;
use Modules\Listings\Search\ListingSearch;
use Modules\Listings\Search\MarketStats;
use Modules\Listings\Search\PlainSearch;
use Modules\Listings\Sync\ListingSync;
use Modules\Listings\Sync\ResoMapper;
use Modules\Sites\Maps;
use Modules\Sites\SiteSetup;

require_once __DIR__.'/../Contracts/contract-helpers.php';

/* Sprint 17C-2a: website IDX — map search, listing pages, market pages, Mapbox token. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->web = 'http://acme.rightally.site';
    $sites = app(SiteSetup::class);
    $this->site = $sites->create('modern', ['phone' => '(512) 555-0100', 'area' => 'Austin'], $this->owner);
    $sites->publish($this->site, true, $this->owner);

    app()->instance(ResoFeed::class, $this->feed = new FakeResoFeed);
    $rules = RulesProfile::query()->create(['name' => 'ACTRIS', 'program' => 'idx', 'attribution_template' => 'Listing courtesy of {ListOfficeName}',
        'disclaimer' => 'Information deemed reliable but not guaranteed. Updated {LastUpdated}.', 'sold_allowed' => false, 'sold_delay_days' => 0, 'freshness_minutes' => 720]);
    $this->source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active', 'rules_profile_id' => $rules->id]);
    $this->feed->records = [
        idxHome(1), idxHome(2, ['PublicRemarks' => 'Sunny <script>alert(1)</script> corner lot.']), idxHome(3), idxHome(4), idxHome(5),
        idxHome(6, ['City' => 'Round Rock', 'PostalCode' => '78664', 'Latitude' => 30.50]),
    ];
    app(ListingSync::class)->run($this->source);
    $a = MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $this->source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($a, ['idx'], null, null, User::factory()->hqStaff()->make());
});

function idxHome(int $i, array $over = []): array
{
    return $over + ['ListingKey' => 'K'.$i, 'ListingId' => 'MLS'.$i, 'StandardStatus' => 'Active', 'PropertyType' => 'Residential', 'ListPrice' => 300000 + $i * 50000,
        'OriginalListPrice' => 300000 + $i * 50000, 'BedroomsTotal' => 2 + ($i % 3), 'BathroomsFull' => 2, 'BathroomsHalf' => $i % 2, 'LivingArea' => 1500 + $i * 100,
        'StreetNumber' => (string) (10 + $i), 'StreetName' => 'Oak St', 'City' => 'Austin', 'StateOrProvince' => 'TX', 'PostalCode' => $i % 2 ? '78704' : '78701',
        'Latitude' => 30.20 + $i / 100, 'Longitude' => -97.75, 'ListOfficeName' => 'Acme Realty', 'OnMarketDate' => '2026-05-'.str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT),
        'DaysOnMarket' => 10 + $i, 'ModificationTimestamp' => '2026-05-20T00:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).':00Z'];
}

function idxListing(string $key): Listing
{
    return Listing::query()->where('listing_key', $key)->sole();
}

it('renders a crawlable search page with filters in the address and every listing linked', function (): void {
    $html = (string) $this->get($this->web.'/homes')->assertOk()->assertHeader('Cache-Control', 'max-age=60, public, s-maxage=120, stale-while-revalidate=600')->getContent();
    $k1 = idxListing('K1');
    expect($html)->toContain('6 homes for sale')->toContain('<link rel="canonical" href="http://acme.rightally.site/homes">')
        ->toContain('content="index, follow, max-image-preview:large"')
        ->toContain('href="/homes/'.$k1->ulid.'/11-oak-st-austin-tx-78704"')->toContain('Listing courtesy of Acme Realty')
        ->toContain('Information deemed reliable but not guaranteed')->toContain('"@type":"SearchAction"')
        ->toContain('>Homes for sale</a>')->not->toContain('data-map-open'); // no token: list only

    $filtered = (string) $this->get($this->web.'/homes?q=78704&beds=3&sort=price_desc&utm_source=x')->assertOk()->getContent();
    expect($filtered)->toContain('2 homes for sale in 78704')->toContain('content="noindex, follow"')
        ->toContain('<link rel="canonical" href="http://acme.rightally.site/homes">')
        ->toContain('<option value="3" selected>3+ bd</option>');
});

it('answers the map with escaped result cards and pins, inside the map area or a drawn shape', function (): void {
    $all = $this->getJson($this->web.'/_search?pins=1')->assertOk()->assertHeader('X-Robots-Tag', 'noindex')->json();
    expect($all['total'])->toBe(6)->and($all['pins'])->toHaveCount(6)->and($all['pins'][0])->toHaveCount(5)->and($all['url'])->toBe('/homes');

    $box = $this->getJson($this->web.'/_search?pins=1&bounds=30.205,-97.80,30.235,-97.70')->json();
    expect($box['total'])->toBe(3)->and(array_column($box['pins'], 0))->toEqualCanonicalizing([idxListing('K1')->ulid, idxListing('K2')->ulid, idxListing('K3')->ulid]);

    // A drawn area around homes 4 and 5 (latitudes 30.24 and 30.25).
    $poly = $this->getJson($this->web.'/_search?pins=1&poly='.urlencode('30.235,-97.80;30.255,-97.80;30.255,-97.70;30.235,-97.70'))->json();
    expect($poly['total'])->toBe(2)->and(array_column($poly['pins'], 0))->toEqualCanonicalizing([idxListing('K4')->ulid, idxListing('K5')->ulid])
        ->and($poly['cards'])->toContain('data-ulid="'.idxListing('K4')->ulid.'"')->and($poly['heading'])->toBe('2 homes for sale');

    $triangle = [[30.20, -97.80], [30.30, -97.80], [30.25, -97.70]];
    expect(ListingSearch::pointInPolygon(30.25, -97.76, $triangle))->toBeTrue()
        ->and(ListingSearch::pointInPolygon(30.21, -97.72, $triangle))->toBeFalse()
        ->and(ListingSearch::clean(['poly' => '1,2;3,4'])['polygon'])->toBeNull()
        ->and(ListingSearch::clean(['poly' => 'x'])['polygon'])->toBeNull();
});

it('shows each home on its own page with structured data, MLS credit and a monthly estimate', function (): void {
    $l = idxListing('K2');
    $this->get($this->web.'/homes/'.$l->ulid)->assertRedirect($this->web.'/homes/'.$l->ulid.'/12-oak-st-austin-tx-78701')->assertStatus(301);
    $this->get($this->web.'/homes/'.$l->ulid.'/wrong-words')->assertStatus(301);

    $res = $this->get($this->web.'/homes/'.$l->ulid.'/12-oak-st-austin-tx-78701')->assertOk();
    $html = (string) $res->getContent();
    expect($html)->toContain('<title>12 Oak St, Austin, TX 78701 | $400,000 | 4 bd, 2 ba, 1,700 sq ft</title>')
        ->toContain('content="4 bd, 2 ba, 1,700 sq ft home for sale at $400,000 in Austin. Sunny alert(1) corner lot."')
        ->toContain('"@type":"RealEstateListing"')->toContain('"@type":"Offer","price":400000,"priceCurrency":"USD"')
        ->toContain('"@type":"SingleFamilyResidence"')->toContain('"unitCode":"FTK"')->toContain('"@type":"BreadcrumbList"')
        ->toContain('"item":"http://acme.rightally.site/market/austin-tx"')
        ->toContain('Listing courtesy of Acme Realty')->toContain('Information deemed reliable but not guaranteed')
        ->toContain('MLS# MLS2')->toContain('<strong>$235</strong> per sq ft')->toContain('Similar homes nearby')
        ->toContain('Sunny &lt;script&gt;alert(1)&lt;/script&gt; corner lot.')->not->toContain('<script>alert(1)</script>')
        ->toContain('name="listing" value="'.$l->ulid.'"')->not->toContain('api.mapbox.com/styles'); // no token: no static map
    // $400,000, 20% down, 6.5% for 30 years = $2,022.62 + tax $400 + insurance $150.
    expect($html)->toContain('<strong data-monthly>$2,573</strong>');

    // Another brokerage is not licensed for this MLS.
    $other = Tenant::factory()->withPlatformDomain()->create(['slug' => 'other']);
    $otherSite = app(TenantContext::class)->runAs($other, function () {
        $owner = User::factory()->owner()->create();
        $s = app(SiteSetup::class)->create('aurum', [], $owner);
        app(SiteSetup::class)->publish($s, true, $owner);

        return $s;
    });
    expect($otherSite->status)->toBe('live');
    $this->get('http://other.rightally.site/homes/'.$l->ulid)->assertNotFound();
    $this->get('http://other.rightally.site/homes')->assertOk()->assertSee('0 homes for sale');
});

it('adds the home to enquiries sent from its page, checking the listing itself', function (): void {
    $l = idxListing('K3');
    $form = ['name' => 'Jane Doe', 'email' => 'jane@x.test', 'message' => 'Can I see it Saturday?', 'listing' => $l->ulid, 'intent' => 'buy',
        'return' => '/homes/'.$l->ulid, 't' => Crypt::encryptString((string) (time() - 30))];
    $this->post($this->web.'/_lead', $form)->assertRedirect($this->web.'/homes/'.$l->ulid.'?sent=1#contact');
    $lead = Lead::query()->sole();
    expect($lead->message)->toStartWith("About 13 Oak St Austin TX 78704 (MLS# MLS3), listed at \$450,000.\nCan I see it Saturday?")
        ->and($lead->price_max ?? $lead->price_min)->toBe(450000);

    $this->post($this->web.'/_lead', [...$form, 'email' => 'sam@x.test', 'listing' => str_repeat('Z', 26)]);
    expect(Lead::query()->latest('id')->first()->message)->toBe('Can I see it Saturday?');
});

it('publishes market pages with live figures, an answer-first summary and FAQ structured data', function (): void {
    $html = (string) $this->get($this->web.'/market/austin-tx')->assertOk()->getContent();
    expect($html)->toContain('Austin, TX real estate market')
        ->toContain('As of June 1, 2026, there are 5 homes for sale in Austin, TX with a median list price of $450,000')
        ->toContain('"@type":"FAQPage"')->toContain('What is the median home price in Austin, TX?')
        ->toContain('href="/market/78704"')->toContain('"item":"http://acme.rightally.site/market"');

    $zip = (string) $this->get($this->web.'/market/78704')->assertOk()->getContent();
    expect($zip)->toContain('78704 (Austin, TX) real estate market')->toContain('href="/market/austin-tx"')->toContain('3 homes for sale');

    $this->get($this->web.'/market/round-rock-tx')->assertNotFound(); // one home: too thin to publish
    $this->get($this->web.'/market/nowhere-tx')->assertNotFound();
    expect((string) $this->get($this->web.'/market')->assertOk()->getContent())->toContain('href="/market/austin-tx"')->toContain('href="/market/78704"')
        ->not->toContain('round-rock')->not->toContain('href="/market/78701"'); // two homes: too thin

    $m = app(MarketStats::class)->forPlace($this->tenant->id, 'austin-tx');
    expect($m)->not->toBeNull()->and($m['active'])->toBe(5)->and($m['median_price'])->toBe(450000)->and($m['median_dom'])->toBe(13)->and($m['zips'])->toBe(['78704']);
});

it('lists homes and market reports in the sitemap and llms.txt', function (): void {
    $sitemap = (string) $this->get($this->web.'/sitemap.xml')->getContent();
    expect($sitemap)->toContain('<loc>http://acme.rightally.site/homes</loc>')->toContain('<loc>http://acme.rightally.site/market/austin-tx</loc>')
        ->toContain('<loc>http://acme.rightally.site/homes/'.idxListing('K1')->ulid.'/11-oak-st-austin-tx-78704</loc>');
    $llms = (string) $this->get($this->web.'/llms.txt')->getContent();
    expect($llms)->toContain('## Market reports')->toContain('[Austin, TX](http://acme.rightally.site/market/austin-tx): 5 homes for sale, median list price $450,000')
        ->toContain('[Homes for sale](http://acme.rightally.site/homes)');
});

it('links listings to the live website from the back office, alerts and texts', function (): void {
    $l = idxListing('K1');
    expect(ListingSearch::publicUrl($l->ulid, '11 Oak St Austin TX'))->toBe('https://acme.rightally.site/homes/'.$l->ulid.'/11-oak-st-austin-tx');
    $setup = app(SiteSetup::class);
    $setup->publish($this->site->refresh(), false, $this->owner);
    expect(ListingSearch::publicUrl($l->ulid))->toBeNull();
    $this->get($this->web.'/homes')->assertOk()->assertSee('Coming soon');
});

it('shows maps once HQ adds a Mapbox public token, and refuses secret tokens', function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq')->assertSuccessful();
    $admin = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $hq = 'http://app.rightally.io';

    $this->actingAs($admin)->post($hq.'/hq/sites/maps', ['mapbox_token' => 'sk.eyJ1Ijoic2VjcmV0In0.abc'])->assertSessionHasErrors('mapbox_token');
    $this->actingAs($admin)->post($hq.'/hq/sites/maps', ['mapbox_token' => 'pk.eyJ1IjoicmlnaHRhbGx5In0.Zm9vYmFy'])->assertSessionHasNoErrors();
    $page = $this->actingAs($admin)->get($hq.'/hq/sites');
    assertPageContract($page, 'hq/sites');
    $page->assertInertia(fn (Assert $p) => $p->where('maps.configured', true)->where('maps.hint', 'YmFy'))->assertDontSee('pk.eyJ1IjoicmlnaHRhbGx5In0');
    expect(DB::connection(Connections::control())->table('hq_site_branding')->value('mapbox_token'))->not->toContain('pk.');

    app()->forgetScopedInstances();
    $search = (string) $this->get($this->web.'/homes')->getContent();
    // The map starts only when a visitor asks for it; the token comes from POST /_map (counted against the allowance).
    expect($search)->toContain('data-mapbox="1"')->toContain('data-map-open')->toContain('Draw an area')->not->toContain('pk.eyJ1IjoicmlnaHRhbGx5In0');
    $this->postJson($this->web.'/_map')->assertOk()->assertJson(['ok' => true, 'token' => 'pk.eyJ1IjoicmlnaHRhbGx5In0.Zm9vYmFy']);
    $l = idxListing('K1');
    expect((string) $this->get($this->web.'/homes/'.$l->ulid.'/11-oak-st-austin-tx-78704')->getContent())
        ->toContain('https://api.mapbox.com/styles/v1/mapbox/streets-v12/static/pin-l+c9a45c(-97.75,30.21)');

    expect(fn () => app(Maps::class)->save('not-a-token', $admin))->toThrow(ValidationException::class);
    app(Maps::class)->save('', $admin);
    expect(app(Maps::class)->token())->toBeNull();
});

/* IX1: designs 6–10 and feed freshness (specs/websites/16). */

it('offers ten website designs, each with its own styles', function (): void {
    $templates = config('sites.templates');
    expect(array_keys($templates))->toContain('estate', 'nordic', 'heritage', 'summit', 'atrium')->and(count($templates))->toBeGreaterThanOrEqual(10);
    $css = (string) file_get_contents(public_path('site-assets/site.css'));
    foreach (array_keys($templates) as $key) {
        expect($css)->toContain('.theme-'.$key.' {');
        expect($templates[$key]['brand']['primary'])->toMatch('/^#[0-9a-f]{6}$/i');
    }

    $this->actingAs($this->owner)->patch('/settings/website', ['template' => 'estate', 'ai_crawlers' => true, 'brand' => ['primary' => '#1f3a33', 'accent' => '#b08d57']])->assertSessionHasNoErrors();
    $html = (string) $this->get($this->web.'/homes')->assertOk()->getContent();
    expect($html)->toContain('class="theme-estate ')->toContain('Playfair+Display')->toContain('site.css?v=16');
});

it('tells visitors and brokerage owners when the MLS feed runs late', function (): void {
    expect((string) $this->get($this->web.'/homes')->getContent())->not->toContain('data-stale');

    $this->travel(13)->hours();
    expect((string) $this->get($this->web.'/homes')->assertOk()->getContent())->toContain('data-stale')->toContain('Listing updates are running a little late');

    $this->artisan('listings:reconcile', ['--freshness-only' => true])->assertSuccessful();
    Notification::assertSentTo($this->owner, FeedDelayedNotice::class, fn ($n): bool => $n->mls === 'ACTRIS' && $n->minutes >= 60);
    $mail = (new FeedDelayedNotice('ACTRIS', 780))->toMail($this->owner);
    expect($mail->subject)->toBe('Your ACTRIS listings are running late')->and(implode(' ', $mail->introLines))->toContain('13 hours');

    // Once every six hours at most.
    $this->artisan('listings:reconcile', ['--freshness-only' => true])->assertSuccessful();
    Notification::assertSentToTimes($this->owner, FeedDelayedNotice::class, 1);
});

/* IX2: designs 11–15, plain-words search in English and Spanish, MLS provider presets (specs/websites/17). */

it('offers fifteen designs', function (): void {
    expect(array_keys(config('sites.templates')))->toContain('villa', 'loft', 'meadow', 'mesa', 'ember')->and(count(config('sites.templates')))->toBeGreaterThanOrEqual(15);
});

it('reads plain-words searches into filters, in English and Spanish', function (): void {
    $p = PlainSearch::parse('3 bed homes under 500k in Austin with a pool and a view');
    expect($p['filters'])->toBe(['max_price' => '500000', 'beds' => '3', 'pool' => '1', 'q' => 'Austin'])->and($p['not_filtered'])->toBe(['view'])->and($p['language'])->toBe('en');
    // IDX10: home facts from the MLS are real filters now.
    expect(PlainSearch::parse('waterfront condo with a 2 car garage in Mobile')['filters'])->toBe(['garage' => '2', 'waterfront' => '1', 'sub' => 'condo', 'q' => 'Mobile'])
        ->and(PlainSearch::parse('new construction on 5 acres near Baton Rouge')['filters'])->toBe(['new_build' => '1', 'acres' => '5', 'q' => 'Baton Rouge'])
        ->and(PlainSearch::parse('homes in Lake Charles')['filters'])->toBe(['q' => 'Lake Charles']);

    $es = PlainSearch::parse('casa de 3 recámaras y 2 baños hasta 400 mil en San Antonio');
    expect($es['filters'])->toBe(['max_price' => '400000', 'beds' => '3', 'baths' => '2', 'q' => 'San Antonio'])->and($es['language'])->toBe('es')
        ->and(PlainSearch::say($es['understood'], 'es'))->toBe(['hasta $400k', '3+ recámaras', '2+ baños', 'en San Antonio']);

    expect(PlainSearch::parse('2br for rent under 2500 in Dallas')['filters'])->toMatchArray(['max_price' => '2500', 'types' => 'Residential Lease']);
    expect(PlainSearch::parse('homes between $300k and $1.2m in 78704')['filters'])->toBe(['min_price' => '300000', 'max_price' => '1200000', 'q' => '78704']);
    expect(PlainSearch::looksPlain('Round Rock'))->toBeFalse()->and(PlainSearch::looksPlain('three bedrooms in Austin'))->toBeTrue();
});

it('turns a plain-words search into the same crawlable filter page', function (): void {
    $r = $this->get($this->web.'/homes?q='.rawurlencode('3 bed homes under 500k in Austin with a pool and a view'));
    $r->assertRedirect();
    $to = (string) $r->headers->get('Location');
    expect($to)->toContain('beds=3')->toContain('max_price=500000')->toContain('q=Austin')->toContain('pool=1')->toContain('heard=');

    $html = (string) $this->get($this->web.'/homes?'.parse_url($to, PHP_URL_QUERY))->assertOk()->getContent();
    expect($html)->toContain('We searched for')->toContain('under $500k · 3+ bedrooms · a pool · in Austin')->toContain('filter by view')
        ->toContain('content="noindex'); // a filtered page is not a canonical page

    $es = (string) $this->followingRedirects()->get($this->web.'/homes?q='.rawurlencode('casa de 3 recámaras hasta 400 mil en Austin'))->assertOk()->getContent();
    expect($es)->toContain('Buscamos')->toContain('hasta $400k')->toContain('lang="es"');

    // A place name is still just a place.
    $this->get($this->web.'/homes?q=Austin')->assertOk();
});

it('fills in the MLS Grid and Trestle addresses for HQ', function (): void {
    expect(MlsSource::PRESETS['trestle']['token_url'])->toStartWith('https://')->and(MlsSource::PRESETS['mls_grid']['base_url'])->toBe('https://api.mlsgrid.com/v2');
});

it('keeps home facts from the MLS and filters on them (IDX10)', function (): void {
    $m = ResoMapper::map(idxHome(9, ['PoolPrivateYN' => true, 'GarageSpaces' => 2.0, 'WaterfrontYN' => 'N', 'NewConstructionYN' => null]), 'actris');
    expect($m['pool'])->toBeTrue()->and($m['garage_spaces'])->toBe(2)->and($m['waterfront'])->toBeFalse()->and($m['new_construction'])->toBeNull();

    $this->feed->records = [idxHome(1, ['PoolPrivateYN' => true, 'GarageSpaces' => 2, 'ModificationTimestamp' => '2026-05-30T00:00:00Z']),
        idxHome(3, ['WaterfrontYN' => true, 'GarageSpaces' => 1, 'ModificationTimestamp' => '2026-05-30T00:01:00Z'])];
    app(ListingSync::class)->run($this->source->refresh());
    $search = app(ListingSearch::class);
    expect(array_column($search->search($this->tenant->id, ['pool' => '1'])['results'], 'mls_number'))->toBe(['MLS1'])
        ->and(array_column($search->search($this->tenant->id, ['garage' => '2'])['results'], 'mls_number'))->toBe(['MLS1'])
        ->and(array_column($search->search($this->tenant->id, ['waterfront' => '1'])['results'], 'mls_number'))->toBe(['MLS3']);
});
