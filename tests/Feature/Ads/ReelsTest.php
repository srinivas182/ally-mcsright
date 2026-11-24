<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ads\Jobs\EnhancePhoto;
use Modules\Ads\Studio\Autopilot;
use Modules\Ads\Studio\Design;
use Modules\Ads\Studio\Enhance\FakeEnhancer;
use Modules\Ads\Studio\Enhance\PhotoEnhancer;
use Modules\Ads\Studio\Enhance\PhotoUpscaler;
use Modules\Ads\Studio\MusicLibrary;
use Modules\Ads\Studio\QueuedPost;
use Modules\Ads\Studio\Render\FakeRenderer;
use Modules\Ads\Studio\Render\StudioRenderer;
use Modules\Ads\Studio\StudioPhoto;
use Modules\Ads\Studio\StudioPhotos;
use Modules\Ads\Studio\StudioPlans;
use Modules\Ads\Studio\StudioVideo;
use Modules\Ads\Studio\Video\FakeVideoMaker;
use Modules\Ads\Studio\Video\VideoMaker;
use Modules\Ads\Studio\Voice\FakeVoice;
use Modules\Ads\Studio\Voice\Narration;
use Modules\Ads\Studio\Voice\StudioVoice;
use Modules\Ads\Studio\Voice\VoiceVendor;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Billing\Credits\CreditLedger;
use Modules\Connect\AppRegistry;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Http\Middleware\ThrottleRequestsPerRoute;
use Modules\Core\Identity\Enums\UserStatus;
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
use Tests\Fixtures\FakeAiProvider;

