<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Search\ListingSearch;
use Modules\Listings\Sync\ListingSync;
use Modules\Movewise\Models\Partner;
use Modules\Movewise\Models\State;
use Modules\Movewise\Movewise;

/* Movewise MW1: HQ's consumer site — its own host (other domains redirect), states switched on only with a broker
   of record, the legal checklist and a live MLS feed; search, each home with its true monthly cost, state pages,
   legal pages with Texas notices, and sitemaps. */

const MW = 'https://movewisehomes.com';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    config(['core.hq_host' => 'app.rightally.io', 'movewise.host' => 'movewisehomes.com', 'listings.cdn_url' => 'https://cdn.movewise.test']);
    $this->artisan('tenants:bootstrap-hq');
    $this->hqTenant = Tenant::query()->where('type', 'hq')->sole();
    $this->staff = app(TenantContext::class)->runAs($this->hqTenant, fn () => User::factory()->hqStaff()->create());
    app(TenantContext::class)->forget();
    app()->instance(ResoFeed::class, $feed = new FakeResoFeed);
    $this->source = MlsSource::query()->create(['key' => 'har', 'name' => 'HAR', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    $home = fn (string $key, string $state, int $price, string $city): array => ['ListingKey' => $key, 'ListingId' => 'M'.$key, 'StandardStatus' => 'Active', 'PropertyType' => 'Residential',
        'ListPrice' => $price, 'BedroomsTotal' => 3, 'BathroomsFull' => 2, 'LivingArea' => 1900, 'StreetNumber' => '12', 'StreetName' => 'Oak St', 'City' => $city, 'StateOrProvince' => $state,
        'PostalCode' => '77001', 'Latitude' => 29.7, 'Longitude' => -95.3, 'ListAgentMlsId' => 'A1', 'ListOfficeName' => 'Bayou Realty', 'ModificationTimestamp' => '2026-05-31T14:30:00Z'];
    $feed->records = [$home('H1', 'TX', 400000, 'Houston'), $home('H2', 'LA', 250000, 'Shreveport')];
    app(ListingSync::class)->run($this->source);
    $this->partner = Partner::query()->create(['name' => 'Lone Star Partners LLC', 'broker_name' => 'Pat Broker', 'licence_number' => '9001234', 'licence_state' => 'TX',
        'phone' => '713-555-0100', 'email' => 'broker@lonestar.test', 'iabs_url' => 'https://lonestar.test/iabs.pdf', 'contract_signed_on' => '2026-05-01', 'status' => 'active']);
    $this->tx = State::query()->findOrFail('TX');
});

function txLive(object $t): void
{
    $auth = app(MlsAccess::class)->request($t->hqTenant->id, $t->source, 'B-LS1', [], 'Movewise TX', $t->staff);
    app(MlsAccess::class)->approve($auth, ['idx'], null, null, $t->staff);
    $t->tx->forceFill(['partner_id' => $t->partner->id, 'checklist' => array_map(fn (): array => ['done' => true], Movewise::CHECKLIST)])->save();
    app(Movewise::class)->switchState($t->tx->refresh(), true, $t->staff);
}

it('is its own site: other domains redirect permanently keeping the path, and it says "coming soon" until a state is live', function (): void {
    $this->get('https://movewise.homes/homes?q=houston')->assertStatus(301)->assertRedirect('https://movewisehomes.com/homes?q=houston');
    $this->get('https://www.movewiserealestate.com/texas')->assertRedirect('https://movewisehomes.com/texas');
    $this->get(MW.'/')->assertOk()->assertSee('Home search without the spam')->assertSee('noindex', false)->assertCookieMissing('laravel_session');
    $this->get(MW.'/robots.txt')->assertOk()->assertSee('Sitemap: https://movewisehomes.com/sitemap.xml');
    $this->get(MW.'/how-movewise-is-paid')->assertOk()->assertSee('Agents pay us for introductions');
    $this->get(MW.'/nope/nothing')->assertNotFound();
});

