<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ads\Google\GoogleAdsConnector;
use Modules\Ads\Google\GoogleCopy;
use Modules\Ads\Models\Campaign;
use Modules\Ads\Social\SocialPost;
use Modules\Ads\Social\SocialTarget;
use Modules\Ads\Studio\StudioVideo;
use Modules\Billing\Credits\CreditLedger;
use Modules\Connect\AppRegistry;
use Modules\Connect\Models\Connection;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Support\Connections;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Sync\ListingSync;
use Modules\Sites\SiteSetup;

/* Sprint C7d-2: listing ads on Google search and YouTube, from the agent's own Google Ads account or Boost on Google,
   with Google's housing ads policy built in (place only), and results, stop-loss and refunds as for Meta ads. */

const GADS = 'googleads.googleapis.com/v26/';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    Storage::fake('public');
    config(['services.google.client_id' => 'gid', 'services.google.client_secret' => 'gsecret', 'listings.cdn_url' => 'https://cdn.acme.test']);
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->jane = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    DB::table('roster_credentials')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'user_id' => $this->jane->id, 'kind' => 'mls',
        'state_code' => null, 'number' => 'AGT1', 'status' => 'current', 'created_at' => now(), 'updated_at' => now()]);
    $sites = app(SiteSetup::class);
    $sites->publish($sites->create('aurum', [], $this->owner), true, $this->owner);
    app()->instance(ResoFeed::class, $feed = new FakeResoFeed);
    $source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    $feed->records = [['ListingKey' => 'A1', 'ListingId' => 'MLS1', 'StandardStatus' => 'Active', 'PropertyType' => 'Residential', 'ListPrice' => 501000, 'BedroomsTotal' => 3,
        'StreetNumber' => '201', 'StreetName' => 'Pine Rd', 'City' => 'Austin', 'StateOrProvince' => 'TX', 'PostalCode' => '78704', 'Latitude' => 30.25, 'Longitude' => -97.75,
        'ListAgentMlsId' => 'AGT1', 'ModificationTimestamp' => '2026-05-20T00:01:00Z']];
    app(ListingSync::class)->run($source);
    Listing::query()->update(['cover_path' => 'listings/a1/cover.jpg']);
    $a = MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($a, ['idx'], null, null, User::factory()->hqStaff()->make());
    $this->home = Listing::query()->sole();

    $hq = User::factory()->make(['id' => 999999]);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('google_ads'), true, ['developer_token' => 'dev-tok'], null, $hq);
    $this->conn = Connection::query()->create(['app_key' => 'google_ads', 'owner_user_id' => $this->jane->id, 'label' => 'jane@gmail.test', 'status' => 'connected',
        'connected_by' => $this->jane->id, 'credentials' => ['access_token' => 'g-user-tok', 'refresh_token' => 'r', 'expires_at' => (string) (time() + 3000)],
        'settings' => ['ad_accounts' => [['id' => '1234567890', 'name' => 'Jane Ads', 'login' => '9990001111']]]]);
});

/** @param  array<string, mixed>  $overrides */
function googleAd(object $t, array $overrides = []): array
{
    return $overrides + ['network' => 'google', 'placement' => 'search', 'listing' => $t->home->ulid, 'account' => '1234567890', 'headline' => 'Light-filled 3 bed on Pine Rd',
        'body' => 'Big yard, new kitchen and a quiet street. See every photo and book a showing.', 'daily_budget' => 20, 'days' => 10, 'radius' => 20, 'agree' => true];
}

/** @return list<array<string, mixed>> the mutate operations Google was sent */
function googleOps(): array
{
    $sent = Http::recorded(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'googleAds:mutate'))->first();

    return $sent !== null ? (array) ($sent[0]->data()['mutateOperations'] ?? []) : [];
}

function googleMutateOk(): array
{
    return [GADS.'customers/*/googleAds:mutate' => Http::response(['mutateOperationResponses' => [
        ['campaignBudgetResult' => ['resourceName' => 'customers/1234567890/campaignBudgets/11']], ['campaignResult' => ['resourceName' => 'customers/1234567890/campaigns/22']],
        ['adGroupResult' => ['resourceName' => 'customers/1234567890/adGroups/33']], ['adGroupAdResult' => ['resourceName' => 'customers/1234567890/adGroupAds/33~44']]]])];
}