/* Sprint SA3: Reels and Shorts — a listing design becomes a 9:16 video in the same style (cover, photos, details)
   with licensed music; counted once when downloaded or posted; posted as an Instagram Reel and a Facebook video. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    Storage::fake('public');
    config(['listings.cdn_url' => 'https://cdn.acme.test']);
    app()->instance(StudioRenderer::class, $this->renderer = new FakeRenderer);
    app()->instance(PhotoEnhancer::class, $this->enhancer = new FakeEnhancer);
    app()->instance(PhotoUpscaler::class, $this->enhancer);
    app()->instance(VideoMaker::class, $this->video = new FakeVideoMaker);
    $this->tenant = $this->onTenantHost('acme');
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
    $this->actingAs($this->jane)->post('/studio/brand', ['headshot' => UploadedFile::fake()->image('me.jpg', 800, 1000), 'details' => ['phone' => '512-555-0100',
        'licence_line' => 'TX #0123456']])->assertSessionHasNoErrors();
    app(StudioPlans::class)->savePackage('studio_essentials', ['posts' => 30, 'reels' => 1, 'tours' => 1, 'enhance' => 2], $hq);
    $this->media = ListingMedia::query()->orderBy('position')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    $this->run = function (): void {
        for ($i = 0; $i < 4; $i++) {
            StudioPhoto::query()->whereIn('status', ['queued', 'processing'])->get()->each(fn (StudioPhoto $p) => (new EnhancePhoto($p->id))->handle(app(StudioPhotos::class)));
        }
    };
});

it('makes a Reel from a listing design in its style, with licensed music, counted once when used, and starts a post', function (): void {
    expect(fn () => app(MusicLibrary::class)->add(UploadedFile::fake()->create('song.mp3', 200, 'audio/mpeg'), ['title' => 'Golden Hour', 'mood' => 'luxury', 'licence' => ''],
        $this->owner))->toThrow(ValidationException::class);
    $track = app(MusicLibrary::class)->add(UploadedFile::fake()->create('song.mp3', 200, 'audio/mpeg'), ['title' => 'Golden Hour', 'artist' => 'Studio Strings',
        'mood' => 'luxury', 'licence' => 'Royalty-free library subscription, social video'], $this->owner);

    $this->actingAs($this->jane)->post('/studio/designs', ['moment' => 'just_listed', 'listing' => $this->home->ulid])->assertSessionHasNoErrors();
    $design = Design::query()->orderBy('id')->firstOrFail();
    $pages = count($this->renderer->pages);
    $this->actingAs($this->jane)->post('/studio/designs/'.$design->ulid.'/reel', ['mood' => 'luxury'])->assertRedirect('/studio/reels');
    $reel = StudioVideo::query()->sole();
    expect($reel->status)->toBe('ready')->and($reel->music_ulid)->toBe($track)->and($reel->seconds)->toBe(13)
        ->and($this->video->made[0]['frames'])->toBe(4)->and($this->video->made[0]['seconds'])->toBe([4.0, 3.0, 3.0, 4.5]) // cover, two photos, details
        ->and($this->video->made[0]['music'])->toEndWith('.mp3')
        ->and(array_slice($this->renderer->pages, $pages))->toHaveCount(4)->and($this->renderer->pages[$pages])->toContain('height:1920px')
        ->and($this->renderer->pages[$pages + 3])->toContain('Book a tour');
    Storage::disk('public')->assertExists((string) $reel->path);
    Storage::disk('public')->assertExists((string) $reel->poster_path);
    expect(app(StudioPlans::class)->usage($this->jane)['reel']['used'])->toBe(0); // free to make

    $this->actingAs($this->jane)->get('/studio/reels')->assertInertia(fn (Assert $p) => $p->component('studio/reels')->has('reels', 1)
        ->where('reels.0.url', fn ($u): bool => str_ends_with((string) $u, '.mp4'))->where('reels.0.music', 'Golden Hour'));
    $this->actingAs($this->jane)->get('/studio/reels/'.$reel->ulid.'/download')->assertOk()->assertDownload('rightally-reel-'.strtolower($reel->ulid).'.mp4');
    $this->actingAs($this->jane)->post('/studio/reels/'.$reel->ulid.'/post')->assertRedirect('/social?reel='.$reel->ulid);
    expect(app(StudioPlans::class)->usage($this->jane)['reel'])->toMatchArray(['used' => 1, 'extra' => 0]); // once
    $this->actingAs($this->jane)->get('/social?reel='.$reel->ulid)->assertInertia(fn (Assert $p) => $p->component('ads/social')
        ->where('start.video', fn ($u): bool => str_ends_with((string) $u, '.mp4'))->where('start.image', fn ($u): bool => str_ends_with((string) $u, '.jpg'))
        ->where('start.listing', $this->home->ulid));
    $this->actingAs($this->owner)->get('/studio/reels/'.$reel->ulid.'/download')->assertNotFound(); // only theirs
});

it('makes Reels only from the person\'s own finished listing designs, and says so when one fails', function (): void {
    $this->actingAs($this->jane)->post('/studio/designs', ['moment' => 'tip', 'headline' => 'Price it right on day one'])->assertSessionHasNoErrors();
    $tip = Design::query()->orderBy('id')->firstOrFail();
    $this->actingAs($this->jane)->post('/studio/designs/'.$tip->ulid.'/reel')->assertSessionHasErrors('reel');
    $this->actingAs($this->owner)->post('/studio/designs/'.$tip->ulid.'/reel')->assertNotFound();

    $this->video->fail = true;
    $this->actingAs($this->jane)->post('/studio/designs', ['moment' => 'just_listed', 'listing' => $this->home->ulid])->assertSessionHasNoErrors();
    $listing = Design::query()->where('moment', 'just_listed')->orderBy('id')->firstOrFail();
    $this->actingAs($this->jane)->post('/studio/designs/'.$listing->ulid.'/reel')->assertRedirect('/studio/reels');
    expect(StudioVideo::query()->sole()->only(['status', 'music_ulid']))->toBe(['status' => 'failed', 'music_ulid' => null]) // no music in the library: a quiet track
        ->and(StudioVideo::query()->sole()->error)->toContain('couldn');
    $this->actingAs($this->jane)->get('/studio/reels/'.StudioVideo::query()->sole()->ulid.'/download')->assertNotFound();
});

it('makes a YouTube listing tour: landscape, a chapter per room from the MLS captions, counted as a tour', function (): void {
    ListingMedia::query()->where('media_key', 'sharp-2')->update(['caption' => 'Chef\'s kitchen']);
    $this->actingAs($this->jane)->post('/studio/tours', ['listing' => $this->home->ulid])->assertRedirect('/studio/reels');
    $tour = StudioVideo::query()->sole();
    expect($tour->kind)->toBe('tour')->and($tour->status)->toBe('ready')->and($tour->path)->toContain('/tours/')
        ->and($this->video->made[0]['size'])->toBe('1920x1080')->and($this->video->made[0]['seconds'])->toBe([6.0, 10.0, 10.0, 8.0])
        ->and($tour->chapters)->toBe([['at' => 0, 'title' => 'Welcome'], ['at' => 5, 'title' => 'Chef\'s kitchen'], ['at' => 14, 'title' => 'A closer look'],
            ['at' => 24, 'title' => 'Book a tour']]);
    $this->actingAs($this->jane)->post('/studio/reels/'.$tour->ulid.'/post')->assertRedirect('/social?reel='.$tour->ulid);
    expect(app(StudioPlans::class)->usage($this->jane)['tour']['used'])->toBe(1)->and(app(StudioPlans::class)->usage($this->jane)['reel']['used'])->toBe(0);
    $this->actingAs($this->jane)->get('/social?reel='.$tour->ulid)->assertInertia(fn (Assert $p) => $p
        ->where('start.text', fn ($t): bool => str_starts_with((string) $t, 'Tour: ') && str_contains((string) $t, "0:05 Chef's kitchen"))
        ->where('start.video', fn ($u): bool => str_contains((string) $u, '/tours/')));
    $other = Listing::query()->create(['mls_source_id' => $this->home->mls_source_id, 'listing_key' => 'B2', 'standard_status' => 'Active', 'list_agent_mls_id' => 'ZZZ']);
    $this->actingAs($this->jane)->post('/studio/tours', ['listing' => $other->ulid])->assertSessionHasErrors('listing');
});

it('adds a Reel to a new listing\'s launch on Autopilot, from the package\'s Reels only', function (): void {
    $this->home->forceFill(['on_market_date' => now()->subDay()])->save();
    app(Autopilot::class)->save($this->jane, ['mode' => 'approve', 'moments' => ['listings'], 'per_week' => 5, 'auto_enhance' => false]);
    app(Autopilot::class)->runFor($this->jane);
    $post = QueuedPost::query()->where('source_key', 'like', 'listing:%')->sole();
    $reel = QueuedPost::query()->where('source_key', 'reel:'.$this->home->ulid)->sole();
    // The Reel is made on the queue (here at once), then waits for approval like any post, a day after the listing post.
    expect($post->status)->toBe('awaiting')->and($reel->status)->toBe('awaiting')->and($reel->video_id)->not->toBeNull()
        ->and((string) $reel->caption)->not->toBe('')->and($reel->post_at?->gt($post->post_at))->toBeTrue()
        ->and(StudioVideo::query()->findOrFail($reel->video_id)->status)->toBe('ready');

    // A second new listing gets no Reel: the package's one Reel is spoken for.
    app(StudioPlans::class)->use($this->jane, 'reel', 'manual-1');
    expect(app(StudioPlans::class)->left($this->jane, 'reel'))->toBe(0);
    $before = StudioVideo::query()->count();
    $second = Listing::query()->create(['mls_source_id' => $this->home->mls_source_id, 'listing_key' => 'A9', 'standard_status' => 'Active', 'list_agent_mls_id' => 'AGT1',
        'on_market_date' => now()]);
    app(Autopilot::class)->runFor($this->jane);
    expect(StudioVideo::query()->count())->toBe($before)->and(QueuedPost::query()->where('source_key', 'reel:'.$second->ulid)->exists())->toBeFalse();
});

/** SA5b: voice-overs on, an AI route for scripts, and a finished Reel of Jane's. */
function voiceReady(object $t): StudioVideo
{
    $hq = User::factory()->make(['id' => 999999]);
    app()->instance(VoiceVendor::class, $t->voice = new FakeVoice);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('studio_voice'), true, ['api_key' => 'xi-test'], null, $hq);
    app(StudioPlans::class)->savePackage('studio_essentials', ['posts' => 30, 'reels' => 1, 'tours' => 1, 'enhance' => 2, 'voice' => 2], $hq);
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => Narration::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.studio_caption']);
    app(ProviderRegistry::class)->fake($model->provider->key, $t->ai = new FakeAiProvider);
    $t->actingAs($t->jane)->post('/studio/designs', ['moment' => 'just_listed', 'listing' => $t->home->ulid])->assertSessionHasNoErrors();
    $t->actingAs($t->jane)->post('/studio/designs/'.Design::query()->orderBy('id')->firstOrFail()->ulid.'/reel')->assertRedirect('/studio/reels');

    return StudioVideo::query()->sole();
}

