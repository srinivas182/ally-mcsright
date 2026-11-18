<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Media\Photos;
use Modules\Listings\Media\PhotoTags;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\ListingMedia;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Models\RulesProfile;
use Modules\Listings\Places\NearbyPlaces;
use Modules\Listings\Search\ListingSearch;
use Modules\Listings\Sync\ListingSync;
use Modules\Sites\Ailly\Assistant;
use Modules\Sites\SiteSetup;
use Tests\TestCase;

/*
| C03 (specs/websites/02): photo tagging (Restb.ai, Azure AI Vision, Google Cloud Vision) names each photo's room and
| the home's features, for alt text and "Has" search; nearby schools and transit (OpenStreetMap, Geoapify, Google
| Places) are looked up once per listing with straight-line distances. Test mode shows results only to HQ.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    config(['listings.cdn_url' => 'https://cdn.rightally.test']);
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create();
    $this->web = 'http://acme.rightally.site';
    $sites = app(SiteSetup::class);
    $sites->publish($sites->create('modern', ['phone' => '(512) 555-0100', 'area' => 'Austin'], $this->owner), true, $this->owner);

    app()->instance(ResoFeed::class, $this->feed = new FakeResoFeed);
    $rules = RulesProfile::query()->create(['name' => 'ACTRIS', 'program' => 'idx', 'attribution_template' => 'Listing courtesy of {ListOfficeName}',
        'disclaimer' => 'Information deemed reliable.', 'sold_allowed' => false, 'sold_delay_days' => 0, 'freshness_minutes' => 720]);
    $this->source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active', 'rules_profile_id' => $rules->id]);
    $this->feed->records = [ppHome('P1'), ppHome('P2', ['StreetNumber' => '40', 'Latitude' => 30.30]), ppHome('P3', ['StreetNumber' => '60', 'InternetAddressDisplayYN' => false])];
    app(ListingSync::class)->run($this->source);
    $a = MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $this->source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($a, ['idx'], null, null, User::factory()->hqStaff()->make());
    $this->p1 = Listing::query()->where('listing_key', 'P1')->sole();
    $this->p2 = Listing::query()->where('listing_key', 'P2')->sole();
    ppPhotos($this->p1, ['kitchen', 'pool', 'person']);
    ppPhotos($this->p2, ['bedroom']);
});

function ppHome(string $key, array $over = []): array
{
    return $over + ['ListingKey' => $key, 'ListingId' => 'MLS-'.$key, 'StandardStatus' => 'Active', 'PropertyType' => 'Residential', 'ListPrice' => 450000,
        'BedroomsTotal' => 3, 'BathroomsFull' => 2, 'LivingArea' => 1800, 'StreetNumber' => '20', 'StreetName' => 'Elm St', 'City' => 'Austin', 'StateOrProvince' => 'TX',
        'PostalCode' => '78704', 'Latitude' => 30.25, 'Longitude' => -97.75, 'ListOfficeName' => 'Acme Realty', 'OnMarketDate' => '2026-05-10',
        'ModificationTimestamp' => '2026-05-20T00:00:00Z'];
}

/** Stored photos named after what is in them, so the fake providers can answer per photo. */
function ppPhotos(Listing $l, array $names): void
{
    foreach ($names as $i => $n) {
        ListingMedia::query()->create(['listing_id' => $l->id, 'media_key' => $n.$l->id, 'position' => $i, 'source_url' => 'https://photos.example/'.$n.'.jpg',
            'storage_path' => 'listings/'.$l->id.'/'.$n, 'status' => 'stored', 'attempts' => 1]);
    }
    $l->forceFill(['photos_count' => count($names)])->saveQuietly();
}

/**
 * HQ staff on the HQ console address.
 *
 * @return array{0: User, 1: string}
 */
function ppHq(TestCase $test): array
{
    config(['core.hq_host' => 'app.rightally.io']);
    $test->artisan('tenants:bootstrap-hq')->assertSuccessful();
    $admin = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());

    return [$admin, 'http://app.rightally.io'];
}

function ppService(string $service, string $provider, array $keys, string $mode): void
{
    DB::connection(Connections::control())->table('outside_services')->updateOrInsert(['tenant_id' => 0, 'service' => $service], ['provider' => $provider, 'mode' => $mode,
        'test_keys' => Crypt::encryptString((string) json_encode($keys)), 'live_keys' => Crypt::encryptString((string) json_encode($keys)), 'test_contacts' => '',
        'created_at' => now(), 'updated_at' => now()]);
}

