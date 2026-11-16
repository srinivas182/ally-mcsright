<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ads\Boost;
use Modules\Ads\Models\Campaign;
use Modules\Ads\StopLossPaused;
use Modules\Billing\Credits\CreditLedger;
use Modules\Connect\AppRegistry;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Support\Connections;
use Modules\Crm\Models\Lead;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Sync\ListingSync;
use Modules\Sites\SiteSetup;

/* Sprint C4b: RightAlly Boost — listing ads paid with credits from RightAlly's ad account, margin set by HQ,
   unspent credits refunded, stop-loss, and results with the leads and commission they brought. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->jane = User::factory()->create(['first_name' => 'Jane']);
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

    app(AppRegistry::class)->save(app(AppCatalog::class)->find('rightally_boost'), true,
        ['ad_account_id' => '999', 'page_id' => '888', 'access_token' => 'ra-system-token', 'margin_percent' => '15'], null, User::factory()->make(['id' => 999999]));
    DB::connection(Connections::control())->table('credit_settings')->insert(['credit_value_microcents' => 1_000_000, 'enforce' => true, 'created_at' => now(), 'updated_at' => now()]);
    app(CreditLedger::class)->savePolicy(false, null, $this->owner); // agents pay from their own credits
    app(CreditLedger::class)->add($this->jane->id, 30_000, 'adjust', 'Test credits', $this->owner);
});

function boostInput(object $t, array $overrides = []): array
{
    return $overrides + ['pay' => 'boost', 'listing' => $t->home->ulid, 'headline' => '3 bed home on Pine Rd',
        'body' => 'Light-filled 3 bed with a big yard. See the photos and book a showing.', 'daily_budget' => 10, 'days' => 10, 'radius' => 20, 'agree' => true, 'stop_loss' => true];
}

function fakeMetaPublish(): void
{
    Http::fake([
        'graph.facebook.com/v21.0/act_999/campaigns' => Http::response(['id' => 'c1']), 'graph.facebook.com/v21.0/act_999/adsets' => Http::response(['id' => 's1']),
        'graph.facebook.com/v21.0/act_999/adcreatives' => Http::response(['id' => 'cr1']), 'graph.facebook.com/v21.0/act_999/ads' => Http::response(['id' => 'a1']),
        'graph.facebook.com/v21.0/c1' => Http::response(['success' => true]),
    ]);
}

it('prices a boost from the spend plus HQ\'s margin, which HQ can change', function (): void {
    expect(app(Boost::class)->marginBps())->toBe(1500)->and(app(Boost::class)->credits(10_000, 1500))->toBe(11_500); // $100 + 15% = $115 = 11,500 one-cent credits
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('rightally_boost'), true, ['margin_percent' => '5'], null, User::factory()->make(['id' => 999999]));
    expect(app(Boost::class)->marginBps())->toBe(500);
    $this->actingAs($this->jane)->get('/ads/new?listing='.$this->home->ulid)->assertInertia(fn (Assert $p) => $p->where('connected', false)
        ->where('boost.margin_percent', 5)->where('boost.credits_per_dollar', 105)->where('boost.balance', 30_000));
});

it('runs a boost from RightAlly\'s ad account, reserving the agent\'s credits', function (): void {
    fakeMetaPublish();
    $this->actingAs($this->jane)->post('/ads', boostInput($this))->assertRedirect('/ads');

    $c = Campaign::query()->sole();
    expect($c->only(['billing', 'status', 'ad_account_id', 'page_id', 'margin_bps', 'credits_reserved', 'radius_miles']))
        ->toBe(['billing' => 'boost', 'status' => 'active', 'ad_account_id' => '999', 'page_id' => '888', 'margin_bps' => 1500, 'credits_reserved' => 11_500, 'radius_miles' => 20])
        ->and(app(CreditLedger::class)->balanceOf($this->jane->id))->toBe(18_500);
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'act_999/campaigns') && $r->header('Authorization')[0] === 'Bearer ra-system-token'
        && $r['special_ad_categories'] === ['HOUSING']);
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'act_999/adcreatives')
        && $r['object_story_spec']['page_id'] === '888' && $r['object_story_spec']['link_data']['description'] === 'Listed by '.$this->jane->name.', '.$this->tenant->name);
});

it('refuses a boost the agent can\'t afford, and gives every credit back if Meta refuses it', function (): void {
    Http::fake();
    $this->actingAs($this->jane)->post('/ads', boostInput($this, ['daily_budget' => 50, 'days' => 10]))->assertSessionHasErrors('pay'); // 57,500 credits
    expect(Campaign::query()->count())->toBe(0)->and(app(CreditLedger::class)->balanceOf($this->jane->id))->toBe(30_000);
    Http::assertNothingSent();

    Http::fake(['graph.facebook.com/v21.0/act_999/campaigns' => Http::response(['error' => ['message' => 'Ad account disabled']], 400)]);
    $this->actingAs($this->jane)->post('/ads', boostInput($this))->assertRedirect('/ads');
    expect(Campaign::query()->sole()->only(['status', 'credits_refunded']))->toBe(['status' => 'failed', 'credits_refunded' => 11_500])
        ->and(app(CreditLedger::class)->balanceOf($this->jane->id))->toBe(30_000);
});

it('gives back unspent credits when a boost ends, using the margin it was bought with', function (): void {
    fakeMetaPublish();
    $this->actingAs($this->jane)->post('/ads', boostInput($this));
    $c = Campaign::query()->sole();
    Http::fake(['graph.facebook.com/v21.0/c1/insights*' => Http::response(['data' => [['spend' => '40.00', 'impressions' => '9000', 'clicks' => '120']]]),
        'graph.facebook.com/*' => Http::response(['success' => true])]);
    $this->artisan('ads:refresh-boosts')->assertSuccessful();
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('rightally_boost'), true, ['margin_percent' => '25'], null, User::factory()->make(['id' => 999999]));

    $this->actingAs($this->jane)->post('/ads/'.$c->ulid.'/end')->assertSessionHasNoErrors();
    // Spent $40 → 4,600 credits at 15%; 11,500 − 4,600 = 6,900 back.
    expect($c->fresh()?->only(['status', 'spend_cents', 'credits_refunded']))->toBe(['status' => 'ended', 'spend_cents' => 4000, 'credits_refunded' => 6900])
        ->and(app(CreditLedger::class)->balanceOf($this->jane->id))->toBe(25_400);
    $this->artisan('ads:refresh-boosts'); // refunds only once
    expect(app(CreditLedger::class)->balanceOf($this->jane->id))->toBe(25_400);
});

it('pauses with the stop-loss when half the budget brings no lead, and tells the agent', function (): void {
    fakeMetaPublish();
    $this->actingAs($this->jane)->post('/ads', boostInput($this));
    Http::fake(['graph.facebook.com/v21.0/c1/insights*' => Http::response(['data' => [['spend' => '55.00', 'impressions' => '9000', 'clicks' => '20']]]),
        'graph.facebook.com/*' => Http::response(['success' => true])]);
    $this->artisan('ads:refresh-boosts');

    $c = Campaign::query()->sole();
    expect($c->status)->toBe('paused')->and($c->stopped_reason)->toContain('half the budget');
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'v21.0/c1') && $r['status'] === 'PAUSED');
    Notification::assertSentTo($this->jane, StopLossPaused::class);
});

it('shows the leads and commission an ad brought', function (): void {
    fakeMetaPublish();
    $this->actingAs($this->jane)->post('/ads', boostInput($this));
    $c = Campaign::query()->sole();
    auth()->logout();
    $this->post('http://acme.rightally.site/_lead', ['name' => 'Rosa Diaz', 'email' => 'rosa@x.test', 'listing' => $this->home->ulid, 'return' => '/homes/'.$this->home->ulid,
        't' => Crypt::encryptString((string) (time() - 30)), 'utm_source' => 'facebook', 'utm_medium' => 'paid_social',
        'utm_campaign' => 'rightally-'.strtolower($c->ulid)])->assertRedirect();
    $lead = Lead::query()->sole();
    foreach ([['closed', 1_500_000], ['under_contract', 900_000]] as [$status, $gci]) {
        DB::table('tx_deals')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'type' => 'buyer', 'status' => $status, 'agent_user_id' => $this->jane->id,
            'contact_id' => $lead->contact_id, 'street' => '1 A St', 'state_code' => 'TX', 'gci_cents' => $gci, 'created_at' => now(), 'updated_at' => now()]);
    }
    Http::fake(['graph.facebook.com/v21.0/c1/insights*' => Http::response(['data' => [['spend' => '30.00', 'impressions' => '9000', 'clicks' => '120']]]),
        'graph.facebook.com/*' => Http::response(['success' => true])]);
    $this->artisan('ads:refresh-boosts');

    $this->actingAs($this->jane)->get('/ads')->assertInertia(fn (Assert $p) => $p->where('campaigns.0.leads', 1)
        ->where('campaigns.0.earned_commission', 15000)->where('campaigns.0.pending_commission', 9000)->where('campaigns.0.return', 500));
    expect(Campaign::query()->sole()->status)->toBe('active'); // a lead came in: no stop-loss
});

/** A signed Facebook Lead Ads webhook for RightAlly's Boost Page (specs/ads/04). */
function boostFormLead(string $form, string $leadgen): TestResponse
{
    $raw = (string) json_encode(['object' => 'page', 'entry' => [['id' => '888', 'changes' => [['field' => 'leadgen', 'value' => ['page_id' => '888', 'leadgen_id' => $leadgen, 'form_id' => $form]]]]]]);

    return test()->call('POST', 'https://auth.rightally.app/connect/apps/facebook_leads/webhook', [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'app-secret')], $raw);
}