it('runs a Google search ad from the agent\'s own account: one atomic request, place-only targeting, keywords without ZIP codes', function (): void {
    Http::fake(googleMutateOk());
    $this->actingAs($this->jane)->get('/ads/new?listing='.$this->home->ulid.'&network=google')->assertInertia(fn (Assert $p) => $p->component('ads/new')
        ->where('google.connected', true)->where('google.accounts.0.id', '1234567890')->where('network', 'google'));
    $this->actingAs($this->jane)->post('/ads', googleAd($this))->assertRedirect('/ads');

    $c = Campaign::query()->sole();
    expect($c->only(['channel', 'format', 'status', 'billing', 'ad_account_id']))->toBe(['channel' => 'google', 'format' => 'search', 'status' => 'active', 'billing' => 'own', 'ad_account_id' => '1234567890'])
        ->and($c->google_ids['campaign'])->toBe('customers/1234567890/campaigns/22')
        ->and($c->link)->toContain('utm_source=google')->toContain('utm_medium=cpc')->toContain('utm_campaign=rightally-'.strtolower($c->ulid))
        ->and($c->keywords)->toContain('201 pine rd')->toContain('homes for sale austin')->each->not->toMatch('/\d{5}/');

    $ops = googleOps();
    $campaign = collect($ops)->pluck('campaignOperation.create')->filter()->sole();
    expect($campaign)->toMatchArray(['advertisingChannelType' => 'SEARCH', 'containsEuPoliticalAdvertising' => 'DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING',
        'startDateTime' => '2026-06-01 00:00:00', 'endDateTime' => '2026-06-10 23:59:59'])
        ->and($campaign['name'])->toEndWith('rightally-'.strtolower($c->ulid))
        ->and(collect($ops)->pluck('campaignBudgetOperation.create.amountMicros')->filter()->sole())->toBe('20000000');
    $criteria = collect($ops)->pluck('campaignCriterionOperation.create')->filter()->values();
    expect($criteria->pluck('proximity.radius')->filter()->sole())->toEqual(20)
        ->and($criteria->pluck('proximity.radiusUnits')->filter()->sole())->toBe('MILES');
    // Housing ads policy: never an age, gender, parental, income or audience criterion, anywhere.
    expect(json_encode($ops))->not->toContain('ageRange')->not->toContain('gender')->not->toContain('parentalStatus')->not->toContain('incomeRange')->not->toContain('userList')
        ->not->toContain('postalCode');
    $ad = collect($ops)->pluck('adGroupAdOperation.create.ad')->filter()->sole();
    expect($ad['finalUrls'])->toBe([$c->link])->and(count($ad['responsiveSearchAd']['headlines']))->toBeGreaterThanOrEqual(3)
        ->and(collect($ad['responsiveSearchAd']['headlines'])->pluck('text')->every(fn (string $h): bool => mb_strlen($h) <= 30))->toBeTrue()
        ->and(collect($ad['responsiveSearchAd']['descriptions'])->pluck('text')->every(fn (string $d): bool => mb_strlen($d) <= 90))->toBeTrue()
        ->and(collect($ad['responsiveSearchAd']['descriptions'])->pluck('text')->implode(' '))->toContain('Listed by Jane Doe');
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), '1234567890/googleAds:mutate') && $r->header('developer-token')[0] === 'dev-tok'
        && $r->header('login-customer-id')[0] === '9990001111' && $r->header('Authorization')[0] === 'Bearer g-user-tok');
});