it('narrates a Reel in a professional AI voice: an AI script that fits, Fair Housing checked, mixed over the music, counted once', function (): void {
    $reel = voiceReady($this);
    $oldPath = (string) $reel->path;
    $this->actingAs($this->jane)->get('/studio/reels')->assertInertia(fn (Assert $p) => $p->where('voice_on', true)->where('voices.0.key', 'stock:stock-rachel')
        ->where('reels.0.max_words', Narration::maxWords($reel)));
    $this->ai->respond((string) json_encode(['script' => 'Welcome to 201 Pine Rd in Austin. Three bedrooms, warm light and a big yard. Book your private tour today.']));
    $this->actingAs($this->jane)->postJson('/studio/reels/'.$reel->ulid.'/script')->assertOk()->assertJsonPath('script', 'Welcome to 201 Pine Rd in Austin. Three bedrooms, warm light and a big yard. Book your private tour today.');
    // An AI script that fails the Fair Housing check is never offered.
    $this->ai->respond((string) json_encode(['script' => 'Perfect for young families who want great schools.']));
    expect($this->actingAs($this->jane)->postJson('/studio/reels/'.$reel->ulid.'/script')->json('script'))->not->toContain('families')->toContain('Book your private tour');

    $this->actingAs($this->jane)->post('/studio/reels/'.$reel->ulid.'/narrate', ['narration' => 'Perfect for young couples with no kids.', 'voice' => 'stock:stock-rachel'])->assertSessionHasErrors('narration');
    $this->actingAs($this->jane)->post('/studio/reels/'.$reel->ulid.'/narrate', ['narration' => str_repeat('word ', 200), 'voice' => 'stock:stock-rachel'])->assertSessionHasErrors('narration');
    $this->actingAs($this->jane)->post('/studio/reels/'.$reel->ulid.'/narrate', ['narration' => 'Welcome home.', 'voice' => 'own'])->assertSessionHasErrors('voice'); // no own voice yet
    $this->actingAs($this->jane)->post('/studio/reels/'.$reel->ulid.'/narrate', ['narration' => 'Welcome to 201 Pine Rd. Book your private tour today.', 'voice' => 'stock:stock-rachel'])
        ->assertRedirect('/studio/reels');

    $reel->refresh();
    expect($reel->only(['status', 'voice', 'narration_error']))->toBe(['status' => 'ready', 'voice' => 'stock:stock-rachel', 'narration_error' => null])
        ->and($this->voice->spoken)->toBe([['voice' => 'stock-rachel', 'text' => 'Welcome to 201 Pine Rd. Book your private tour today.']])
        ->and($this->video->made[1]['voice'])->toStartWith('ID3')->and($this->video->made[1]['music'])->toBe($this->video->made[0]['music'])
        ->and($reel->path)->not->toBe($oldPath)->toContain('-n')
        ->and(app(StudioPlans::class)->usage($this->jane)['voice'])->toMatchArray(['used' => 1, 'extra' => 0]);
    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists((string) $reel->path);
    $this->actingAs($this->owner)->post('/studio/reels/'.$reel->ulid.'/narrate', ['narration' => 'Hi.', 'voice' => 'stock:stock-rachel'])->assertNotFound(); // only theirs
});