/** Restb.ai's answer for a photo, from its name. */
function ppRestb(HttpRequest $r): array
{
    $url = (string) ($r->data()['image_url'] ?? '');
    $room = match (true) {
        str_contains($url, 'kitchen') => 'kitchen', str_contains($url, 'pool') => 'backyard', str_contains($url, 'bedroom') => 'bedroom', default => 'unknown'
    };
    $features = match (true) {
        str_contains($url, 'kitchen') => [['label' => 'kitchen_island', 'confidence' => 0.93], ['label' => 'stainless_steel_appliances', 'confidence' => 0.88],
            ['label' => 'pool_table', 'confidence' => 0.9]],
        str_contains($url, 'pool') => [['label' => 'swimming_pool', 'confidence' => 0.97], ['label' => 'fireplace', 'confidence' => 0.2]],
        str_contains($url, 'person') => [['label' => 'person', 'confidence' => 0.99]],
        default => [],
    };

    return ['response' => ['solutions' => ['re_roomtype_global_v2' => ['top_prediction' => ['label' => $room, 'confidence' => 0.95]], 're_features_v4' => ['detections' => $features]]]];
}

/** An Overpass answer: two schools in range, one too far, a bus stop on each side of the road, a subway station. */
function ppOverpass(): array
{
    return ['elements' => [
        ['type' => 'way', 'center' => ['lat' => 30.255, 'lon' => -97.75], 'tags' => ['amenity' => 'school', 'name' => 'Becker Elementary School']],
        ['type' => 'node', 'lat' => 30.262, 'lon' => -97.752, 'tags' => ['amenity' => 'school', 'name' => 'Travis High School', 'isced:level' => '3']],
        ['type' => 'node', 'lat' => 30.40, 'lon' => -97.75, 'tags' => ['amenity' => 'school', 'name' => 'Far Away School']],
        ['type' => 'node', 'lat' => 30.2505, 'lon' => -97.7501, 'tags' => ['highway' => 'bus_stop', 'name' => 'Elm & 2nd']],
        ['type' => 'node', 'lat' => 30.2506, 'lon' => -97.7499, 'tags' => ['highway' => 'bus_stop', 'name' => 'Elm & 2nd']],
        ['type' => 'node', 'lat' => 30.27, 'lon' => -97.74, 'tags' => ['railway' => 'station', 'station' => 'subway', 'name' => 'Downtown Station']],
        ['type' => 'node', 'lat' => 30.251, 'lon' => -97.75, 'tags' => ['amenity' => 'school']], // no name: skipped
        ['type' => 'way', 'center' => ['lat' => 30.258, 'lon' => -97.755], 'tags' => ['shop' => 'supermarket', 'name' => 'Green Grocer']],
        ['type' => 'node', 'lat' => 30.2515, 'lon' => -97.7505, 'tags' => ['amenity' => 'cafe', 'name' => 'Corner Coffee']],
    ]];
}

it('tags photos in Test mode for HQ to check, without showing anything on listing pages yet', function (): void {
    ppService('photo_tags', 'restb', ['api_key' => 'restb-client-key-123456'], 'test');
    Http::fake(['api-us.restb.ai/*' => fn (HttpRequest $r) => Http::response(ppRestb($r))]);

    $this->artisan('listings:photos-and-places')->expectsOutputToContain('Photo tagging (test): 4 photos in 2 listings')
        ->expectsOutputToContain('Nearby schools and transit is off.')->assertSuccessful();
    $kitchen = ListingMedia::query()->where('media_key', 'kitchen'.$this->p1->id)->sole();
    expect($kitchen->room)->toBe('kitchen')->and(json_decode((string) $kitchen->tags, true))->toBe(['kitchen_island', 'stainless_appliances'])
        ->and($kitchen->alt_text)->toBe('Kitchen with kitchen island and stainless appliances');
    // Only the home: a photo of a person gets no description, and the pool table is not a pool.
    expect(ListingMedia::query()->where('media_key', 'person'.$this->p1->id)->sole()->alt_text)->toBeNull();
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'model_id=re_roomtype_global_v2%2Cre_features_v4')
        && str_contains($r->url(), urlencode('https://cdn.rightally.test/listings/'.$this->p1->id.'/kitchen-800.webp')));

    // Nothing public yet: no features, no "Has" filter, alt text still the plain fallback.
    expect(app(ListingSearch::class)->find($this->tenant->id, $this->p1->ulid)['features'])->toBe([])->and(PhotoTags::searchable())->toBe([]);
    $html = (string) $this->followingRedirects()->get($this->web.'/homes/'.$this->p1->ulid)->assertOk()->getContent();
    expect($html)->not->toContain('Seen in the photos')->not->toContain('Kitchen with kitchen island');

    [$hq, $host] = ppHq($this);
    $this->actingAs($hq)->get($host.'/hq/mls/photos-places')->assertOk()->assertInertia(fn (Assert $p) => $p->component('hq/mls-photos-places')
        ->where('tags.mode', 'test')->where('tags.photos', 4)->where('tags.listings', 0)
        ->where('tags.latest', fn ($rows): bool => collect($rows)->contains(fn ($r): bool => $r['alt'] === 'Kitchen with kitchen island and stainless appliances' && $r['room'] === 'Kitchen')));
});