it('refuses Fair Housing wording in a Google ad and never publishes it', function (): void {
    Http::fake();
    $this->actingAs($this->jane)->post('/ads', googleAd($this, ['body' => 'Perfect for young couples with no kids.']))->assertSessionHasErrors('body');
    expect(Campaign::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('runs a YouTube ad from a Reel that went out on the agent\'s channel, aimed at the city, with the brokerage logo', function (): void {
    $reel = StudioVideo::query()->create(['user_id' => $this->jane->id, 'listing_ulid' => $this->home->ulid, 'status' => 'ready']);
    $reel->forceFill(['path' => 'studio/t/videos/r.mp4', 'poster_path' => 'studio/t/videos/r.jpg'])->save();
    // Not on YouTube yet: refused.
    $this->actingAs($this->jane)->post('/ads', googleAd($this, ['placement' => 'youtube', 'studio_video' => $reel->ulid]))->assertSessionHasErrors('studio_video');

    $post = SocialPost::query()->create(['user_id' => $this->jane->id, 'listing_ulid' => $this->home->ulid, 'text' => 'Tour 201 Pine Rd', 'status' => 'posted', 'post_at' => now()]);
    $post->forceFill(['studio_video_id' => $reel->id])->save();
    SocialTarget::query()->create(['post_id' => $post->id, 'connection_id' => 1, 'channel' => 'youtube', 'target_id' => 'mine', 'name' => 'Jane', 'status' => 'posted'])
        ->forceFill(['external_id' => 'dQw4w9WgXcQ'])->save();
    // No logo or headshot yet: refused.
    $this->actingAs($this->jane)->post('/ads', googleAd($this, ['placement' => 'youtube', 'studio_video' => $reel->ulid]))->assertSessionHasErrors('studio_video');

    $img = imagecreatetruecolor(600, 200);
    ob_start();
    imagepng($img);
    Storage::disk('public')->put('brand/logo.png', (string) ob_get_clean());
    DB::connection(Connections::cell())->table('studio_brand_kits')->insert(['tenant_id' => $this->tenant->id, 'user_id' => null, 'logo_path' => 'brand/logo.png',
        'created_at' => now(), 'updated_at' => now()]);
    Http::fake([GADS.'geoTargetConstants:suggest' => Http::response(['geoTargetConstantSuggestions' => [
        ['geoTargetConstant' => ['resourceName' => 'geoTargetConstants/21137', 'name' => 'Texas', 'targetType' => 'State']],
        ['geoTargetConstant' => ['resourceName' => 'geoTargetConstants/1026201', 'name' => 'Austin', 'targetType' => 'City']]]])] + googleMutateOk());
    $this->actingAs($this->jane)->get('/ads/new?listing='.$this->home->ulid)->assertInertia(fn (Assert $p) => $p->where('creatives.videos.0.youtube', true));
    $this->actingAs($this->jane)->post('/ads', googleAd($this, ['placement' => 'youtube', 'studio_video' => $reel->ulid]))->assertSessionHasNoErrors();

    $c = Campaign::query()->sole();
    expect($c->only(['channel', 'format', 'youtube_video_id', 'status']))->toBe(['channel' => 'google', 'format' => 'video', 'youtube_video_id' => 'dQw4w9WgXcQ', 'status' => 'active'])
        ->and($c->link)->toContain('utm_source=youtube')->toContain('utm_medium=video');
    $ops = googleOps();
    expect(collect($ops)->pluck('campaignOperation.create.advertisingChannelType')->filter()->sole())->toBe('DEMAND_GEN')
        ->and(collect($ops)->pluck('adGroupOperation.create.demandGenAdGroupSettings.channelControls.selectedChannels')->filter()->sole())
        ->toMatchArray(['youtubeInStream' => true, 'youtubeShorts' => true, 'gmail' => false, 'display' => false, 'discover' => false])
        ->and(collect($ops)->pluck('adGroupCriterionOperation.create.location.geoTargetConstant')->filter()->sole())->toBe('geoTargetConstants/1026201')
        ->and(collect($ops)->pluck('assetOperation.create.youtubeVideoAsset.youtubeVideoId')->filter()->sole())->toBe('dQw4w9WgXcQ')
        ->and(collect($ops)->pluck('assetOperation.create.imageAsset.data')->filter()->sole())->not->toBe('');
    $ad = collect($ops)->pluck('adGroupAdOperation.create.ad.demandGenVideoResponsiveAd')->filter()->sole();
    expect($ad['businessName']['text'])->toBe(GoogleCopy::fit($this->tenant->name, 25))->and($ad['videos'][0]['asset'])->toEndWith('/assets/-4');
    expect(json_encode($ops))->not->toContain('proximity')->not->toContain('ageRange')->not->toContain('gender');
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'geoTargetConstants:suggest') && $r['locationNames']['names'] === ['Austin, TX']);
});

it('runs Google ads with RightAlly credits from RightAlly\'s account, and refunds what Google did not spend', function (): void {
    $hq = User::factory()->make(['id' => 999999]);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('rightally_boost'), true, ['ad_account_id' => '999', 'page_id' => '888', 'access_token' => 'ra', 'margin_percent' => '15'], null, $hq);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('rightally_boost_google'), true, ['customer_id' => '555-000-1111', 'login_customer_id' => '5550009999', 'refresh_token' => 'ra-refresh'], null, $hq);
    DB::connection(Connections::control())->table('credit_settings')->insert(['credit_value_microcents' => 1_000_000, 'enforce' => true, 'created_at' => now(), 'updated_at' => now()]);
    app(CreditLedger::class)->savePolicy(false, null, $this->owner);
    app(CreditLedger::class)->add($this->jane->id, 30_000, 'adjust', 'Test credits', $this->owner);
    Cache::forget('ads:boost:google-token');
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'ra-google-tok', 'expires_in' => 3599]),
        GADS.'customers/5550001111/googleAds:search' => Http::response(['results' => [['metrics' => ['costMicros' => '40000000', 'impressions' => '9000', 'clicks' => '120', 'conversions' => 0.0]]]])]
        + googleMutateOk());

    $this->actingAs($this->jane)->get('/ads/new?listing='.$this->home->ulid)->assertInertia(fn (Assert $p) => $p->where('google.boost', true));
    $this->actingAs($this->jane)->post('/ads', googleAd($this, ['pay' => 'boost', 'account' => '']))->assertRedirect('/ads');
    $c = Campaign::query()->sole();
    expect($c->only(['channel', 'billing', 'ad_account_id', 'credits_reserved', 'status']))->toBe(['channel' => 'google', 'billing' => 'boost', 'ad_account_id' => '5550001111',
        'credits_reserved' => 23_000, 'status' => 'active']);
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), '5550001111/googleAds:mutate') && $r->header('Authorization')[0] === 'Bearer ra-google-tok'
        && $r->header('login-customer-id')[0] === '5550009999');
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://oauth2.googleapis.com/token' && $r['refresh_token'] === 'ra-refresh' && $r['client_secret'] === 'gsecret');

    // Hourly: results; past its end date it ends and gives back what wasn't spent ($40 + 15% = 4,600 used).
    $this->travelTo(CarbonImmutable::parse('2026-06-12 15:00', 'UTC'));
    $this->artisan('ads:refresh-boosts')->assertSuccessful();
    expect($c->refresh()->only(['spend_cents', 'impressions', 'clicks', 'status', 'credits_refunded']))
        ->toBe(['spend_cents' => 4000, 'impressions' => 9000, 'clicks' => 120, 'status' => 'ended', 'credits_refunded' => 18_400])
        ->and(app(CreditLedger::class)->balanceOf($this->jane->id))->toBe(25_400);
});

