<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ads\Models\Campaign;
use Modules\Ads\Social\SocialPost;
use Modules\Ads\Social\SocialTarget;
use Modules\Ads\Studio\Design;
use Modules\Ads\Studio\StudioVideo;
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

/* Sprint C4c: Meta ads made from Ally Studio — a carousel or a Reel as the ad, a promoted Facebook post — and instant-form
   lead ads (own account, leads into the CRM through Facebook Lead Ads), all still Housing ads with a place-only audience. */

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
    config(['listings.cdn_url' => 'https://cdn.acme.test']);
    $this->design = Design::query()->create(['user_id' => $this->jane->id, 'batch' => 'b1', 'moment' => 'just_listed', 'listing_ulid' => $this->janes->ulid, 'collection' => 'noir',
        'variant' => 'noir', 'content' => ['headline' => '201 Pine Rd'], 'status' => 'ready']);
    $this->design->forceFill(['files' => ['portrait' => 'studio/t/d-portrait.jpg', 'square' => 'studio/t/d-square.jpg', 'story' => 'studio/t/d-story.jpg',
        'slides' => ['studio/t/d-slide-2.jpg', 'studio/t/d-slide-3.jpg']]])->save();
    $this->reel = StudioVideo::query()->create(['user_id' => $this->jane->id, 'design_id' => $this->design->id, 'listing_ulid' => $this->janes->ulid, 'status' => 'ready']);
    $this->reel->forceFill(['path' => 'studio/t/videos/r.mp4', 'poster_path' => 'studio/t/videos/r.jpg'])->save();
    $this->post = SocialPost::query()->create(['user_id' => $this->jane->id, 'listing_ulid' => $this->janes->ulid, 'text' => "Just listed: 201 Pine Rd\nBright and open.",
        'image_url' => 'https://cdn.acme.test/x.jpg', 'status' => 'posted', 'post_at' => now()]);
    SocialTarget::query()->create(['post_id' => $this->post->id, 'connection_id' => 1, 'channel' => 'facebook', 'target_id' => '777', 'name' => 'Jane Doe Realtor', 'status' => 'posted'])
        ->forceFill(['external_id' => '777_9001'])->save();
});
/** @param  array<string, mixed>  $overrides */
function formatAd(object $t, array $overrides = []): array
{
    return $overrides + ['listing' => $t->janes->ulid, 'account' => '555', 'page' => '777', 'headline' => '3 bed home on Pine Rd',
        'body' => 'Light-filled 3 bed with a big yard. $501,000.', 'daily_budget' => 20, 'days' => 10, 'radius' => 15, 'agree' => true];
}

function metaFakes(array $extra = []): void
{
    Http::fake($extra + [
        'graph.facebook.com/v21.0/act_555/campaigns' => Http::response(['id' => 'c1']),
        'graph.facebook.com/v21.0/act_555/adsets' => Http::response(['id' => 's1']),
        'graph.facebook.com/v21.0/777?*' => Http::response(['access_token' => 'page-tok']),
        'graph.facebook.com/v21.0/777/leadgen_forms' => Http::response(['id' => 'form1']),
        'graph.facebook.com/v21.0/act_555/advideos' => Http::response(['id' => 'v1']),
        'graph.facebook.com/v21.0/v1?*' => Http::sequence()->push(['status' => ['video_status' => 'processing']])->push(['status' => ['video_status' => 'ready']]),
        'graph.facebook.com/v21.0/act_555/adcreatives' => Http::response(['id' => 'cr1']),
        'graph.facebook.com/v21.0/act_555/ads' => Http::response(['id' => 'a1']),
        'graph.facebook.com/v21.0/c1' => Http::response(['success' => true]),
    ]);
}