it('in Live, gathers features from photos already tagged, shows them, and lets people search "Has: pool"', function (): void {
    ppService('photo_tags', 'restb', ['api_key' => 'restb-client-key-123456'], 'test');
    $calls = 0;
    Http::fake(['api-us.restb.ai/*' => function (HttpRequest $r) use (&$calls) {
        $calls++;

        return Http::response(ppRestb($r));
    }]);
    $this->artisan('listings:photos-and-places');
    expect($calls)->toBe(4);

    ppService('photo_tags', 'restb', ['api_key' => 'restb-client-key-123456'], 'live');
    $this->artisan('listings:photos-and-places')->expectsOutputToContain('Photo tagging (live): 0 photos in 2 listings');
    expect($calls)->toBe(4); // not paid for twice
    expect(PhotoTags::labelsFor($this->p1->fresh()))->toBe(['Pool', 'Kitchen island', 'Stainless appliances']);

    // Search: "Has: pool" finds only the home with the pool, in the back office and on the website.
    $search = app(ListingSearch::class)->search($this->tenant->id, ['features' => 'pool']);
    expect(array_column($search['results'], 'ulid'))->toBe([$this->p1->ulid]);
    expect(app(ListingSearch::class)->search($this->tenant->id, ['features' => 'made_up'])['total'])->toBe(3);
    $this->actingAs($this->agent)->get('/listings?features=pool')->assertInertia(fn (Assert $p) => $p->where('total', 1)->where('featureChoices.pool', 'Pool'));
    expect((string) $this->get($this->web.'/homes')->getContent())->toContain('<option value="pool" >Pool</option>');

    // The listing page: features, alt text and structured data.
    $html = (string) $this->followingRedirects()->get($this->web.'/homes/'.$this->p1->ulid)->assertOk()->getContent();
    expect($html)->toContain('Seen in the photos')->toContain('alt="Kitchen with kitchen island and stainless appliances"')->toContain('alt="Backyard with pool"')
        ->toContain('"amenityFeature":[{"@type":"LocationFeatureSpecification","name":"Pool","value":true}');
    $this->actingAs($this->agent)->get('/listings/'.$this->p1->ulid)->assertInertia(fn (Assert $p) => $p->component('listings/show')
        ->where('listing.features', ['Pool', 'Kitchen island', 'Stainless appliances'])->where('listing.photos.0.alt', 'Kitchen with kitchen island and stainless appliances'));

    // An MLS caption always wins; a changed photo is tagged again.
    ListingMedia::query()->where('media_key', 'pool'.$this->p1->id)->update(['caption' => 'Heated saltwater pool']);
    expect(collect(Photos::forListing($this->p1->fresh()))->pluck('alt')->all())->toContain('Heated saltwater pool');
    $record = ppHome('P1', ['Media' => [['MediaKey' => 'kitchen'.$this->p1->id, 'MediaURL' => 'https://photos.example/kitchen-new.jpg', 'Order' => 0]]]);
    app(Photos::class)->syncList($this->p1->fresh(), $record);
    expect($this->p1->fresh()->photos_tagged_at)->toBeNull()
        ->and(ListingMedia::query()->where('media_key', 'kitchen'.$this->p1->id)->sole()->tagged_at)->toBeNull();
});

