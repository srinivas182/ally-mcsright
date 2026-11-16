<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ads\Jobs\EnhancePhoto;
use Modules\Ads\Studio\Design;
use Modules\Ads\Studio\Enhance\FakeEnhancer;
use Modules\Ads\Studio\Enhance\PhotoEnhancer;
use Modules\Ads\Studio\Enhance\PhotoUpscaler;
use Modules\Ads\Studio\MusicLibrary;
use Modules\Ads\Studio\Render\FakeRenderer;
use Modules\Ads\Studio\Render\StudioRenderer;
use Modules\Ads\Studio\StudioPhoto;
use Modules\Ads\Studio\StudioPhotos;
use Modules\Ads\Studio\StudioPlans;
use Modules\Ads\Studio\StudioVideo;
use Modules\Ads\Studio\Video\FakeVideoMaker;
use Modules\Ads\Studio\Video\VideoMaker;
use Modules\Billing\Credits\CreditLedger;
use Modules\Connect\AppRegistry;
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
    app(StudioPlans::class)->savePackage('studio_essentials', ['posts' => 30, 'reels' => 1, 'enhance' => 2], $hq);
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
