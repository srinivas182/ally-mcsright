<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Services\ConnectionCheck;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Feed\RepliersFeed;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Sync\ListingSync;

/*
| IDX3: Repliers. Listings are read from Repliers' search, oldest change first, and turned into RESO records, so they
| go through the same sync, MLS rules, duplicates and search as every other feed.
*/

function repliersListing(string $mls, string $status = 'A', string $last = 'New', string $updated = '2026-11-20T10:00:00Z'): array
{
    return ['mlsNumber' => $mls, 'boardId' => 7, 'status' => $status, 'lastStatus' => $last, 'class' => 'residential', 'type' => 'sale',
        'listPrice' => '425000', 'originalPrice' => 439000, 'listDate' => '2026-11-01', 'updatedOn' => $updated, 'daysOnMarket' => 20,
        'address' => ['streetNumber' => '14', 'streetName' => 'Oak', 'streetSuffix' => 'Ln', 'city' => 'Houston', 'state' => 'TX', 'zip' => '77005', 'neighborhood' => 'West U'],
        'map' => ['latitude' => '29.7174', 'longitude' => '-95.4180'],
        'details' => ['numBedrooms' => 3, 'numBathrooms' => 2, 'sqft' => '1800-2000', 'yearBuilt' => '1998', 'propertyType' => 'Single Family Residence', 'description' => 'Bright home.'],
        'agents' => [['agentId' => 'A12', 'name' => 'Jordan Lee']], 'office' => ['brokerageName' => 'Lee Realty'],
        'images' => ['sandbox/IMG-1.jpg', 'sandbox/IMG-2.jpg'], 'photoCount' => 2];
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));
    Storage::fake('public');
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $services = app(OutsideServices::class);
    $services->save(0, 'idx_repliers', 'repliers', ['api_key' => 'rep-test-key'], [], null, $this->staff);
    $services->setMode(0, 'idx_repliers', 'test', $this->staff);
    $this->source = MlsSource::query()->create(['key' => 'har-repliers', 'name' => 'HAR via Repliers', 'states' => ['TX'], 'provider' => 'repliers',
        'base_url' => MlsSource::PRESETS['repliers']['base_url'], 'originating_system' => '7', 'status' => 'active']);
});

it('turns a Repliers listing into a RESO record the sync understands', function (): void {
    $r = RepliersFeed::toReso(repliersListing('HAR123'));
    expect($r)->toMatchArray(['ListingKey' => 'HAR123', 'StandardStatus' => 'Active', 'PropertyType' => 'Residential', 'StreetName' => 'Oak Ln', 'City' => 'Houston',
        'ListPrice' => 425000.0, 'LivingArea' => 1800.0, 'Latitude' => 29.7174, 'ListAgentMlsId' => 'A12', 'ListOfficeName' => 'Lee Realty', 'PhotosCount' => 2])
        ->and($r['Media'][0]['MediaURL'])->toBe('https://cdn.repliers.io/sandbox/IMG-1.jpg');

    expect(RepliersFeed::status(['status' => 'U', 'lastStatus' => 'Sld']))->toBe('Closed')
        ->and(RepliersFeed::status(['status' => 'U', 'lastStatus' => 'Exp']))->toBe('Expired')
        ->and(RepliersFeed::status(['status' => 'A', 'lastStatus' => 'Sc']))->toBe('Active Under Contract')
        ->and(RepliersFeed::status(['status' => 'U', 'lastStatus' => 'Ter']))->toBe('Canceled');
});

it('syncs a board from Repliers page by page with RightAlly\'s key, oldest change first', function (): void {
    Http::fake([
        'api.repliers.io/listings*pageNum=1*' => Http::response(['page' => 1, 'numPages' => 2, 'count' => 2, 'listings' => [repliersListing('HAR1')]]),
        'api.repliers.io/listings*pageNum=2*' => Http::response(['page' => 2, 'numPages' => 2, 'count' => 2, 'listings' => [repliersListing('HAR2', 'U', 'Sld', '2026-11-20T11:00:00Z')]]),
        'cdn.repliers.io/*' => Http::response('img', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $result = app(ListingSync::class)->run($this->source);
    expect($result['result'])->toBe('ok')
        ->and(Listing::query()->where('mls_source_id', $this->source->id)->count())->toBe(2)
        ->and(Listing::query()->where('listing_key', 'HAR2')->value('standard_status'))->toBe('Closed')
        ->and(Listing::query()->where('listing_key', 'HAR1')->value('list_price_cents'))->toBe(42_500_000);
    Http::assertSent(fn ($r): bool => str_starts_with($r->url(), 'https://api.repliers.io/listings?boardId=7&status=A&status=U&sortBy=updatedOnAsc')
        && $r->hasHeader('REPLIERS-API-KEY', 'rep-test-key'));

    // The next run asks only for what changed since.
    app(ListingSync::class)->run($this->source->refresh());
    Http::assertSent(fn ($r): bool => str_contains(urldecode($r->url()), 'minUpdatedOn=2026-11-20T10:58:00Z'));
});

it('checks the Repliers key and stops without one', function (): void {
    Http::fake(fn ($r) => $r->hasHeader('REPLIERS-API-KEY', 'bad') ? Http::response(['message' => 'no'], 401) : Http::response(['listings' => []]));
    expect(app(ConnectionCheck::class)->run('repliers', 'test', ['api_key' => 'good'])['ok'])->toBeTrue()
        ->and(app(ConnectionCheck::class)->run('repliers', 'test', ['api_key' => 'bad'])['ok'])->toBeFalse();

    app(OutsideServices::class)->setMode(0, 'idx_repliers', 'off', $this->staff);
    expect(app(RepliersFeed::class)->test($this->source))->toContain('Outside services');
});