it('matches Azure AI Vision and Google Cloud Vision tags to rooms and features', function (): void {
    ppService('photo_tags', 'azure_vision', ['endpoint' => 'https://acme.cognitiveservices.azure.com', 'api_key' => 'azure-key-1234567890'], 'live');
    Http::fake(['acme.cognitiveservices.azure.com/*' => Http::response(['tagsResult' => ['values' => [['name' => 'indoor', 'confidence' => 0.99],
        ['name' => 'bedroom', 'confidence' => 0.91], ['name' => 'hardwood', 'confidence' => 0.8], ['name' => 'fireplace', 'confidence' => 0.75], ['name' => 'person', 'confidence' => 0.9]]]])]);
    app(PhotoTags::class)->run();
    expect(ListingMedia::query()->where('media_key', 'bedroom'.$this->p2->id)->sole()->alt_text)->toBe('Bedroom with fireplace and hardwood floors');
    Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Ocp-Apim-Subscription-Key', 'azure-key-1234567890') && str_contains($r->url(), 'imageanalysis:analyze'));

    expect(PhotoTags::match([['Kitchen', 0.95], ['House', 0.9]], [['Countertop', 0.9], ['Granite', 0.82], ['Swimming pool', 0.4]]))
        ->toBe(['room' => 'kitchen', 'features' => ['stone_countertops']]);

    ListingMedia::query()->update(['tagged_at' => null]);
    Listing::query()->update(['photos_tagged_at' => null]);
    ppService('photo_tags', 'google_vision', ['api_key' => 'google-key-1234567890'], 'live');
    Http::fake(['vision.googleapis.com/*' => Http::response(['responses' => [['labelAnnotations' => [['description' => 'Swimming pool', 'score' => 0.96],
        ['description' => 'Backyard', 'score' => 0.9], ['description' => 'Deck', 'score' => 0.7]]]]])]);
    app(PhotoTags::class)->run();
    expect(ListingMedia::query()->where('media_key', 'pool'.$this->p1->id)->sole()->alt_text)->toBe('Backyard with pool and deck or patio');
});

it('finds nearby schools and transit with OpenStreetMap, shows them in Live, and never rates schools', function (): void {
    ppService('nearby_places', 'osm', ['host' => 'https://overpass.example/api/interpreter'], 'live');
    $empty = false;
    Http::fake(['overpass.example/*' => function () use (&$empty) {
        return Http::response($empty ? ['elements' => []] : ppOverpass());
    }]);

    $this->artisan('listings:photos-and-places')->expectsOutputToContain('Nearby places (live): 2 listings')->assertSuccessful();
    // The listing whose address the MLS hides is not looked up.
    expect(Listing::query()->where('listing_key', 'P3')->sole()->places_at)->toBeNull();
    Http::assertSent(fn (HttpRequest $r) => str_contains((string) $r->data()['data'], 'nwr["amenity"="school"](around:3219,30.250000,-97.750000)'));

    $nearby = app(ListingSearch::class)->find($this->tenant->id, $this->p1->ulid)['nearby'];
    expect(array_column($nearby['schools'], 'name'))->toBe(['Becker Elementary School', 'Travis High School'])
        ->and($nearby['schools'][0])->toBe(['name' => 'Becker Elementary School', 'type' => 'Elementary school', 'miles' => '0.3 miles'])
        ->and($nearby['schools'][1]['type'])->toBe('High school')
        ->and(array_column($nearby['transit'], 'name'))->toBe(['Elm & 2nd', 'Downtown Station']) // the two stops are one
        ->and($nearby['transit'][0]['miles'])->toBe('less than 0.1 miles')
        ->and($nearby['summary'])->toBe('The nearest school is Becker Elementary School, 0.3 miles away. The nearest bus stop is Elm & 2nd, less than 0.1 miles away.')
        ->and($nearby['shops'])->toBe([['name' => 'Corner Coffee', 'type' => 'Coffee shop', 'miles' => '0.1 miles'], ['name' => 'Green Grocer', 'type' => 'Grocery store', 'miles' => '0.6 miles']])
        ->and($nearby['credit'])->toBe('© OpenStreetMap contributors');

    // AIlly answers "how far is the school?" on the listing page from these, without looking anything up.
    $facts = (new ReflectionMethod(Assistant::class, 'homeFacts'))->invoke(app(Assistant::class), '/homes/'.$this->p1->ulid.'/20-elm-st-austin-tx-78704');
    expect(implode("\n", $facts))->toContain('Becker Elementary School (elementary school, 0.3 miles)')->toContain('Green Grocer (grocery store, 0.6 miles)')
        ->toContain('Never rate or rank schools');
    expect((new ReflectionMethod(Assistant::class, 'homeFacts'))->invoke(app(Assistant::class), '/about'))->toBe([]);

    $html = (string) $this->followingRedirects()->get($this->web.'/homes/'.$this->p1->ulid)->assertOk()->getContent();
    expect($html)->toContain("What's nearby")->toContain('Becker Elementary School')->toContain('Downtown Station')->toContain('Subway station')
        ->toContain('School attendance depends on the school district')->toContain('Groceries and coffee')->toContain('© OpenStreetMap contributors')
        ->not->toContain('rating')->not->toContain('Far Away School');

    // Looked up again after 30 days, not before.
    $empty = true;
    $this->travel(10)->days();
    expect(app(NearbyPlaces::class)->run()['listings'])->toBe(0);
    $this->travel(21)->days();
    expect(app(NearbyPlaces::class)->run()['listings'])->toBe(2);
    expect(app(ListingSearch::class)->find($this->tenant->id, $this->p1->ulid)['nearby'])->toBeNull();
});

