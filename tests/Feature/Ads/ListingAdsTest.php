<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ads\AdCopy;
use Modules\Ads\Models\Campaign;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Connect\AppRegistry;
use Modules\Connect\Models\Connection;
use Modules\Connect\SyncRunner;
use Modules\Core\Connect\AppCatalog;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Sync\ListingSync;
use Modules\Sites\SiteSetup;
use Tests\Fixtures\FakeAiProvider;

/* Sprint C4a: listing ads with AI, published to Facebook and Instagram from the agent's own Meta ad account,
   as Housing ads (place-only audience, at least 15 miles), with results back in RightAlly. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->jane = User::factory()->create(['first_name' => 'Jane']);
    $this->bob = User::factory()->create(['first_name' => 'Bob']);
    foreach ([[$this->jane, 'AGT1'], [$this->bob, 'AGT2']] as [$u, $mls]) {
        DB::table('roster_credentials')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'user_id' => $u->id, 'kind' => 'mls',
            'state_code' => null, 'number' => $mls, 'status' => 'current', 'created_at' => now(), 'updated_at' => now()]);
    }
    $sites = app(SiteSetup::class);
    $sites->publish($sites->create('aurum', [], $this->owner), true, $this->owner);
    app()->instance(ResoFeed::class, $feed = new FakeResoFeed);
    $source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    $home = fn (int $i, string $agent): array => ['ListingKey' => 'A'.$i, 'ListingId' => 'MLS'.$i, 'StandardStatus' => 'Active', 'PropertyType' => 'Residential',
        'ListPrice' => 500000 + $i * 1000, 'BedroomsTotal' => 3, 'StreetNumber' => (string) (200 + $i), 'StreetName' => 'Pine Rd', 'City' => 'Austin', 'StateOrProvince' => 'TX',
        'PostalCode' => '78704', 'Latitude' => 30.25, 'Longitude' => -97.75, 'ListAgentMlsId' => $agent, 'ModificationTimestamp' => '2026-05-20T00:0'.$i.':00Z'];
    $feed->records = [$home(1, 'AGT1'), $home(2, 'AGT2')];
    app(ListingSync::class)->run($source);
    Listing::query()->update(['cover_path' => 'listings/a1/cover.jpg']);
    $a = MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($a, ['idx'], null, null, User::factory()->hqStaff()->make());
    $this->janes = Listing::query()->where('list_agent_mls_id', 'AGT1')->sole();
    $this->bobs = Listing::query()->where('list_agent_mls_id', 'AGT2')->sole();

    $hq = User::factory()->make(['id' => 999999]);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('facebook_leads'), true, ['client_id' => '123', 'client_secret' => 's', 'verify_token' => 'v'], null, $hq);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('meta_ads'), true, [], null, $hq);
    $this->conn = Connection::query()->create(['app_key' => 'meta_ads', 'owner_user_id' => $this->jane->id, 'label' => 'jane@fb.test', 'status' => 'connected', 'connected_by' => $this->jane->id,
        'credentials' => ['access_token' => 'user-tok', 'expires_at' => (string) (time() + 86400 * 50), 'long_lived' => '1'],
        'settings' => ['ad_accounts' => [['id' => '555', 'name' => 'Jane Ads']], 'pages' => [['id' => '777', 'name' => 'Jane Doe Realtor']]]]);
});

/** @param  array<string, mixed>  $overrides */
function adInput(object $t, array $overrides = []): array
{
    return $overrides + ['listing' => $t->janes->ulid, 'account' => '555', 'page' => '777', 'headline' => '3 bed home on Pine Rd',
        'body' => 'Light-filled 3 bed with a big yard. $501,000. See the photos and book a showing.', 'daily_budget' => 20, 'days' => 10, 'radius' => 15, 'agree' => true];
}

it('lets an agent advertise only their own listings', function (): void {
    $this->actingAs($this->jane)->get('/ads/new')->assertInertia(fn (Assert $p) => $p->component('ads/new')->where('connected', true)
        ->where('listings', fn ($l): bool => collect($l)->pluck('ulid')->all() === [$this->janes->ulid]));
    $this->actingAs($this->jane)->get('/ads/new?listing='.$this->bobs->ulid)->assertInertia(fn (Assert $p) => $p->where('listing', null));
    $this->actingAs($this->jane)->post('/ads', adInput($this, ['listing' => $this->bobs->ulid]))->assertSessionHasErrors('listing');
});

it('drafts the ad with AI and flags Fair Housing wording', function (): void {
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => AdCopy::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.ads_copy']);
    $ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $ai);
    $ai->respond((string) json_encode(['headline' => '3 bed near Zilker', 'body' => 'Perfect for families! Big yard and new kitchen.']));

    $this->actingAs($this->jane)->postJson('/ads/draft', ['listing' => $this->janes->ulid])->assertOk()
        ->assertJson(['headline' => '3 bed near Zilker', 'flags' => ['perfect for families']]);
    expect($ai->requests[0]->userMessage)->toContain('Pine Rd');
    $this->actingAs($this->jane)->post('/ads', adInput($this, ['body' => 'Perfect for families! Big yard.']))->assertSessionHasErrors('body');
    $this->actingAs($this->jane)->postJson('/ads/draft', ['listing' => $this->bobs->ulid])->assertNotFound();
});

