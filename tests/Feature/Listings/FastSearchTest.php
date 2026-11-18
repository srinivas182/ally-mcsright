<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Search\Engines\MeilisearchEngine;
use Modules\Listings\Search\Engines\OpenSearchEngine;
use Modules\Listings\Search\ListingSearch;
use Modules\Listings\Sync\Duplicates;
use Modules\Listings\Sync\ListingSync;

require_once __DIR__.'/../Contracts/contract-helpers.php';

/*
| SR1 (specs/websites/02): one home on two MLSs is shown once; homes near a point with their distance; and the fast
| search server (Typesense, Meilisearch or OpenSearch) — compared in Test mode, answering in Live mode, with the
| database stepping in when it fails.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Storage::fake('public');
    Cache::flush();
    app()->instance(ResoFeed::class, $this->feed = new FakeResoFeed);
    $this->actris = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    $this->sabor = MlsSource::query()->create(['key' => 'sabor', 'name' => 'SABOR', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    $this->tenant = $this->onTenantHost('acme');
    $this->agent = User::factory()->create();
});

function fsHome(string $key, array $over = []): array
{
    return $over + ['ListingKey' => $key, 'ListingId' => 'MLS-'.$key, 'StandardStatus' => 'Active', 'PropertyType' => 'Residential', 'ListPrice' => 400000,
        'BedroomsTotal' => 3, 'BathroomsFull' => 2, 'LivingArea' => 1800, 'StreetNumber' => '12', 'StreetName' => 'Oak Street', 'City' => 'Austin', 'StateOrProvince' => 'TX',
        'PostalCode' => '78704', 'Latitude' => 30.25, 'Longitude' => -97.75, 'ListOfficeName' => 'Acme Realty', 'OnMarketDate' => '2026-05-10',
        'ModificationTimestamp' => '2026-05-20T00:00:00Z'];
}

function fsLicence(MlsSource $source, int $tenantId): void
{
    $a = MlsAuthorization::query()->create(['tenant_id' => $tenantId, 'mls_source_id' => $source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($a, ['idx'], null, null, User::factory()->hqStaff()->make());
}

function fsServer(string $provider, array $keys, string $mode): void
{
    DB::connection(Connections::control())->table('outside_services')->insert(['tenant_id' => 0, 'service' => 'search', 'provider' => $provider, 'mode' => $mode,
        'test_keys' => Crypt::encryptString((string) json_encode($keys)), 'live_keys' => Crypt::encryptString((string) json_encode($keys)), 'test_contacts' => '',
        'created_at' => now(), 'updated_at' => now()]);
}

it('shows a home listed on two MLSs once, but still shows it to a brokerage licensed for only one', function (): void {
    expect(Duplicates::key('12', 'North Oak Street', 'Apt 4', '78704-1234'))->toBe('12|n oak st|4|78704')
        ->and(Duplicates::key('12', 'N. Oak St', '#4', '78704'))->toBe('12|n oak st|4|78704')
        ->and(Duplicates::key(null, 'Oak St', null, '78704'))->toBeNull();

    $this->feed->records = [fsHome('A1', ['Media' => [['MediaKey' => 'm1', 'MediaURL' => 'https://photos.example/1.jpg', 'Order' => 0]]]), fsHome('A2', ['StreetNumber' => '14'])];
    Http::fake(['photos.example/*' => Http::response('x', 404)]);
    app(ListingSync::class)->run($this->actris);
    $this->feed->records = [fsHome('S1', ['StreetName' => 'Oak St', 'ModificationTimestamp' => '2026-05-21T00:00:00Z'])];
    app(ListingSync::class)->run($this->sabor);

    $copy = Listing::query()->where('listing_key', 'S1')->sole();
    $shown = Listing::query()->where('listing_key', 'A1')->sole();
    expect($copy->duplicate_of_id)->toBe($shown->id)->and($shown->duplicate_of_id)->toBeNull(); // A1 has a photo

    fsLicence($this->actris, $this->tenant->id);
    fsLicence($this->sabor, $this->tenant->id);
    $both = app(ListingSearch::class)->search($this->tenant->id, ['q' => 'Austin']);
    expect($both['total'])->toBe(2)->and(array_column($both['results'], 'mls_number'))->not->toContain('MLS-S1');

    $other = $this->onTenantHost('bravo');
    fsLicence($this->sabor, $other->id);
    expect(app(ListingSearch::class)->search($other->id, ['q' => 'Austin'])['total'])->toBe(1); // only SABOR: its own copy

    // When the shown copy leaves the market, the other copy is shown again.
    DB::connection(Connections::listings())->table('listings')->where('id', $shown->id)->update(['off_market_at' => now()]);
    app(Duplicates::class)->refresh([$shown->id]);
    expect($copy->fresh()->duplicate_of_id)->toBeNull();
});

it('finds homes near a point, closest first, with how far each is', function (): void {
    $this->feed->records = [fsHome('N1', ['Latitude' => 30.2500, 'Longitude' => -97.7500]), fsHome('N2', ['StreetNumber' => '200', 'Latitude' => 30.2650, 'Longitude' => -97.7500]),
        fsHome('N3', ['StreetNumber' => '900', 'Latitude' => 30.4000, 'Longitude' => -97.7500])];
    app(ListingSync::class)->run($this->actris);
    fsLicence($this->actris, $this->tenant->id);

    $found = app(ListingSearch::class)->search($this->tenant->id, ['near' => '30.2660,-97.7500,2', 'sort' => 'distance']);
    expect($found['total'])->toBe(2)->and(array_column($found['results'], 'mls_number'))->toBe(['MLS-N2', 'MLS-N1'])
        ->and($found['results'][0]['miles'])->toBe(0.1)->and($found['results'][1]['miles'])->toBe(1.1);
    expect(ListingSearch::clean(['sort' => 'distance'])['sort'])->toBe('newest') // closest first needs a point
        ->and(ListingSearch::clean(['near' => '30,-97,500'])['near'])->toBeNull();
});

it('answers from the search server in Live mode, keeps the MLS rules, and falls back to the database when it fails', function (): void {
    $this->feed->records = [fsHome('L1'), fsHome('L2', ['StreetNumber' => '20', 'ListPrice' => 500000])];
    app(ListingSync::class)->run($this->actris);
    fsLicence($this->actris, $this->tenant->id);
    $l1 = Listing::query()->where('listing_key', 'L1')->sole();
    $l2 = Listing::query()->where('listing_key', 'L2')->sole();
    fsServer('typesense', ['host' => 'https://ts.example', 'api_key' => 'ts-key'], 'live');

    $down = false;
    Http::fake(function () use (&$down, $l1, $l2) {
        return $down ? Http::response([], 500) : Http::response(['found' => 3, 'hits' => [['document' => ['id' => (string) $l2->id]],
            ['document' => ['id' => '999999']], ['document' => ['id' => (string) $l1->id]]]]);
    });
    $this->actingAs($this->agent)->get('/listings?q=Ostin&min_price=450000')->assertInertia(fn (Assert $p) => $p->where('total', 3)
        ->where('results.0.mls_number', 'MLS-L2')->where('results.1.mls_number', 'MLS-L1')->count('results', 2)); // an unknown id is dropped
    Http::assertSent(function (HttpRequest $r): bool {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

        return $q['q'] === 'Ostin' && str_contains($q['filter_by'], 'source:['.$this->actris->id.']') && str_contains($q['filter_by'], 'dup_source:!=[')
            && str_contains($q['filter_by'], 'price:>=45000000') && $r->hasHeader('X-TYPESENSE-API-KEY', 'ts-key');
    });

    $down = true;
    $this->actingAs($this->agent)->get('/listings?q=Austin')->assertInertia(fn (Assert $p) => $p->where('total', 2));
});

it('keeps the server up to date after each sync, compares it in Test mode, and lets HQ rebuild it', function (): void {
    fsServer('typesense', ['host' => 'https://ts.example', 'api_key' => 'ts-key'], 'test');
    Http::fake([
        'ts.example/collections/listings/documents/import*' => Http::response('{"success":true}'),
        'ts.example/collections/listings/documents/search*' => Http::response(['found' => 1, 'hits' => [['document' => ['id' => '1']]]]),
        'ts.example/collections/listings/documents?*' => Http::response(['num_deleted' => 1]),
        'ts.example/collections/listings' => Http::response(['num_documents' => 2]),
        'ts.example/*' => Http::response([]),
    ]);
    $this->feed->records = [fsHome('T1'), fsHome('T2', ['StreetNumber' => '30'])];
    app(ListingSync::class)->run($this->actris);
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/documents/import?action=upsert') && substr_count($r->body(), "\n") === 1
        && str_contains($r->body(), '"location":[30.25,-97.75]') && str_contains($r->body(), '"text":"12 Oak Street MLS-T1 Austin 78704"'));

    fsLicence($this->actris, $this->tenant->id);
    $this->actingAs($this->agent)->get('/listings')->assertInertia(fn (Assert $p) => $p->where('total', 2)); // the database answers in Test mode
    $samples = Cache::get('listings:search-compare');
    expect($samples)->toHaveCount(1)->and($samples[0]['database_total'])->toBe(2)->and($samples[0]['server_total'])->toBe(1);

    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq')->assertSuccessful();
    $staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $hq = 'http://app.rightally.io';
    assertPageContract($this->actingAs($staff)->get($hq.'/hq/mls/search'), 'hq/mls-search');
    $this->actingAs($staff)->get($hq.'/hq/mls/search')->assertInertia(fn (Assert $p) => $p->where('report.provider', 'typesense')->where('report.mode', 'test')
        ->where('report.compared', 1)->where('report.indexed', 2)->where('report.should_be', 2));
    $this->actingAs($staff)->post($hq.'/hq/mls/search/rebuild')->assertSessionHasNoErrors();
    expect(Cache::get('listings:search-compare:rebuilt'))->not->toBeNull();
});

it('asks Meilisearch and OpenSearch the same question in their own words', function (): void {
    Http::fake(['meili.example/*' => Http::response(['totalHits' => 1, 'hits' => [['id' => 7]]]), 'os.example/*' => Http::response(['hits' => ['total' => ['value' => 1], 'hits' => [['_id' => '7']]]])]);
    $query = ['q' => 'oak', 'sources' => [3], 'statuses' => ['Active'], 'types' => [], 'min_price' => 100, 'max_price' => null, 'beds' => 2, 'baths' => null, 'min_sqft' => null,
        'bounds' => null, 'near' => [30.25, -97.75, 2.0], 'agent' => null, 'states' => [], 'since' => null, 'sort' => 'distance', 'page' => 1, 'per_page' => 24];
    expect((new MeilisearchEngine('https://meili.example', 'k'))->search($query))->toBe(['ids' => [7], 'total' => 1])
        ->and((new OpenSearchEngine('https://os.example', 'u', 'p'))->search($query))->toBe(['ids' => [7], 'total' => 1]);
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'meili.example') && str_contains((string) $r['filter'], '_geoRadius(30.25, -97.75, 3219)')
        && str_contains((string) $r['filter'], 'dup_source NOT IN [3]') && $r['sort'] === ['_geoPoint(30.25, -97.75):asc']);
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'os.example/listings/_search') && $r['query']['bool']['must'][0]['multi_match']['fuzziness'] === 'AUTO'
        && $r['query']['bool']['must_not'][0]['terms']['dup_source'] === [3]);
});