it('lists the accounts a Google login can run ads from, refreshes results, and pauses with the stop-loss', function (): void {
    Http::fake(googleMutateOk());
    $this->actingAs($this->jane)->post('/ads', googleAd($this, ['days' => 4, 'daily_budget' => 10]));
    $c = Campaign::query()->sole();

    Http::fake([
        GADS.'customers:listAccessibleCustomers' => Http::response(['resourceNames' => ['customers/9990001111']]),
        GADS.'customers/9990001111/googleAds:search' => Http::response(['results' => [
            ['customerClient' => ['id' => '9990001111', 'descriptiveName' => 'Jane MCC', 'manager' => true, 'status' => 'ENABLED', 'level' => '0']],
            ['customerClient' => ['id' => '1234567890', 'descriptiveName' => 'Jane Ads', 'manager' => false, 'status' => 'ENABLED', 'level' => '1']],
            ['customerClient' => ['id' => '1112223333', 'descriptiveName' => 'Old', 'manager' => false, 'status' => 'CANCELED', 'level' => '1']]]]),
        GADS.'customers/1234567890/googleAds:search' => Http::response(['results' => [['metrics' => ['costMicros' => '25000000', 'impressions' => '3000', 'clicks' => '41', 'conversions' => 0]]]]),
        GADS.'customers/1234567890/googleAds:mutate' => Http::response(['results' => [['resourceName' => 'customers/1234567890/campaigns/22']]]),
    ]);
    $out = app(GoogleAdsConnector::class)->sync($this->conn->data());
    expect($out['updated'])->toBe(1)
        ->and($this->conn->refresh()->settings['ad_accounts'])->toBe([['id' => '1234567890', 'name' => 'Jane Ads', 'login' => '9990001111']])
        ->and($c->refresh()->only(['spend_cents', 'clicks', 'status']))->toBe(['spend_cents' => 2500, 'clicks' => 41, 'status' => 'paused'])
        ->and($c->stopped_reason)->toContain('half the budget');
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'googleAds:mutate')
        && ($r['mutateOperations'][0]['campaignOperation']['update'] ?? null) === ['resourceName' => 'customers/1234567890/campaigns/22', 'status' => 'PAUSED']
        && ($r['mutateOperations'][0]['campaignOperation']['updateMask'] ?? null) === 'status');

    // The agent runs it again from the list.
    $this->actingAs($this->jane)->post('/ads/'.$c->ulid.'/resume')->assertSessionHasNoErrors();
    expect($c->refresh()->status)->toBe('active');
    $this->actingAs($this->jane)->get('/ads')->assertInertia(fn (Assert $p) => $p->where('campaigns.0.network', 'Google search')->where('google_connected', true));
});

it('shows a Google failure on the ad, and nothing is half-made', function (): void {
    Http::fake([GADS.'customers/*/googleAds:mutate' => Http::response(['error' => ['message' => 'Request contains an invalid argument.',
        'details' => [['errors' => [['message' => 'The account is not enabled.']]]]]], 400)]);
    $this->actingAs($this->jane)->post('/ads', googleAd($this))->assertRedirect('/ads');
    expect(Campaign::query()->sole()->only(['status', 'error']))->toBe(['status' => 'failed', 'error' => 'Google: The account is not enabled.']);
});
