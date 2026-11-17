<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Display\ListingPresenter;
use Modules\Listings\Events\ListingsChanged;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\FeedThrottled;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Feed\ResoWebApi;
use Modules\Listings\Jobs\SyncMlsSource;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Models\RulesProfile;
use Modules\Listings\Sync\ListingSync;

require_once __DIR__.'/../Contracts/contract-helpers.php';

/* Sprint 17A: MLS ingestion (RESO Web API), display rules and MLS licence enforcement. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    app()->instance(ResoFeed::class, $this->feed = new FakeResoFeed);
    $this->source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'originating_system' => 'actris', 'credentials' => ['token' => 'secret-mls-token'], 'status' => 'setup']);
});

function reso(int $i, array $over = []): array
{
    return $over + ['ListingKey' => 'K'.$i, 'ListingId' => 'MLS'.$i, 'StandardStatus' => 'Active', 'PropertyType' => 'Residential', 'ListPrice' => 400000 + $i,
        'BedroomsTotal' => 3, 'BathroomsFull' => 2, 'LivingArea' => 1800, 'StreetNumber' => (string) $i, 'StreetName' => 'Oak St', 'City' => 'Austin', 'StateOrProvince' => 'tx',
        'PostalCode' => '78704', 'Latitude' => 30.25, 'Longitude' => -97.75, 'ListOfficeName' => 'Acme Realty', 'ListAgentFullName' => 'Ann Agent', 'PublicRemarks' => 'Lovely home.',
        'DaysOnMarket' => 12, 'ModificationTimestamp' => CarbonImmutable::parse('2026-05-01 00:00:00', 'UTC')->addMinutes($i)->format('Y-m-d\TH:i:s\Z'), 'CustomField' => 'kept'];
}

it('replicates a whole MLS a page at a time and resumes after a failure without losing or repeating records', function (): void {
    $this->feed->records = array_map(fn (int $i): array => reso($i), range(1, 450));
    $this->feed->failAfterPages = 2;
    $sync = app(ListingSync::class);

    expect($sync->run($this->source, 200))->toMatchArray(['kind' => 'full', 'result' => 'failed', 'changed' => 400]);
    $this->source->refresh();
    expect(Listing::query()->count())->toBe(400)->and($this->source->next_link)->not->toBeNull()->and($this->source->full_sync_done)->toBeFalse()
        ->and($this->source->backoff_until)->not->toBeNull();

    expect($sync->run($this->source)['result'])->toBe('waiting'); // backing off
    $this->travel(5)->minutes();
    expect($sync->run($this->source->refresh(), 200))->toMatchArray(['kind' => 'full', 'result' => 'ok', 'changed' => 50]);
    $this->source->refresh();
    expect(Listing::query()->count())->toBe(450)->and($this->source->full_sync_done)->toBeTrue()->and($this->source->status)->toBe('active')
        ->and($this->source->cursor)->toBe('2026-05-01T07:30:00Z')
        ->and(DB::connection(Connections::listings())->table('mls_sync_runs')->pluck('result')->all())->toBe(['failed', 'ok']);
});

it('maps RESO fields, keeps the raw record, and picks up changes with an overlap so nothing is missed', function (): void {
    Event::fake([ListingsChanged::class]);
    $this->feed->records = [reso(1), reso(2, ['LotSizeAcres' => 0.25, 'InternetAddressDisplayYN' => false, 'CloseDate' => '2026-04-30', 'OffMarketTimestamp' => null])];
    app(ListingSync::class)->run($this->source);
    $l = Listing::query()->where('listing_key', 'K1')->sole();
    expect($l->list_price_cents)->toBe(40000100)->and($l->state_or_province)->toBe('TX')->and($l->beds_total)->toBe(3)->and((float) $l->latitude)->toBe(30.25)
        ->and($l->modification_timestamp?->format('Y-m-d H:i:s'))->toBe('2026-05-01 00:01:00')
        ->and(Listing::query()->where('listing_key', 'K2')->sole()->lot_size_sqft)->toBe(10890)
        ->and(Listing::query()->where('listing_key', 'K2')->sole()->address_display_allowed)->toBeFalse()
        ->and(json_decode((string) DB::connection(Connections::listings())->table('listing_payloads')->where('listing_id', $l->id)->value('payload'), true)['CustomField'])->toBe('kept');

    // A price change and a new listing; K2 comes back again in the 2-minute overlap and is skipped.
    $this->feed->records = [reso(1, ['ListPrice' => 389000, 'ModificationTimestamp' => '2026-05-01T00:05:00Z']), reso(2), reso(3, ['ModificationTimestamp' => '2026-05-01T00:06:00Z'])];
    expect(app(ListingSync::class)->run($this->source->refresh()))->toMatchArray(['kind' => 'incremental', 'result' => 'ok', 'changed' => 2]);
    expect($l->refresh()->list_price_cents)->toBe(38900000)->and(Listing::query()->count())->toBe(3);
    Event::assertDispatched(ListingsChanged::class, fn (ListingsChanged $e): bool => $e->kind === 'changed' && count($e->listingIds) === 2);
});

it('backs off when the MLS says slow down and never goes over its hourly limit', function (): void {
    $this->feed->records = array_map(fn (int $i): array => reso($i), range(1, 30));
    $this->feed->throttle = true;
    expect(app(ListingSync::class)->run($this->source)['result'])->toBe('throttled');
    expect($this->source->refresh()->backoff_until?->diffInSeconds(CarbonImmutable::now(), true))->toBeGreaterThanOrEqual(120.0);

    $this->travel(3)->minutes();
    $this->source->forceFill(['rate_limit_per_hour' => 1])->save();
    RateLimiter::clear('mls-requests:'.$this->source->id);
    expect(app(ListingSync::class)->run($this->source->refresh(), 10))->toMatchArray(['result' => 'throttled', 'changed' => 10])
        ->and($this->source->refresh()->next_link)->not->toBeNull();
});

it('takes listings off the market when the MLS stops showing them, but never on an empty answer', function (): void {
    $this->feed->records = [reso(1), reso(2), reso(3)];
    app(ListingSync::class)->run($this->source);
    $this->feed->records = [reso(1), reso(2)];
    expect(app(ListingSync::class)->reconcile($this->source->refresh()))->toBe(1)
        ->and(Listing::query()->where('listing_key', 'K3')->sole()->standard_status)->toBe('Off Market');

    $this->feed->records = [];
    expect(fn () => app(ListingSync::class)->reconcile($this->source))->toThrow(RuntimeException::class, 'nothing was removed');
    expect(Listing::query()->whereNull('off_market_at')->count())->toBe(2);
});

it('shows listings only as the MLS rules allow, with attribution, disclaimer and freshness', function (): void {
    $profile = RulesProfile::query()->create(['name' => 'ACTRIS IDX', 'attribution_template' => 'Listing courtesy of {ListOfficeName}', 'disclaimer' => 'Data from ACTRIS, updated {LastUpdated}.',
        'sold_allowed' => true, 'sold_delay_days' => 30, 'suppressed_fields' => ['days_on_market'], 'freshness_minutes' => 60]);
    $this->source->forceFill(['rules_profile_id' => $profile->id])->save();
    $this->feed->records = [reso(1), reso(2, ['InternetEntireListingDisplayYN' => false]), reso(3, ['StandardStatus' => 'Closed', 'ClosePrice' => 395000, 'CloseDate' => '2026-05-20']),
        reso(4, ['StandardStatus' => 'Closed', 'ClosePrice' => 380000, 'CloseDate' => '2026-04-01']), reso(5, ['InternetAddressDisplayYN' => false])];
    app(ListingSync::class)->run($this->source);
    $get = fn (string $k): ?array => ListingPresenter::forDisplay(Listing::query()->where('listing_key', $k)->with('source.rulesProfile')->sole());

    expect($get('K1'))->toMatchArray(['price' => 400001, 'address' => '1 Oak St', 'days_on_market' => null, 'attribution' => 'Listing courtesy of Acme Realty',
        'disclaimer' => 'Data from ACTRIS, updated 06/01/2026 10:00 AM CDT.', 'stale' => false, 'indexable' => true])
        ->and($get('K2'))->toBeNull()   // the seller said no internet display
        ->and($get('K3'))->toBeNull()   // sold within the 30-day delay
        ->and($get('K4'))->toMatchArray(['price' => 380000, 'status' => 'Closed', 'indexable' => false])
        ->and($get('K5'))->toMatchArray(['address' => null, 'latitude' => null]);

    $this->travel(2)->hours();
    expect($get('K1')['stale'])->toBeTrue();
});

it('lets a brokerage see an MLS only while HQ has approved its licence, in that scope', function (): void {
    $this->feed->records = [reso(1), reso(2)];
    app(ListingSync::class)->run($this->source);
    $tenant = $this->onTenantHost('acme');
    $owner = User::factory()->owner()->create();
    $visible = fn (string $scope = 'idx'): int => Listing::query()->visibleTo($tenant->id, $scope)->count();

    $this->actingAs($owner)->get('/settings/mls')->assertOk()->assertInertia(fn (Assert $page) => $page->component('settings/mls')->where('sources.0.licence', null));
    $this->actingAs($owner)->post("/settings/mls/{$this->source->ulid}/request", ['broker_mls_id' => 'B123', 'office_mls_ids' => 'O1, O2'])->assertSessionHasNoErrors();
    $auth = MlsAuthorization::query()->sole();
    expect($auth->status)->toBe('pending')->and($auth->office_mls_ids)->toBe(['O1', 'O2'])->and($visible())->toBe(0);

    $hq = User::factory()->hqStaff()->make();
    app(MlsAccess::class)->approve($auth, ['idx'], '2027-06-01', 'IDX-2026-44', $hq);
    expect($visible())->toBe(2)->and($visible('vow'))->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2027-06-02 12:00', 'UTC'));
    expect($visible())->toBe(0); // expired, even before the daily job marks it
    $this->travelTo(CarbonImmutable::parse('2026-06-02 12:00', 'UTC'));
    app(MlsAccess::class)->revoke($auth->refresh(), 'Agreement ended', $hq);
    expect($visible())->toBe(0);
    $this->actingAs(User::factory()->create())->get('/settings/mls')->assertForbidden();
});

it('lets HQ add feeds, set write-only credentials, approve licences and see freshness', function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq')->assertSuccessful();
    $admin = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $hq = 'http://app.rightally.io';
    $brokerage = Tenant::factory()->withPlatformDomain()->create(['name' => 'Jones Realty']);
    MlsAuthorization::query()->create(['tenant_id' => $brokerage->id, 'mls_source_id' => $this->source->id, 'broker_mls_id' => 'B9', 'scopes' => ['idx'], 'status' => 'pending']);

    $this->actingAs($admin)->post($hq.'/hq/mls/sources', ['key' => 'har', 'name' => 'HAR', 'provider' => 'trestle', 'base_url' => 'https://api-trestle.corelogic.com/trestle/odata',
        'states' => ['tx'], 'timezone' => 'America/Chicago', 'sync_interval_minutes' => 5, 'rate_limit_per_hour' => 7200])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post($hq.'/hq/mls/sources', ['key' => 'har', 'name' => 'x', 'provider' => 'nope', 'base_url' => 'http://x', 'states' => [], 'timezone' => 'x',
        'sync_interval_minutes' => 0, 'rate_limit_per_hour' => 0])->assertSessionHasErrors(['key', 'provider', 'base_url', 'states']);
    $har = MlsSource::query()->where('key', 'har')->sole();

    $this->actingAs($admin)->post($hq."/hq/mls/sources/{$har->ulid}/credentials", ['client_id' => 'c', 'client_secret' => 'super-secret', 'token_url' => 'https://auth.example/token'])
        ->assertRedirect(); // password confirmation first
    expect($har->refresh()->credentials)->toBeNull();
    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->post($hq."/hq/mls/sources/{$har->ulid}/credentials",
        ['client_id' => 'c', 'client_secret' => 'super-secret', 'token_url' => 'https://auth.example/token'])->assertSessionHasNoErrors();
    expect($har->refresh()->credentials)->toBe(['client_id' => 'c', 'client_secret' => 'super-secret', 'token_url' => 'https://auth.example/token', 'scope' => 'api'])
        ->and(DB::connection(Connections::listings())->table('mls_sources')->where('key', 'har')->value('credentials'))->not->toContain('super-secret');

    // IX2: presets fill the MLS Grid and Trestle addresses; Bridge still needs its dataset address.
    $this->actingAs($admin)->post($hq.'/hq/mls/sources', ['key' => 'ntreis', 'name' => 'NTREIS', 'provider' => 'trestle', 'base_url' => '',
        'states' => ['tx'], 'timezone' => 'America/Chicago', 'sync_interval_minutes' => 5, 'rate_limit_per_hour' => 7200])->assertSessionHasNoErrors();
    $ntreis = MlsSource::query()->where('key', 'ntreis')->sole();
    expect($ntreis->base_url)->toBe(MlsSource::PRESETS['trestle']['base_url']);
    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->post($hq."/hq/mls/sources/{$ntreis->ulid}/credentials",
        ['client_id' => 'c2', 'client_secret' => 's2'])->assertSessionHasNoErrors();
    expect($ntreis->refresh()->credentials['token_url'] ?? null)->toBe(MlsSource::PRESETS['trestle']['token_url']);
    $this->actingAs($admin)->post($hq.'/hq/mls/sources', ['key' => 'brx', 'name' => 'Bridge X', 'provider' => 'bridge',
        'states' => ['tx'], 'timezone' => 'America/Chicago', 'sync_interval_minutes' => 5, 'rate_limit_per_hour' => 7200])->assertSessionHasErrors('base_url');

    $page = $this->actingAs($admin)->get($hq.'/hq/mls')->assertOk();
    expect(json_encode($page->viewData('page')))->not->toContain('super-secret')->not->toContain('secret-mls-token');
    $page->assertInertia(fn (Assert $p) => $p->component('hq/mls')->where('licences.0.brokerage', 'Jones Realty')->where('licences.0.status', 'pending')
        ->where('sources', fn ($s) => collect($s)->firstWhere('key', 'har')['has_credentials'] === true));

    $licence = MlsAuthorization::query()->sole();
    $this->actingAs($admin)->post($hq."/hq/mls/licences/{$licence->ulid}/approve", ['scopes' => ['idx', 'vow'], 'expires_on' => '2027-01-01'])->assertSessionHasNoErrors();
    expect($licence->refresh()->status)->toBe('active')->and($licence->scopes)->toBe(['idx', 'vow']);
    $this->actingAs($admin)->post($hq."/hq/mls/licences/{$licence->ulid}/approve", ['scopes' => []])->assertSessionHasErrors('scopes');

    Queue::fake();
    $this->actingAs($admin)->post($hq."/hq/mls/sources/{$this->source->ulid}/sync")->assertRedirect();
    Queue::assertPushed(SyncMlsSource::class);
    assertPageContract($this->actingAs($admin)->get($hq.'/hq/mls'), 'hq/mls');
});

it('queues syncs that are due', function (): void {
    Queue::fake();
    $this->source->forceFill(['last_success_at' => now()->subMinute(), 'full_sync_done' => true])->save();
    MlsSource::query()->create(['key' => 'due', 'name' => 'Due', 'states' => ['TX'], 'provider' => 'bridge', 'base_url' => 'https://api.bridgedataoutput.com/api/v2/OData/x',
        'credentials' => ['token' => 't'], 'status' => 'active', 'last_success_at' => now()->subMinutes(10)]);
    MlsSource::query()->create(['key' => 'paused', 'name' => 'Paused', 'states' => ['TX'], 'provider' => 'bridge', 'base_url' => 'https://x.example', 'credentials' => ['token' => 't'], 'status' => 'paused']);
    $this->artisan('listings:sync')->assertSuccessful();
    Queue::assertPushed(SyncMlsSource::class, 1);
});

it('talks RESO Web API: filters, paging on the same host only, OAuth tokens and 429s', function (): void {
    Http::fake([
        'auth.example/token' => Http::response(['access_token' => 'oauth-token', 'expires_in' => 3600]),
        'api.mlsgrid.com/v2/Property?*skip*' => Http::response(['value' => [reso(2)]]),
        'api.mlsgrid.com/v2/Property*' => Http::response(['value' => [reso(1)], '@odata.nextLink' => 'https://api.mlsgrid.com/v2/Property?$skip=1']),
        'trestle.example/odata/Property*' => Http::response(['value' => [], '@odata.nextLink' => 'https://evil.example/steal'], 200),
        'slow.example/*' => Http::response([], 429, ['Retry-After' => '90']),
    ]);
    $api = new ResoWebApi(app(Factory::class));
    $page = $api->changes($this->source, '2026-05-01T00:00:00Z', null);
    expect($page->records[0]['ListingKey'])->toBe('K1')->and($api->changes($this->source, null, $page->next)->records[0]['ListingKey'])->toBe('K2');
    Http::assertSent(fn (HttpRequest $r): bool => str_contains(urldecode($r->url()), "OriginatingSystemName eq 'actris' and ModificationTimestamp gt 2026-05-01T00:00:00Z")
        && str_contains(urldecode($r->url()), '$orderby=ModificationTimestamp asc') && str_contains(urldecode($r->url()), '$expand=Media') && $r->hasHeader('Authorization', 'Bearer secret-mls-token'));

    $trestle = new MlsSource(['key' => 't', 'provider' => 'trestle', 'base_url' => 'https://trestle.example/odata', 'credentials' => ['client_id' => 'c', 'client_secret' => 's', 'token_url' => 'https://auth.example/token']]);
    $trestle->id = 999;
    $first = $api->changes($trestle, null, null);
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'trestle.example') && $r->hasHeader('Authorization', 'Bearer oauth-token'));
    expect(fn () => $api->changes($trestle, null, $first->next))->toThrow(RuntimeException::class, 'another host');

    $slow = new MlsSource(['key' => 's', 'provider' => 'bridge', 'base_url' => 'https://slow.example/odata', 'credentials' => ['token' => 'x']]);
    try {
        $api->changes($slow, null, null);
        $this->fail('Expected a throttle');
    } catch (FeedThrottled $e) {
        expect($e->retryAfterSeconds)->toBe(90);
    }
});

it('sends the brokerage MLS page the data it needs', function (): void {
    $this->onTenantHost('acme');
    assertPageContract($this->actingAs(User::factory()->owner()->create())->get('/settings/mls'), 'settings/mls');
});