it('keeps the video and gives the narration back when the voice service fails', function (): void {
    $reel = voiceReady($this);
    $this->voice->fail = true;
    $this->actingAs($this->jane)->post('/studio/reels/'.$reel->ulid.'/narrate', ['narration' => 'Welcome home. Book your private tour today.', 'voice' => 'stock:stock-adam']);
    expect($reel->refresh()->only(['status', 'voice', 'narration']))->toBe(['status' => 'ready', 'voice' => null, 'narration' => null])
        ->and($reel->narration_error)->toContain('back in your allowance')->and($this->video->made[1]['voice'])->toBeNull()
        ->and(app(StudioPlans::class)->usage($this->jane)['voice']['used'])->toBe(0);
});

it('makes an agent\'s own voice only with their signed and spoken consent, uses it only for them, and deletes it on request', function (): void {
    $reel = voiceReady($this);
    $this->withoutMiddleware([ThrottleRequests::class, ThrottleRequestsPerRoute::class]); // this test makes many attempts on purpose
    $this->actingAs($this->jane)->get('/studio/voice')->assertInertia(fn (Assert $p) => $p->component('studio/voice')->where('own', null)
        ->where('consent', fn ($c): bool => str_starts_with((string) $c, 'I, Jane Agent, agree') && str_contains((string) $c, '12 months')));
    $clip = fn (): UploadedFile => UploadedFile::fake()->create('voice.webm', 300, 'audio/webm');
    // Making a voice asks for the password first.
    $this->flushSession();
    $this->actingAs($this->jane)->post('/studio/voice', ['name' => 'Jane Agent', 'agree' => '1', 'recordings' => [$clip()]])->assertRedirect();
    expect(StudioVoice::query()->count())->toBe(0);

    $confirmed = $this->actingAs($this->jane)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
    $confirmed->post('/studio/voice', ['name' => 'Someone Else', 'agree' => '1', 'recordings' => [$clip()]])->assertSessionHasErrors('name');
    $confirmed->post('/studio/voice', ['name' => 'Jane Agent', 'recordings' => [$clip()]])->assertSessionHasErrors('agree');
    $confirmed->post('/studio/voice', ['name' => 'Jane Agent', 'agree' => '1'])->assertSessionHasErrors('recordings');
    $confirmed->post('/studio/voice', ['name' => 'Jane Agent', 'agree' => '1', 'recordings' => [UploadedFile::fake()->create('x.pdf', 300, 'application/pdf')]])->assertSessionHasErrors('recordings');
    $confirmed->post('/studio/voice', ['name' => ' jane  agent ', 'agree' => '1', 'recordings' => [$clip()]])->assertRedirect('/studio/voice');

    $voice = StudioVoice::query()->sole();
    expect($voice->only(['user_id', 'status', 'voice_ref', 'consent_version']))->toBe(['user_id' => $this->jane->id, 'status' => 'active', 'voice_ref' => 'own-voice-1', 'consent_version' => 1])
        ->and($voice->consent_text)->toStartWith('I, Jane Agent, agree')->and($voice->recording_sha256)->toHaveLength(64)
        ->and(DB::connection(Connections::cell())->table('studio_voices')->value('voice_ref'))->not->toContain('own-voice') // encrypted at rest
        ->and($this->voice->cloned)->toBe([['label' => 'RightAlly '.$this->tenant->ulid.' '.$this->jane->id, 'files' => 1]]);

    // Only Jane can use it.
    expect(array_column(app(Narration::class)->voices($this->jane), 'key'))->toContain('own')
        ->and(array_column(app(Narration::class)->voices($this->owner), 'key'))->not->toContain('own');
    $this->actingAs($this->jane)->post('/studio/reels/'.$reel->ulid.'/narrate', ['narration' => 'Welcome home. Book your private tour today.', 'voice' => 'own'])->assertRedirect('/studio/reels');
    expect($this->voice->spoken[0]['voice'])->toBe('own-voice-1')->and($voice->refresh()->last_used_at)->not->toBeNull();

    $this->actingAs($this->jane)->delete('/studio/voice')->assertRedirect('/studio/voice');
    expect($voice->refresh()->only(['status', 'voice_ref', 'deleted_reason']))->toBe(['status' => 'deleted', 'voice_ref' => null, 'deleted_reason' => 'Deleted by the agent'])
        ->and($this->voice->deleted)->toBe(['own-voice-1']);
    $this->actingAs($this->jane)->post('/studio/reels/'.$reel->ulid.'/narrate', ['narration' => 'Welcome home.', 'voice' => 'own'])->assertSessionHasErrors('voice');
});

