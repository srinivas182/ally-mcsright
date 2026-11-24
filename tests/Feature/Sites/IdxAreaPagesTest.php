<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\Connections;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Idx\Subscriptions;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Models\RulesProfile;
use Modules\Listings\Search\MarketStats;
use Modules\Listings\Sync\ListingSync;
use Modules\Sites\SiteSetup;

/*
| IDX9: automatic area pages for cities, neighborhoods and ZIP codes; the brokerage's own words on any of them, or a
| page switched off; and never indexed while the website shows sample listings only.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    giveLicence($this->tenant); // LV2: boards need an active licence in their state
    $this->owner = User::factory()->owner()->create();
    $this->web = 'http://acme.rightally.site';
    $sites = app(SiteSetup::class);
    $sites->publish($sites->create('modern', ['phone' => '(512) 555-0100', 'area' => 'Austin'], $this->owner), true, $this->owner);

    app()->instance(ResoFeed::class, $feed = new FakeResoFeed);
    $rules = RulesProfile::query()->create(['name' => 'ACTRIS', 'program' => 'idx', 'attribution_template' => 'Listing courtesy of {ListOfficeName}',
        'disclaimer' => 'Information deemed reliable but not guaranteed.', 'sold_allowed' => false, 'sold_delay_days' => 0, 'freshness_minutes' => 720]);
    $this->source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active', 'rules_profile_id' => $rules->id]);
    $home = fn (int $i, ?string $sub): array => ['ListingKey' => 'K'.$i, 'ListingId' => 'MLS'.$i, 'StandardStatus' => 'Active', 'PropertyType' => 'Residential',
        'ListPrice' => 400000 + $i * 10000, 'LivingArea' => 1800, 'StreetNumber' => (string) (10 + $i), 'StreetName' => 'Oak St', 'City' => 'Austin', 'StateOrProvince' => 'TX',
        'PostalCode' => '78704', 'SubdivisionName' => $sub, 'Latitude' => 30.24, 'Longitude' => -97.75, 'ListOfficeName' => 'Acme Realty', 'OnMarketDate' => '2026-05-10',
        'DaysOnMarket' => 12, 'ModificationTimestamp' => '2026-05-20T00:0'.$i.':00Z'];
    $feed->records = [$home(1, 'TRAVIS HEIGHTS'), $home(2, 'TRAVIS HEIGHTS'), $home(3, 'TRAVIS HEIGHTS'), $home(4, 'Zilker'), $home(5, 'Zilker'), $home(6, null)];
    app(ListingSync::class)->run($this->source);
    $a = MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $this->source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($a, ['idx'], null, null, User::factory()->hqStaff()->make());
});

it('makes a page for every neighborhood with enough homes, linked from its city and the sitemap', function (): void {
    expect(MarketStats::areaSlug('TRAVIS HEIGHTS', 'Austin', 'TX'))->toBe('travis-heights--austin-tx')
        ->and(MarketStats::areaName('TRAVIS HEIGHTS'))->toBe('Travis Heights');

    $this->get($this->web.'/market')->assertOk()->assertSee('Neighborhoods')->assertSee('Travis Heights, Austin')->assertDontSee('Zilker'); // 2 homes: too few
    $this->get($this->web.'/market/travis-heights--austin-tx')->assertOk()
        ->assertSee('Travis Heights, Austin real estate market')->assertSee('<meta name="robots" content="index, follow', false)
        ->assertSee('href="/market/austin-tx"', false);
    $this->get($this->web.'/market/austin-tx')->assertOk()->assertSee('Neighborhoods in Austin')->assertSee('/market/travis-heights--austin-tx', false);
    $this->get($this->web.'/market/zilker--austin-tx')->assertNotFound();
    $this->get($this->web.'/sitemap.xml')->assertSee('/market/travis-heights--austin-tx', false);
});

it('lets the brokerage add its own words to an area or switch the page off', function (): void {
    $this->actingAs($this->owner)->get('http://acme.rightally.app/settings/website/areas')->assertInertia(fn (Assert $page) => $page
        ->component('settings/website-areas')->where('sample', false)->where('areas', fn ($areas) => collect($areas)->contains('slug', 'travis-heights--austin-tx')));

    $this->actingAs($this->owner)->put('http://acme.rightally.app/settings/website/areas/travis-heights--austin-tx', ['intro' => 'A family-friendly area.', 'hidden' => false])
        ->assertSessionHasErrors('intro'); // Fair Housing
    $this->actingAs($this->owner)->put('http://acme.rightally.app/settings/website/areas/travis-heights--austin-tx',
        ['intro' => "Tree-lined streets a short walk from South Congress.\n\nBungalows from the 1930s sit next to new builds.", 'hidden' => false])->assertSessionHasNoErrors();
    $this->get($this->web.'/market/travis-heights--austin-tx')->assertSee('Tree-lined streets a short walk from South Congress.')->assertSee('Bungalows from the 1930s');

    $this->actingAs($this->owner)->put('http://acme.rightally.app/settings/website/areas/78704', ['intro' => null, 'hidden' => true])->assertSessionHasNoErrors();
    $this->get($this->web.'/market/78704')->assertNotFound();
    $this->get($this->web.'/sitemap.xml')->assertDontSee('/market/78704', false);

    $agent = User::factory()->create();
    $this->actingAs($agent)->put('http://acme.rightally.app/settings/website/areas/austin-tx', ['intro' => 'Mine', 'hidden' => false])->assertForbidden();
});

it('keeps area pages and sample listings out of search engines while the website shows sample listings only', function (): void {
    $this->source->update(['is_demo' => true]);
    MlsAuthorization::query()->delete();
    DB::connection(Connections::control())->table('idx_boards')->where('code', 'HAR')->update(['visibility' => 'public', 'monthly_fee_cents' => 5_000]);
    $har = (string) DB::connection(Connections::control())->table('idx_boards')->where('code', 'HAR')->value('ulid');
    app(Subscriptions::class)->add($this->tenant->id, $har, $this->owner);
    Cache::flush();

    $this->get($this->web.'/market/austin-tx')->assertOk()->assertSee('sample listings')->assertSee('<meta name="robots" content="noindex', false);
    $sitemap = $this->get($this->web.'/sitemap.xml')->assertOk();
    $sitemap->assertDontSee('/market/', false)->assertDontSee('/homes/', false);
});