it('runs an instant-form Boost ad on RightAlly\'s Page and hands each lead only to the brokerage and agent whose ad it is', function (): void {
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('facebook_leads'), true,
        ['client_id' => '123', 'client_secret' => 'app-secret', 'verify_token' => 'vt'], null, User::factory()->make(['id' => 999999]));
    Http::fake([
        'graph.facebook.com/v21.0/act_999/campaigns' => Http::response(['id' => 'c1']), 'graph.facebook.com/v21.0/act_999/adsets' => Http::response(['id' => 's1']),
        'graph.facebook.com/v21.0/888?*' => Http::response(['access_token' => 'boost-page-tok']),
        'graph.facebook.com/v21.0/888/leadgen_forms' => Http::response(['id' => '4401']),
        'graph.facebook.com/v21.0/888/subscribed_apps' => Http::response(['success' => true]),
        'graph.facebook.com/v21.0/act_999/adcreatives' => Http::response(['id' => 'cr1']), 'graph.facebook.com/v21.0/act_999/ads' => Http::response(['id' => 'a1']),
        'graph.facebook.com/v21.0/c1' => Http::response(['success' => true]),
        'graph.facebook.com/v21.0/5501?*' => Http::response(['field_data' => [['name' => 'full_name', 'values' => ['Rosa Diaz']], ['name' => 'email', 'values' => ['rosa@x.test']],
            ['name' => 'phone_number', 'values' => ['+15125550101']], ['name' => 'what_would_you_like?', 'values' => ['A showing']]]]),
    ]);
    // Instant forms with credits need the privacy link, nothing else.
    $this->actingAs($this->jane)->post('/ads', boostInput($this, ['goal' => 'form']))->assertSessionHasErrors('privacy_url');
    $this->actingAs($this->jane)->post('/ads', boostInput($this, ['goal' => 'form', 'privacy_url' => 'https://acme.test/privacy']))->assertRedirect('/ads');
    $c = Campaign::query()->sole();
    expect($c->only(['billing', 'status', 'goal']))->toBe(['billing' => 'boost', 'status' => 'active', 'goal' => 'form'])
        ->and(DB::connection(Connections::control())->table('ads_boost_forms')->where('form_id', '4401')->sole())
        ->tenant_id->toBe($this->tenant->id)->campaign_id->toBe($c->id);
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), '888/subscribed_apps') && ($r->data()['subscribed_fields'] ?? null) === 'leadgen'
        && ($r->data()['access_token'] ?? null) === 'boost-page-tok');

    boostFormLead('4401', '5501')->assertOk();
    boostFormLead('4401', '5501')->assertOk(); // Meta retries: still one lead
    $lead = Lead::query()->sole();
    expect($lead->only(['external_id', 'source', 'assigned_user_id']))->toBe(['external_id' => '5501', 'source' => 'facebook', 'assigned_user_id' => $this->jane->id])
        ->and($lead->utm['utm_campaign'] ?? null)->toBe('rightally-'.strtolower($c->ulid))
        ->and($lead->message)->toContain('What Would You Like?: A showing');
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/5501?') && str_contains($r->url(), 'access_token=boost-page-tok'));

    // A form RightAlly doesn't know, or another Page nobody connected: nothing is taken.
    boostFormLead('9999', '5502')->assertOk();
    expect(Lead::query()->count())->toBe(1);
});