it('deletes own voices when the agent leaves or after 12 months unused', function (): void {
    voiceReady($this);
    $bob = User::factory()->create(['first_name' => 'Bob', 'last_name' => 'Agent']);
    foreach ([$this->jane, $bob] as $i => $u) {
        StudioVoice::query()->create(['user_id' => $u->id, 'voice_ref' => 'v-'.$i, 'status' => 'active', 'consent_name' => $u->name, 'consent_text' => 'I agree',
            'consent_version' => 1, 'recording_sha256' => str_repeat('a', 64), 'consent_at' => now()]);
    }
    $this->travelTo(now()->addMonths(6));
    $this->artisan('ads:studio-voices')->assertSuccessful();
    expect(StudioVoice::query()->where('status', 'active')->count())->toBe(2);

    $bob->forceFill(['status' => UserStatus::Deactivated])->save();
    $this->artisan('ads:studio-voices')->assertSuccessful();
    expect(StudioVoice::query()->where('user_id', $bob->id)->value('deleted_reason'))->toBe('Left the brokerage');

    $this->travelTo(now()->addMonths(7));
    $this->artisan('ads:studio-voices')->assertSuccessful();
    expect(StudioVoice::query()->where('user_id', $this->jane->id)->value('deleted_reason'))->toBe('Unused for 12 months')
        ->and($this->voice->deleted)->toBe(['v-1', 'v-0']);
});