it('runs a Studio carousel as an instant-form lead ad: a Housing lead campaign, a form with no questions about the person, tagged for the CRM', function (): void {
    metaFakes();
    DB::connection(Connections::control())->table('connect_routes')->insert(['app_key' => 'facebook_leads', 'external_key' => '777', 'tenant_id' => $this->tenant->id,
        'connection_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->jane)->get('/ads/new?listing='.$this->janes->ulid.'&design='.$this->design->ulid)->assertInertia(fn (Assert $p) => $p->component('ads/new')
        ->where('chosen.studio_design', $this->design->ulid)->where('creatives.designs.0.slides', 3)->has('creatives.videos', 1)->has('creatives.posts', 1)->where('form_pages', ['777']));
    $this->actingAs($this->jane)->post('/ads', formatAd($this, ['studio_design' => $this->design->ulid, 'goal' => 'form']))->assertSessionHasErrors('privacy_url');
    $this->actingAs($this->jane)->post('/ads', formatAd($this, ['studio_design' => $this->design->ulid, 'goal' => 'form', 'privacy_url' => 'https://acme.test/privacy']))
        ->assertSessionHasNoErrors();

    $c = Campaign::query()->sole();
    expect($c->only(['status', 'format', 'goal', 'studio_design_id']))->toBe(['status' => 'active', 'format' => 'carousel', 'goal' => 'form', 'studio_design_id' => $this->design->id])
        ->and($c->images)->toHaveCount(3)->and($c->meta_ids['form'])->toBe('form1');
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'act_555/campaigns') && $r['objective'] === 'OUTCOME_LEADS' && $r['special_ad_categories'] === ['HOUSING']
        && str_ends_with((string) $r['name'], 'rightally-'.strtolower($c->ulid)));
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'act_555/adsets') && $r['optimization_goal'] === 'LEAD_GENERATION' && $r['destination_type'] === 'ON_AD'
        && $r['promoted_object'] === ['page_id' => '777'] && $r['targeting']['age_min'] === 18 && ! isset($r['targeting']['flexible_spec']));
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), '777/leadgen_forms') && $r['access_token'] === 'page-tok'
        && array_column($r['questions'], 'type') === ['FULL_NAME', 'EMAIL', 'PHONE', 'CUSTOM'] && $r['privacy_policy']['url'] === 'https://acme.test/privacy');
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'act_555/adcreatives')
        && count($r['object_story_spec']['link_data']['child_attachments'] ?? []) === 3
        && ($r['object_story_spec']['link_data']['call_to_action']['value']['lead_gen_form_id'] ?? null) === 'form1');
});

it('runs a Reel as a video ad, waiting for Meta to process it', function (): void {
    metaFakes();
    Sleep::fake();
    $this->actingAs($this->jane)->post('/ads', formatAd($this, ['studio_video' => $this->reel->ulid]))->assertSessionHasNoErrors();
    $c = Campaign::query()->sole();
    expect($c->only(['status', 'format', 'goal']))->toBe(['status' => 'active', 'format' => 'video', 'goal' => 'website'])->and($c->image_url)->toEndWith('r.jpg');
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'act_555/advideos') && $r['file_url'] === 'https://cdn.acme.test/studio/t/videos/r.mp4');
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'act_555/adcreatives') && ($r['object_story_spec']['video_data']['video_id'] ?? null) === 'v1'
        && ($r['object_story_spec']['video_data']['call_to_action']['type'] ?? null) === 'LEARN_MORE');
    Sleep::assertSleptTimes(1);
});

it('promotes a post already on the person\'s Page as it is, and keeps instant forms and Boost to what they can do', function (): void {
    metaFakes();
    $this->actingAs($this->jane)->post('/ads', formatAd($this, ['post' => $this->post->ulid, 'goal' => 'form', 'privacy_url' => 'https://acme.test/privacy']))
        ->assertSessionHasErrors('goal');
    $this->actingAs($this->jane)->post('/ads', formatAd($this, ['post' => $this->post->ulid, 'headline' => '', 'body' => '']))->assertSessionHasNoErrors();
    $c = Campaign::query()->sole();
    expect($c->only(['format', 'object_story_id', 'social_post_id', 'headline']))->toBe(['format' => 'post', 'object_story_id' => '777_9001', 'social_post_id' => $this->post->id,
        'headline' => 'Just listed: 201 Pine Rd']);
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'act_555/adcreatives') && ($r['object_story_id'] ?? null) === '777_9001');

    // Without Facebook Lead Ads listening to the Page, form leads would go nowhere: refused.
    $this->actingAs($this->jane)->post('/ads', formatAd($this, ['studio_design' => $this->design->ulid, 'goal' => 'form', 'privacy_url' => 'https://acme.test/privacy']))
        ->assertSessionHasErrors('goal');
    // Only the person's own Studio work.
    $this->actingAs($this->bob)->post('/ads', formatAd($this, ['listing' => $this->bobs->ulid, 'studio_design' => $this->design->ulid]))->assertSessionHasErrors();
    expect(Campaign::query()->count())->toBe(1);
});
