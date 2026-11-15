<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Ads\Mcp\StudioQueueTool;
use Modules\Ads\Social\SocialPost;
use Modules\Ads\Studio\Autopilot;
use Modules\Ads\Studio\Design;
use Modules\Ads\Studio\QueuedPost;
use Modules\Ads\Studio\Render\FakeRenderer;
use Modules\Ads\Studio\Render\StudioRenderer;
use Modules\Ads\Studio\StudioBriefItems;
use Modules\Ads\Studio\StudioPlans;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Billing\Credits\CreditLedger;
use Modules\Connect\AppRegistry;
use Modules\Connect\Models\Connection;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Support\Connections;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Feed\FakeResoFeed;
use Modules\Listings\Feed\ResoFeed;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\ListingMedia;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Listings\Sync\ListingSync;
use Modules\Sites\SiteSetup;
use Modules\Transactions\Models\Deal;
use Tests\Fixtures\FakeAiProvider;

/* Sprint SA2: Ally Studio Autopilot — moments become designed, captioned posts at good times; Approve first or
   Autopilot; once per moment; Fair Housing on captions; in the daily brief and Your AI Assistant. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    Storage::fake('public');
    config(['listings.cdn_url' => 'https://cdn.acme.test']);
    app()->instance(StudioRenderer::class, $this->renderer = new FakeRenderer);
    $this->tenant = $this->onTenantHost('acme');
    $this->tenant->forceFill(['timezone' => 'America/Chicago'])->save();
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive', 'last_name' => 'Owner']);
    $this->jane = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Agent']);
    DB::table('roster_credentials')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'user_id' => $this->jane->id, 'kind' => 'mls',
        'state_code' => null, 'number' => 'AGT1', 'status' => 'current', 'created_at' => now(), 'updated_at' => now()]);
    $sites = app(SiteSetup::class);
    $sites->publish($sites->create('aurum', [], $this->owner), true, $this->owner);
    app()->instance(ResoFeed::class, $feed = new FakeResoFeed);
    $source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://api.mlsgrid.com/v2',
        'credentials' => ['token' => 't'], 'status' => 'active']);
    $feed->records = [['ListingKey' => 'A1', 'ListingId' => 'MLS1', 'StandardStatus' => 'Active', 'PropertyType' => 'Residential', 'ListPrice' => 501000, 'BedroomsTotal' => 3,
        'BathroomsFull' => 2, 'BathroomsHalf' => 1, 'LivingArea' => 2140, 'StreetNumber' => '201', 'StreetName' => 'Pine Rd', 'City' => 'Austin', 'StateOrProvince' => 'TX',
        'PostalCode' => '78704', 'Latitude' => 30.25, 'Longitude' => -97.75, 'ListAgentMlsId' => 'AGT1', 'ListOfficeName' => 'Acme Realty', 'ModificationTimestamp' => '2026-05-20T00:01:00Z']];
    app(ListingSync::class)->run($source);
    $a = MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($a, ['idx'], null, null, User::factory()->hqStaff()->make());
    $this->home = Listing::query()->sole();
    $this->home->forceFill(['on_market_date' => '2026-05-30'])->save();
    // Four photos: the second is blurry (one flat colour), so the art director leaves it out.
    foreach (['sharp-1', 'flat', 'sharp-2', 'sharp-3'] as $i => $name) {
        $img = imagecreatetruecolor(1600, 1067);
        if ($name !== 'flat') {
            mt_srand($i);
            for ($k = 0; $k < 4000; $k++) {
                imagefilledrectangle($img, mt_rand(0, 1600), mt_rand(0, 1067), mt_rand(0, 1600), mt_rand(0, 1067), (int) imagecolorallocate($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
            }
        }
        ob_start();
        imagewebp($img);
        Storage::disk('public')->put("listings/a1/{$name}-1600.webp", (string) ob_get_clean());
        ListingMedia::query()->create(['listing_id' => $this->home->id, 'media_key' => $name, 'position' => $i, 'source_url' => 'https://mls.test/'.$name.'.jpg',
            'storage_path' => "listings/a1/{$name}", 'width' => 1600, 'height' => 1067, 'status' => 'stored', 'attempts' => 1]);
    }

    $hq = User::factory()->make(['id' => 999999]);
    DB::connection(Connections::control())->table('credit_settings')->insert(['credit_value_microcents' => 1_000_000, 'enforce' => true, 'created_at' => now(), 'updated_at' => now()]);
    app(CreditLedger::class)->savePolicy(false, null, $this->owner);
    app(CreditLedger::class)->add($this->jane->id, 10_000, 'adjust', 'Test credits', $this->owner);
    $app = app(AppCatalog::class)->find('studio_essentials');
    app(AppRegistry::class)->save($app, true, [], null, $hq);
    app(AppRegistry::class)->savePrice($app, 3900, [], $hq);
    $this->actingAs($this->jane)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->post('/apps/studio_essentials/addon')->assertSessionHasNoErrors();
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('facebook_leads'), true, ['client_id' => '123', 'client_secret' => 's', 'verify_token' => 'v'], null, $hq);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('facebook_pages'), true, [], null, $hq);
    Connection::query()->create(['app_key' => 'facebook_pages', 'owner_user_id' => $this->jane->id, 'label' => 'Facebook Pages', 'status' => 'connected',
        'credentials' => ['access_token' => 'u', 'long_lived' => '1', 'expires_at' => (string) (time() + 86400 * 50), 'pages' => (string) json_encode(['111' => 'page-111'])],
        'settings' => ['pages' => [['id' => '111', 'name' => 'Jane Sells Austin']], 'instagram' => []], 'connected_by' => $this->jane->id]);
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => Autopilot::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.studio_caption']);
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai = new FakeAiProvider);
    Http::fake(['graph.facebook.com/v21.0/111/feed' => Http::response(['id' => '111_9']), 'graph.facebook.com/v21.0/111/photos' => Http::response(['id' => '111_9'])]);
    $this->actingAs($this->jane)->post('/studio/brand', ['headshot' => UploadedFile::fake()->image('me.jpg', 800, 1000), 'details' => ['phone' => '512-555-0100',
        'licence_line' => 'TX #0123456']])->assertSessionHasNoErrors();
});

function caption(object $t, string $text = 'Light-filled 3 bed on a quiet street, with a big yard and room to grow. Book a private showing.'): void
{
    $t->ai->respond((string) json_encode(['caption' => $text, 'caption_es' => '', 'hashtags' => ['AustinRealEstate', '#78704', 'Just Listed!']]));
}

it('turns a new listing into a designed, captioned post at a good time, waiting for an OK, once', function (): void {
    caption($this);
    expect(app(Autopilot::class)->runFor($this->jane))->toBe(1);
    $q = QueuedPost::query()->sole();
    expect($q->only(['moment', 'status', 'source_key']))->toBe(['moment' => 'just_listed', 'status' => 'awaiting', 'source_key' => 'listing:'.$this->home->ulid.':just_listed'])
        ->and($q->hashtags)->toBe(['AustinRealEstate', '78704', 'JustListed'])
        ->and($q->post_at?->toIso8601String())->toBe('2026-06-01T23:00:00+00:00') // 6 PM in Austin: 9:30 has passed
        ->and(app(Autopilot::class)->text($q))->toEndWith('#AustinRealEstate #78704 #JustListed');
    expect(app(Autopilot::class)->runFor($this->jane))->toBe(0) // the same moment never twice
        ->and(StudioBriefItems::items($this->jane)[0]['title'])->toBe('1 Ally Studio post is ready for your OK')
        ->and((string) json_encode(app(StudioQueueTool::class)->forAssistant($this->jane, [], true)))->toContain($q->ulid);
    expect(app(StudioPlans::class)->usage($this->jane)['post']['used'])->toBe(0);

    $this->actingAs($this->jane)->get('/studio/autopilot')->assertOk();
    $this->actingAs($this->jane)->post('/studio/autopilot/'.$q->ulid.'/approve', ['caption' => 'Perfect for families! Come see it.'])->assertSessionHasErrors('caption');
    $this->actingAs($this->owner)->post('/studio/autopilot/'.$q->ulid.'/approve')->assertNotFound();
    $this->actingAs($this->jane)->post('/studio/autopilot/'.$q->ulid.'/approve')->assertSessionHasNoErrors();
    $post = SocialPost::query()->sole();
    expect($q->fresh()?->status)->toBe('scheduled')->and($post->status)->toBe('scheduled')->and($post->post_at->toIso8601String())->toBe('2026-06-01T23:00:00+00:00')
        ->and($post->text)->toContain('Light-filled 3 bed')->toContain('#JustListed')->toContain('Jane Agent')
        ->and($post->image_url)->toEndWith('-portrait.jpg')
        ->and(app(StudioPlans::class)->usage($this->jane)['post']['used'])->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-06-01 23:00:30', 'UTC'));
    $this->artisan('ads:social-posts')->assertSuccessful();
    expect($post->fresh()?->status)->toBe('posted');
    Http::assertSent(fn ($r): bool => str_contains($r->url(), '111/') && str_contains((string) $r['access_token'], 'page-111'));
});

it('schedules by itself on Autopilot, notices price improvements, and never uses wording that fails Fair Housing', function (): void {
    $this->actingAs($this->jane)->post('/studio/autopilot', ['mode' => 'auto', 'moments' => ['listings'], 'times' => ['09:30', '18:00'], 'per_week' => 5])->assertSessionHasNoErrors();
    caption($this, 'Perfect for families, in a safe neighborhood.');
    app(Autopilot::class)->runFor($this->jane);
    $first = QueuedPost::query()->sole();
    expect($first->reason)->toBeNull()->and($first->status)->toBe('scheduled')->and($first->caption)->toStartWith('Just listed: 201 Pine Rd')->and($first->hashtags)->toBe([]); // the plain caption instead

    $this->home->forceFill(['list_price_cents' => 48_900_000])->save();
    caption($this);
    app(Autopilot::class)->runFor($this->jane);
    $price = QueuedPost::query()->where('moment', 'price_improved')->sole();
    expect($price->status)->toBe('scheduled')->and($price->post_at?->toIso8601String())->toBe('2026-06-02T14:30:00+00:00') // one a day: tomorrow 9:30
        ->and(SocialPost::query()->count())->toBe(2);

    $this->actingAs($this->jane)->post('/studio/autopilot', ['mode' => 'off', 'moments' => ['listings']]);
    $this->home->forceFill(['standard_status' => 'Pending'])->save();
    expect(app(Autopilot::class)->runFor($this->jane))->toBe(0); // switched off
});

it('knows the US holidays it greets', function (): void {
    $h = Autopilot::holidays(2026);
    expect($h['2026-11-26'][0])->toBe('thanksgiving')->and($h['2026-05-25'][0])->toBe('memorial_day')->and($h['2026-09-07'][0])->toBe('labor_day');
});

function studioLuxury(object $t): void
{
    $app = app(AppCatalog::class)->find('studio_luxury');
    $hq = User::factory()->make(['id' => 999999]);
    app(AppRegistry::class)->save($app, true, [], null, $hq);
    app(AppRegistry::class)->savePrice($app, 7900, [], $hq);
    app(CreditLedger::class)->add($t->jane->id, 10_000, 'adjust', 'More test credits', $t->owner);
    $t->actingAs($t->jane)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->post('/apps/studio_luxury/addon')->assertSessionHasNoErrors();
}

it('runs a listing launch campaign across the first week on Luxury, and moves posts on the calendar', function (): void {
    studioLuxury($this);
    foreach (range(1, 3) as $i) {
        caption($this);
    }
    app(Autopilot::class)->runFor($this->jane);
    $items = QueuedPost::query()->orderBy('id')->get();
    expect($items->pluck('moment')->all())->toBe(['just_listed', 'listing_feature', 'neighbourhood'])->and($items->pluck('status')->unique()->all())->toBe(['awaiting'])
        ->and($items->map(fn (QueuedPost $q): string => (string) $q->post_at?->toIso8601String())->all())
        ->toBe(['2026-06-01T23:00:00+00:00', '2026-06-03T14:30:00+00:00', '2026-06-05T14:30:00+00:00'])
        ->and(Design::query()->where('batch', $items[2]->batch)->first()?->content)->toMatchArray(['kicker' => 'The neighbourhood', 'headline' => 'Austin', 'sub' => 'Around 201 Pine Rd'])
        ->and(Design::query()->where('batch', $items[2]->batch)->first()?->content)->not->toHaveKey('price');

    $this->actingAs($this->jane)->post('/studio/autopilot/'.$items[1]->ulid.'/move', ['date' => '2026-06-10'])->assertSessionHasNoErrors();
    expect($items[1]->fresh()?->post_at?->toIso8601String())->toBe('2026-06-10T14:30:00+00:00');
    $this->actingAs($this->jane)->post('/studio/autopilot/'.$items[1]->ulid.'/move', ['date' => '2026-05-01'])->assertSessionHasErrors('date');
    $this->actingAs($this->jane)->get('/studio/calendar?month=2026-06')->assertInertia(fn ($p) => $p->component('studio/calendar')->has('events', 3)->where('days', 30)->where('first_weekday', 1));
});

it('asks for the client\'s OK before a closing post, celebrates work anniversaries, and learns the agent\'s voice with their OK', function (): void {
    $this->jane->forceFill(['created_at' => '2024-06-01 12:00:00'])->save();
    Deal::query()->create(['type' => 'buyer', 'status' => 'closed', 'agent_user_id' => $this->jane->id, 'street' => '9 Elm St', 'city' => 'Austin', 'state_code' => 'TX',
        'price_cents' => 40_000_000, 'gci_cents' => 1_200_000, 'closed_on' => '2026-05-29', 'created_by' => $this->jane->id]);
    Http::fake(['graph.facebook.com/v21.0/111/posts*' => Http::response(['data' => [['message' => 'Sunday open house on Pine Rd was packed. Thank you, Austin, see you next week!'],
        ['message' => 'Hi']]])]);
    $this->actingAs($this->jane)->post('/studio/autopilot', ['mode' => 'approve', 'moments' => ['closings', 'milestones'], 'learn_voice' => true])->assertSessionHasNoErrors();
    caption($this);
    caption($this);
    app(Autopilot::class)->runFor($this->jane);
    $closing = QueuedPost::query()->where('moment', 'just_closed')->sole();
    expect($closing->needs_client_ok)->toBeTrue()->and($closing->status)->toBe('awaiting')
        ->and(Design::query()->where('batch', $closing->batch)->first()?->content['headline'])->toBe('Another happy closing in Austin') // never the address
        ->and(QueuedPost::query()->where('source_key', 'anniversary:2026')->sole()->moment)->toBe('milestone')
        ->and(app(Autopilot::class)->settings($this->jane)['voice'])->toBe(['Sunday open house on Pine Rd was packed. Thank you, Austin, see you next week!']);
    $this->actingAs($this->jane)->post('/studio/autopilot/'.$closing->ulid.'/approve')->assertSessionHasErrors('client_ok');
    $this->actingAs($this->jane)->post('/studio/autopilot/'.$closing->ulid.'/approve', ['client_ok' => true])->assertSessionHasNoErrors();
    expect($closing->fresh()?->status)->toBe('scheduled');

    $this->actingAs($this->jane)->post('/studio/autopilot', ['mode' => 'approve', 'moments' => ['closings'], 'learn_voice' => false]);
    expect(app(Autopilot::class)->settings($this->jane)['voice'])->toBe([]); // forgotten when switched off
});

it('lets the brokerage review agents\' posts before they go out, with its own caption line', function (): void {
    $this->actingAs($this->jane)->post('/studio/rules', ['require_review' => true])->assertForbidden();
    $this->actingAs($this->owner)->post('/studio/rules', ['require_review' => true, 'caption_disclaimer' => 'Each office independently owned and operated.'])->assertSessionHasNoErrors();
    $this->actingAs($this->jane)->post('/studio/autopilot', ['mode' => 'auto', 'moments' => ['listings']]);
    expect(app(Autopilot::class)->settings($this->jane)['mode'])->toBe('approve'); // review means no Autopilot for agents
    caption($this);
    app(Autopilot::class)->runFor($this->jane);
    $q = QueuedPost::query()->sole();
    $this->actingAs($this->jane)->post('/studio/autopilot/'.$q->ulid.'/approve')->assertSessionHasNoErrors();
    expect($q->fresh()?->status)->toBe('review')->and(SocialPost::query()->count())->toBe(0)
        ->and(StudioBriefItems::items($this->owner)[0]['title'])->toBe('1 agent post needs your review');
    $this->actingAs($this->jane)->get('/studio/review')->assertForbidden();
    $this->actingAs($this->owner)->get('/studio/review')->assertInertia(fn ($p) => $p->component('studio/review')->has('items', 1)->where('items.0.agent', 'Jane'));
    $this->actingAs($this->owner)->post('/studio/review/'.$q->ulid, ['ok' => true])->assertSessionHasNoErrors();
    expect($q->fresh()?->status)->toBe('scheduled')->and(SocialPost::query()->sole()->text)->toContain('Each office independently owned and operated.')
        ->and(SocialPost::query()->sole()->user_id)->toBe($this->jane->id);
});