it('publishes a Housing ad with a place-only audience, switching it on last', function (): void {
    Http::fake([
        'graph.facebook.com/v21.0/act_555/campaigns' => Http::response(['id' => 'c1']),
        'graph.facebook.com/v21.0/act_555/adsets' => Http::response(['id' => 's1']),
        'graph.facebook.com/v21.0/act_555/adcreatives' => Http::response(['id' => 'cr1']),
        'graph.facebook.com/v21.0/act_555/ads' => Http::response(['id' => 'a1']),
        'graph.facebook.com/v21.0/c1' => Http::response(['success' => true]),
    ]);
    $this->actingAs($this->jane)->post('/ads', adInput($this))->assertRedirect('/ads');

    $c = Campaign::query()->sole();
    expect($c->status)->toBe('active')->and($c->meta_ids)->toBe(['campaign' => 'c1', 'adset' => 's1', 'creative' => 'cr1', 'ad' => 'a1'])
        ->and($c->maxSpendCents())->toBe(20000)->and($c->link)->toStartWith('https://acme.rightally.site/homes/'.$this->janes->ulid.'/')->toContain('utm_source=facebook');
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'act_555/campaigns') && $r['special_ad_categories'] === ['HOUSING'] && $r['status'] === 'PAUSED');
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'act_555/adsets') && $r['daily_budget'] === 2000
        && $r['targeting'] === ['geo_locations' => ['custom_locations' => [['latitude' => 30.25, 'longitude' => -97.75, 'radius' => 15, 'distance_unit' => 'mile']]], 'age_min' => 18, 'age_max' => 65]);
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'v21.0/c1') && $r['status'] === 'ACTIVE');
    $this->actingAs($this->jane)->get('/ads')->assertInertia(fn (Assert $p) => $p->where('campaigns.0.status', 'active')->where('campaigns.0.max_spend', 200));
});

it('needs the person to agree to the budget, within limits', function (): void {
    Http::fake();
    $this->actingAs($this->jane)->post('/ads', adInput($this, ['agree' => false, 'daily_budget' => 2, 'days' => 90]))
        ->assertSessionHasErrors(['agree', 'daily_budget', 'days']);
    Http::assertNothingSent();
    expect(Campaign::query()->count())->toBe(0);
});

it('removes what it made and says why when Meta refuses the ad', function (): void {
    Http::fake([
        'graph.facebook.com/v21.0/act_555/campaigns' => Http::response(['id' => 'c1']),
        'graph.facebook.com/v21.0/act_555/adsets' => Http::response(['id' => 's1']),
        'graph.facebook.com/v21.0/act_555/adcreatives' => Http::response(['error' => ['message' => 'Invalid', 'error_user_msg' => 'The image could not be downloaded.']], 400),
        'graph.facebook.com/v21.0/c1' => Http::response(['success' => true]),
    ]);
    $this->actingAs($this->jane)->post('/ads', adInput($this))->assertRedirect('/ads');
    expect(Campaign::query()->sole()->only(['status', 'error']))->toBe(['status' => 'failed', 'error' => 'The image could not be downloaded.']);
    Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), 'v21.0/c1'));
});

it('pauses and resumes only for the person whose account it runs on, and brings results back', function (): void {
    $c = Campaign::query()->create(['user_id' => $this->jane->id, 'connection_id' => $this->conn->id, 'ad_account_id' => '555', 'page_id' => '777', 'listing_ulid' => $this->janes->ulid,
        'listing_label' => '201 Pine Rd', 'headline' => 'h', 'body' => 'b', 'image_url' => 'https://x/y.jpg', 'link' => 'https://x', 'latitude' => '30.25', 'longitude' => '-97.75',
        'radius_miles' => 15, 'daily_budget_cents' => 2000, 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-10', 'status' => 'active', 'meta_ids' => ['campaign' => 'c1']]);
    Http::fake(['graph.facebook.com/v21.0/c1/insights*' => Http::response(['data' => [['spend' => '42.50', 'impressions' => '5100', 'clicks' => '87',
        'actions' => [['action_type' => 'lead', 'value' => '3']]]]]), 'graph.facebook.com/*' => Http::response(['data' => [], 'success' => true])]);

    $this->actingAs($this->bob)->post('/ads/'.$c->ulid.'/pause')->assertNotFound();
    $this->actingAs($this->owner)->post('/ads/'.$c->ulid.'/pause')->assertSessionHasErrors('ad');
    $this->actingAs($this->jane)->post('/ads/'.$c->ulid.'/pause')->assertSessionHasNoErrors();
    expect($c->fresh()?->status)->toBe('paused');
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'v21.0/c1') && $r['status'] === 'PAUSED');

    app(SyncRunner::class)->run($this->conn->fresh());
    expect($c->fresh()?->only(['spend_cents', 'impressions', 'clicks', 'leads']))->toBe(['spend_cents' => 4250, 'impressions' => 5100, 'clicks' => 87, 'leads' => 3]);
});
