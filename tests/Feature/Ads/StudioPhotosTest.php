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
use Modules\Ads\Studio\Autopilot;
use Modules\Ads\Studio\Design;
use Modules\Ads\Studio\Designer;
use Modules\Ads\Studio\Enhance\FakeEnhancer;
use Modules\Ads\Studio\Enhance\PhotoEnhancer;
use Modules\Ads\Studio\Enhance\PhotoUpscaler;
use Modules\Ads\Studio\QueuedPost;
use Modules\Ads\Studio\Render\FakeRenderer;
use Modules\Ads\Studio\Render\StudioRenderer;
use Modules\Ads\Studio\StudioPhoto;
use Modules\Ads\Studio\StudioPhotos;
use Modules\Ads\Studio\StudioPlans;
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

/* Sprint SA5a: AI photo enhancement in Ally Studio — chosen photos and a virtual twilight, from the package's allowance
   then credits, given back on failure, used in designs with "Photos virtually enhanced"; Autopilot enhances new
   listings within the allowance and posts inside the package. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    Storage::fake('public');
    config(['listings.cdn_url' => 'https://cdn.acme.test']);
    app()->instance(StudioRenderer::class, $this->renderer = new FakeRenderer);
    app()->instance(PhotoEnhancer::class, $this->enhancer = new FakeEnhancer);
    app()->instance(PhotoUpscaler::class, $this->enhancer);
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
    app(StudioPlans::class)->savePackage('studio_essentials', ['posts' => 30, 'reels' => 4, 'enhance' => 2], $hq);
    $this->media = ListingMedia::query()->orderBy('position')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    $this->run = function (): void {
        for ($i = 0; $i < 4; $i++) {
            StudioPhoto::query()->whereIn('status', ['queued', 'processing'])->get()->each(fn (StudioPhoto $p) => (new EnhancePhoto($p->id))->handle(app(StudioPhotos::class)));
        }
    };
});

it('enhances chosen photos and a twilight, from the allowance then credits, and designs use them with the label', function (): void {
    $this->actingAs($this->jane)->get('/studio/photos?listing='.$this->home->ulid)->assertInertia(fn (Assert $p) => $p->component('studio/photos')
        ->where('on', true)->has('photos', 4)->where('usage.allowance', 2)->where('photos.0.enhanced', null));
    $before = app(CreditLedger::class)->balanceOf($this->jane->id);
    $this->actingAs($this->jane)->post('/studio/photos', ['listing' => $this->home->ulid, 'photos' => [$this->media[0], $this->media[2]], 'twilight' => true])
        ->assertSessionHasNoErrors()->assertRedirect('/studio/photos?listing='.$this->home->ulid);
    expect(StudioPhoto::query()->pluck('paid', 'kind')->all())->toHaveCount(2)
        ->and(app(StudioPlans::class)->usage($this->jane)['enhance'])->toMatchArray(['used' => 2, 'extra' => 1]) // two from the allowance, one with credits
        ->and(app(CreditLedger::class)->balanceOf($this->jane->id))->toBe($before - 40);

    ($this->run)();
    expect(StudioPhoto::query()->pluck('status')->unique()->all())->toBe(['ready'])->and($this->enhancer->started)->toContain('dusk:img-1');
    foreach (StudioPhoto::query()->get() as $p) {
        Storage::disk('public')->assertExists((string) $p->path);
    }
    $this->actingAs($this->jane)->get('/studio/photos?listing='.$this->home->ulid)->assertInertia(fn (Assert $p) => $p
        ->where('photos.0.twilight.url', fn ($u): bool => str_contains((string) $u, '/photos/'))->where('photos.1.enhanced', null));

    // Designs lead with the twilight, use the enhanced photos, and say so on the image and in the caption.
    $this->actingAs($this->jane)->post('/studio/designs', ['moment' => 'just_listed', 'listing' => $this->home->ulid])->assertSessionHasNoErrors();
    $design = Design::query()->orderBy('id')->firstOrFail();
    $twilight = StudioPhoto::query()->where('kind', 'twilight')->sole();
    expect($design->content['enhanced'])->toBeTrue()->and($design->content['photo'])->toEndWith((string) $twilight->path)
        ->and($this->renderer->pages[0])->toContain(StudioPhotos::LABEL)->and(app(Designer::class)->caption($design))->toContain(StudioPhotos::LABEL);

    // Asking again for photos already enhanced does nothing and costs nothing.
    $this->actingAs($this->jane)->post('/studio/photos', ['listing' => $this->home->ulid, 'photos' => [$this->media[0]], 'twilight' => true])->assertSessionHasNoErrors();
    expect(StudioPhoto::query()->count())->toBe(3)->and(app(CreditLedger::class)->balanceOf($this->jane->id))->toBe($before - 40);
});

it('gives back what a failed enhancement cost, and only works when switched on and for the person\'s own listings', function (): void {
    $this->enhancer->isReady = false;
    $this->actingAs($this->jane)->post('/studio/photos', ['listing' => $this->home->ulid, 'photos' => [$this->media[0]]])->assertSessionHasErrors('photos');
    $this->enhancer->isReady = true;
    $this->actingAs($this->owner)->post('/studio/photos', ['listing' => $this->home->ulid, 'photos' => [$this->media[0]]])->assertSessionHasErrors(); // no package, not theirs

    $this->enhancer->fail = true;
    $before = app(CreditLedger::class)->balanceOf($this->jane->id);
    $this->actingAs($this->jane)->post('/studio/photos', ['listing' => $this->home->ulid, 'photos' => [$this->media[0], $this->media[2], $this->media[3]]])->assertSessionHasNoErrors();
    expect(app(CreditLedger::class)->balanceOf($this->jane->id))->toBe($before - 40);
    ($this->run)();
    expect(StudioPhoto::query()->pluck('status')->unique()->all())->toBe(['failed'])
        ->and(app(StudioPlans::class)->usage($this->jane)['enhance']['used'])->toBe(0) // the allowance comes back
        ->and(app(CreditLedger::class)->balanceOf($this->jane->id))->toBe($before); // and the credits

    // Trying again works once the service does.
    $this->enhancer->fail = false;
    $this->actingAs($this->jane)->post('/studio/photos', ['listing' => $this->home->ulid, 'photos' => [$this->media[0]]])->assertSessionHasNoErrors();
    ($this->run)();
    expect(StudioPhoto::query()->where('media_id', $this->media[0])->value('status'))->toBe('ready');
});

it('lets Autopilot enhance a new listing from the allowance only, wait for the photos, and stay inside the package', function (): void {
    $before = app(CreditLedger::class)->balanceOf($this->jane->id);
    $this->home->forceFill(['on_market_date' => now()->subDay()])->save();
    app(Autopilot::class)->save($this->jane, ['mode' => 'approve', 'moments' => ['listings'], 'per_week' => 5]);
    app(Autopilot::class)->runFor($this->jane);
    expect(StudioPhoto::query()->count())->toBe(2) // two left in the allowance: two photos, no twilight, never credits
        ->and(app(CreditLedger::class)->balanceOf($this->jane->id))->toBe($before)
        ->and(QueuedPost::query()->where('moment', 'just_listed')->value('status'))->toBe('enhancing');

    ($this->run)();
    app(Autopilot::class)->runFor($this->jane);
    $item = QueuedPost::query()->where('moment', 'just_listed')->sole();
    expect($item->status)->toBe('awaiting')->and(Design::query()->where('batch', $item->batch)->first()?->content['enhanced'])->toBeTrue();

    // With the month's posts used up, approving waits for the package (unless credits for extra posts are allowed).
    app(StudioPlans::class)->savePackage('studio_essentials', ['posts' => 0, 'enhance' => 2], User::factory()->make(['id' => 999999]));
    expect(fn () => app(Autopilot::class)->approve($item, $this->jane))->toThrow(ValidationException::class, 'designed posts in your Studio package are used');
    expect(app(CreditLedger::class)->balanceOf($this->jane->id))->toBe($before);
});