it('keeps Test-mode places to HQ, and reads Geoapify and Google Places too', function (): void {
    ppService('nearby_places', 'geoapify', ['api_key' => 'geoapify-key-12345678'], 'test');
    Http::fake(['api.geoapify.com/*' => Http::response(['type' => 'FeatureCollection', 'features' => [
        ['properties' => ['name' => 'Zilker Elementary', 'lat' => 30.252, 'lon' => -97.75, 'categories' => ['education', 'education.school']]],
        ['properties' => ['name' => 'Lamar Station', 'lat' => 30.26, 'lon' => -97.75, 'categories' => ['public_transport', 'public_transport.light_rail']]],
        ['properties' => ['name' => 'Some Cafe', 'lat' => 30.25, 'lon' => -97.75, 'categories' => ['catering.cafe']]],
    ]])]);
    expect(app(NearbyPlaces::class)->run()['listings'])->toBe(2);
    expect(app(ListingSearch::class)->find($this->tenant->id, $this->p1->ulid)['nearby'])->toBeNull(); // Test: HQ only
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'filter=circle%3A-97.750000%2C30.250000%2C4828'));
    [$hq, $host] = ppHq($this);
    $this->actingAs($hq)->get($host.'/hq/mls/photos-places')->assertInertia(fn (Assert $p) => $p->where('places.checked', 2)->where('places.listings', 0)
        ->where('places.latest.0.places', fn ($rows): bool => collect($rows)->pluck('type')->sort()->values()->all() === ['Coffee shop', 'Elementary school', 'Light rail or tram stop']));

    $google = ['provider' => 'google_places', 'mode' => 'live', 'keys' => ['api_key' => 'g-key-1234567890123']];
    Http::fake(['places.googleapis.com/*' => fn (HttpRequest $r) => Http::response(['places' => in_array('school', $r->data()['includedTypes'], true)
        ? [['displayName' => ['text' => 'Austin Middle School'], 'location' => ['latitude' => 30.253, 'longitude' => -97.75], 'types' => ['secondary_school', 'school']]]
        : [['displayName' => ['text' => 'Congress Ave Stop'], 'location' => ['latitude' => 30.2502, 'longitude' => -97.75], 'types' => ['bus_stop', 'transit_station']]]])]);
    $found = app(NearbyPlaces::class)->lookUp($google, 30.25, -97.75);
    expect(array_column($found, 'subtype'))->toBe(['middle', 'bus']);
    Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('X-Goog-Api-Key', 'g-key-1234567890123') && $r['rankPreference'] === 'DISTANCE');
});

it('does nothing when both services are off, and says so on HQ', function (): void {
    Http::fake();
    $this->artisan('listings:photos-and-places')->expectsOutputToContain('Photo tagging is off.')->expectsOutputToContain('Nearby schools and transit is off.');
    Http::assertNothingSent();
    [$hq, $host] = ppHq($this);
    $this->actingAs($hq)->post($host.'/hq/mls/photos-places/run')->assertRedirect();
    expect(NearbyPlaces::miles(1609))->toBe('1 mile')->and(NearbyPlaces::miles(3500))->toBe('2.2 miles')
        ->and(NearbyPlaces::schoolLevel('Kealing Junior High'))->toBe('middle')->and(NearbyPlaces::schoolLevel('St. Mary Academy'))->toBe('school');
});