it('switches a state on only with a broker of record, the whole legal checklist and a live MLS feed', function (): void {
    $hq = 'http://app.rightally.io';
    $as = fn () => $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()]);
    $as()->post($hq.'/hq/movewise/states/TX/switch', ['on' => true])->assertSessionHasErrors('state');
    $la = Partner::query()->create(['name' => 'Bayou', 'broker_name' => 'B', 'licence_number' => '1', 'licence_state' => 'LA', 'status' => 'active']);
    $as()->post($hq.'/hq/movewise/states/TX/partner', ['partner' => $la->ulid])->assertSessionHasErrors('partner');
    $as()->post($hq.'/hq/movewise/states/TX/partner', ['partner' => $this->partner->ulid])->assertSessionHasNoErrors();
    foreach (array_keys(Movewise::CHECKLIST) as $item) {
        $as()->post($hq.'/hq/movewise/states/TX/checklist', ['item' => $item, 'done' => true])->assertSessionHasNoErrors();
    }
    expect(app(Movewise::class)->missing($this->tx->refresh()))->toBe(['A live MLS feed covering Texas']);
    $as()->post($hq.'/hq/movewise/states/TX/feeds', ['mls' => $this->source->ulid, 'broker_mls_id' => 'B-LS1'])->assertSessionHasNoErrors();
    $auth = MlsAuthorization::query()->where('tenant_id', $this->hqTenant->id)->sole();
    expect($auth->only(['status', 'broker_mls_id']))->toBe(['status' => 'pending', 'broker_mls_id' => 'B-LS1']);
    app(MlsAccess::class)->approve($auth, ['idx'], null, null, $this->staff);
    $as()->post($hq.'/hq/movewise/states/TX/switch', ['on' => true])->assertSessionHasNoErrors();
    expect($this->tx->refresh()->on)->toBeTrue();
    $as()->get($hq.'/hq/movewise')->assertInertia(fn (Assert $p) => $p->component('hq/movewise')->where('states.3.code', 'TX')->where('states.3.on', true)
        ->where('states.3.feeds.0.status', 'active'));

    // Taking back a checklist item, or the partner going inactive, switches the state off.
    $as()->post($hq.'/hq/movewise/states/TX/checklist', ['item' => 'tcpa', 'done' => false]);
    expect($this->tx->refresh()->on)->toBeFalse();
});

it('shows only live states\' homes, each with the true monthly cost, the listing brokerage and the Texas notices', function (): void {
    txLive($this);
    $tx = Listing::query()->where('listing_key', 'H1')->sole();
    $la = Listing::query()->where('listing_key', 'H2')->sole();
    $this->get(MW.'/')->assertOk()->assertSee('Find your next home')->assertSee('12 Oak St')->assertDontSee('Shreveport')
        ->assertSee('Texas Real Estate Commission Information About Brokerage Services')->assertSee('https://lonestar.test/iabs.pdf', false)
        ->assertSee('Texas Real Estate Commission Consumer Protection Notice')->assertSee('Lone Star Partners LLC, licence 9001234');
    $this->get(MW.'/homes?q=Houston')->assertOk()->assertSee('1 home')->assertSee('noindex', false);
    $this->get(MW.'/homes?state=LA')->assertOk()->assertSee('12 Oak St'); // LA is off: the live states instead

    $page = $this->get(MW.'/homes/'.$tx->ulid.'/12-oak-st-houston-tx-77001')->assertOk()
        ->assertSee('$400,000')->assertSee('What it really costs each month')->assertSee('Loan payment')->assertSee('Mortgage insurance')
        ->assertSee('Bayou Realty')->assertSee('"@type":"RealEstateListing"', false)->assertSee('<link rel="canonical" href="https://movewisehomes.com/homes/'.$tx->ulid.'/12-oak-st-houston-tx-77001">', false)
        ->assertSee('Last updated');
    expect($page->headers->get('Cache-Control'))->toContain('public');
    $this->get(MW.'/homes/'.$la->ulid)->assertNotFound();

    $this->get(MW.'/texas')->assertOk()->assertSee('Homes for sale in Texas')->assertSee('Houston (1)')->assertSee('"@type":"FAQPage"', false);
    $this->get(MW.'/louisiana')->assertNotFound();
    $this->get(MW.'/sitemap.xml')->assertOk()->assertSee('https://movewisehomes.com/sitemaps/tx.xml')->assertDontSee('sitemaps/la.xml');
    $this->get(MW.'/sitemaps/tx.xml')->assertOk()->assertSee('/homes/'.$tx->ulid.'/12-oak-st-houston-tx-77001')->assertDontSee($la->ulid);
    $this->get(MW.'/sitemaps/pages.xml')->assertOk()->assertSee('https://movewisehomes.com/texas');
    $this->get(MW.'/brokers')->assertOk()->assertSee('Managing broker: Pat Broker');
});

it('never shows Movewise\'s feeds to a brokerage', function (): void {
    txLive($this);
    $acme = $this->onTenantHost('acme');
    expect(app(ListingSearch::class)->search($acme->id, [])['total'])->toBe(0);
});
