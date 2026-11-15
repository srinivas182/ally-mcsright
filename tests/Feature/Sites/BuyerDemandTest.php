<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\Connections;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\SavedSearch;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Sync\ListingSync;
use Modules\Sites\Demand\BuyerDemand;
use Modules\Sites\Demand\DemandAlert;
use Modules\Sites\Models\DemandReport;
use Modules\Sites\SiteSetup;

/* Sprint BD1: buyer demand before listing — counts of the brokerage's own buyers who want a home like this, by
   price, through which agents, with similar homes for sale; a printable report in English and Spanish. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $this->bob = User::factory()->create(['first_name' => 'Bob', 'last_name' => 'Broker']);
    $this->site = app(SiteSetup::class)->create('aurum', [], $this->owner);
    app()->instance(ResoFeed::class, $feed = new FakeResoFeed);
    $source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    $feed->records = array_map(fn (int $i): array => ['ListingKey' => 'D'.$i, 'ListingId' => 'MLS'.$i, 'StandardStatus' => 'Active', 'PropertyType' => 'Residential',
        'ListPrice' => 400000 + $i * 25000, 'BedroomsTotal' => 3, 'BathroomsFull' => 2, 'LivingArea' => 1700, 'StreetNumber' => (string) (100 + $i), 'StreetName' => 'Elm St',
        'City' => 'Austin', 'StateOrProvince' => 'TX', 'PostalCode' => '78704', 'Latitude' => 30.25, 'Longitude' => -97.76, 'ListOfficeName' => 'Acme Realty',
        'OnMarketDate' => '2026-05-15', 'ModificationTimestamp' => '2026-05-20T00:0'.$i.':00Z'], range(1, 4));
    app(ListingSync::class)->run($source);
    $a = MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($a, ['idx'], null, null, User::factory()->hqStaff()->make());

    // Buyers who told the brokerage what they want.
    $contacts = app(ManageContacts::class);
    $want = function (User $agent, string $name, array $filters) use ($contacts): void {
        $c = $contacts->create(['first_name' => $name, 'last_name' => 'Buyer', 'email' => strtolower($name).'@x.test'], $agent);
        SavedSearch::query()->create(['contact_id' => $c->id, 'user_id' => $agent->id, 'frequency' => 'daily', 'filters' => $filters, 'name' => $name]);
    };
    $want($this->ann, 'Amy', ['q' => '78704', 'max_price' => 500000, 'beds' => 3]);            // up to $500k
    $want($this->ann, 'Abe', ['q' => 'Austin, TX', 'min_price' => 400000, 'max_price' => 550000]); // the city
    $want($this->bob, 'Ben', ['bounds' => [30.2, -97.8, 30.3, -97.7], 'max_price' => 600000]);     // a map area
    $want($this->bob, 'Bea', ['q' => '78704', 'max_price' => 450000]);                          // too low at $500k
    $want($this->bob, 'Bo', ['q' => '78704', 'beds' => 5]);                                     // wants more bedrooms
    $want($this->bob, 'Bud', ['q' => 'Dallas, TX', 'max_price' => 600000]);                     // elsewhere
    $c = $contacts->create(['first_name' => 'Lea', 'last_name' => 'Lead', 'email' => 'lea@x.test'], $this->bob);
    Lead::query()->create(['contact_id' => $c->id, 'source' => 'website', 'channel' => 'website', 'intent' => 'buy', 'zip' => '78704', 'price_min' => 450000, 'price_max' => 525000,
        'status' => 'responded', 'new_contact' => true]);

    // Website shoppers who looked at similar homes.
    $db = DB::connection(Connections::cell());
    foreach (['vid_aaaaaaaaaaaaaaaa', 'vid_bbbbbbbbbbbbbbbb'] as $n => $vid) {
        $v = $db->table('site_visitors')->insertGetId(['tenant_id' => $this->tenant->id, 'site_id' => $this->site->id, 'ulid' => (string) Str::ulid(), 'vid' => $vid, 'created_at' => now(), 'updated_at' => now()]);
        foreach (Listing::query()->pluck('ulid') as $u) {
            $db->table('site_events')->insert(['tenant_id' => $this->tenant->id, 'visitor_id' => $v, 'type' => 'listing', 'listing_ulid' => $u, 'created_at' => now()->subDays(3)]);
        }
        if ($n === 0) {
            $db->table('site_favourites')->insert(['tenant_id' => $this->tenant->id, 'visitor_id' => $v, 'listing_ulid' => Listing::query()->value('ulid'), 'created_at' => now()]);
        }
    }
});

it('counts the buyers who want a home like this, by price, through which agents, never who they are', function (): void {
    $this->actingAs($this->ann)->post('/listings/demand', ['address' => '12 Oak Ln', 'zip' => '78704', 'price' => '$500,000', 'beds' => 3, 'sqft' => 1800])
        ->assertRedirect('/listings/demand?report='.DemandReport::query()->sole()->ulid);
    $r = DemandReport::query()->sole()->result;
    expect($r['buyers'])->toBe(4) // Amy, Abe, Ben and Lea
        ->and(array_column($r['curve'], 'buyers', 'price'))->toBe([425000 => 4, 450000 => 5, 475000 => 4, 500000 => 4, 525000 => 3, 550000 => 2, 575000 => 1])
        ->and($r['agents'])->toBe([['id' => $this->ann->id, 'name' => 'Ann Agent', 'buyers' => 2], ['id' => $this->bob->id, 'name' => 'Bob Broker', 'buyers' => 2]])
        ->and($r['shoppers'])->toBe(2)->and($r['favourited'])->toBe(1)
        ->and($r['competition']['total'])->toBe(4)->and($r['competition']['median_price'])->toBe(475000);
    expect(json_encode($r))->not->toContain('Amy')->not->toContain('amy@x.test')->not->toContain('Lea');

    $this->actingAs($this->ann)->get('/listings/demand?report='.DemandReport::query()->sole()->ulid)->assertInertia(fn (Assert $p) => $p->component('listings/demand')
        ->where('report.buyers', 4)->where('reports.0.address', '12 Oak Ln'));
    $this->actingAs($this->bob)->get('/listings/demand?report='.DemandReport::query()->sole()->ulid)->assertInertia(fn (Assert $p) => $p->where('report', null)); // not his
});

it('prints a branded report for the seller in English or Spanish', function (): void {
    $report = app(BuyerDemand::class)->run($this->ann, ['address' => '12 Oak Ln', 'zip' => '78704', 'price' => '500000', 'beds' => 3]);
    $this->actingAs($this->ann)->get('/listings/demand/'.$report->ulid.'/print')->assertOk()->assertSee('Buyer demand')->assertSee('buyers want a home like this')
        ->assertSee('Counts only')->assertSee('Equal Housing Opportunity.')->assertDontSee('Amy');
    $this->actingAs($this->ann)->get('/listings/demand/'.$report->ulid.'/print?lang=es')->assertOk()->assertSee('Demanda de compradores')
        ->assertSee('compradores buscan una casa como esta')->assertSee('Igualdad de oportunidades de vivienda.');
    $this->actingAs($this->bob)->get('/listings/demand/'.$report->ulid.'/print')->assertForbidden();
});

it('tells the agents who hold matching buyers, once', function (): void {
    $report = app(BuyerDemand::class)->run($this->ann, ['address' => '12 Oak Ln', 'zip' => '78704', 'price' => '500000', 'beds' => 3]);
    $this->actingAs($this->ann)->post('/listings/demand/'.$report->ulid.'/tell')->assertSessionHasNoErrors();
    Notification::assertSentTo($this->bob, DemandAlert::class, fn (DemandAlert $n): bool => $n->buyers === 2 && $n->listingAgent === 'Ann Agent');
    Notification::assertNotSentTo($this->ann, DemandAlert::class);
    $this->actingAs($this->ann)->post('/listings/demand/'.$report->ulid.'/tell')->assertSessionHasErrors('agents');
});

it('asks for an address, a ZIP code and a price', function (): void {
    $this->actingAs($this->ann)->post('/listings/demand', ['address' => '', 'zip' => '787', 'price' => ''])->assertSessionHasErrors(['address', 'zip', 'price']);
});
