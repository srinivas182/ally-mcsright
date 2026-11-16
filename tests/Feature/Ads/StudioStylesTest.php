<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ads\Studio\BrandKits;
use Modules\Ads\Studio\Design;
use Modules\Ads\Studio\Render\FakeRenderer;
use Modules\Ads\Studio\Render\StudioRenderer;
use Modules\Ads\Studio\StylePreviews;
use Modules\Ads\Studio\Styles;
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

/* Sprint SA1d: the Ally Studio style library: eighteen styles previewed with the person's own listing, finishes,
   favourites rotated by Autopilot, style match, and the brokerage's allowed styles. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    Storage::fake('public');
    config(['listings.cdn_url' => 'https://cdn.acme.test']);
    app()->instance(StudioRenderer::class, $this->renderer = new FakeRenderer);
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
});

it('shows every style with the person\'s own listing, made once and kept until the brand changes', function (): void {
    expect(Styles::ALL)->toHaveCount(18);
    $this->actingAs($this->jane)->get('/studio/styles')->assertInertia(fn (Assert $p) => $p->component('studio/styles')->has('styles', 18)
        ->where('current.style', 'noir_atelier')->where('styles.0.preview', null)); // being made
    $this->actingAs($this->jane)->get('/studio/styles')->assertInertia(fn (Assert $p) => $p->where('styles.0.preview', fn ($v): bool => is_string($v) && str_ends_with($v, '.jpg')));
    $urls = app(StylePreviews::class)->urls($this->jane);
    expect(array_filter($urls))->toHaveCount(18)->and($this->renderer->pages)->toHaveCount(18)
        ->and(collect($this->renderer->pages)->first(fn (string $h): bool => str_contains($h, "'Syne'")))->toContain('201 Pine Rd'); // Architect, with her listing
    $this->actingAs($this->jane)->get('/studio/styles')->assertOk();
    expect($this->renderer->pages)->toHaveCount(18); // not made again
});

it('saves a style, a finish and favourites, matches styles to taste, and rotates favourites on Autopilot designs', function (): void {
    $this->actingAs($this->jane)->post('/studio/styles', ['style' => 'architect', 'finish' => 'platinum', 'favorites' => ['riviera', 'desert_modern', 'nonsense']])
        ->assertSessionHasNoErrors();
    $kit = app(BrandKits::class)->forUser($this->jane);
    expect($kit['collection'])->toBe('architect')->and($kit['finish'])->toBe('platinum')->and($kit['favorites'])->toBe(['riviera', 'desert_modern'])
        ->and($kit['details']['licence_line'])->toBe('TX #0123456'); // the rest of the kit is untouched

    $this->actingAs($this->jane)->post('/studio/designs', ['moment' => 'just_listed', 'listing' => $this->home->ulid])->assertSessionHasNoErrors();
    expect(Design::query()->pluck('variant')->all())->toBe(['blueprint', 'split', 'cover'])->and($this->renderer->pages[0])->toContain("'IBM Plex Mono'");
    $this->actingAs($this->jane)->post('/studio/designs', ['moment' => 'just_listed', 'listing' => $this->home->ulid, 'style' => 'riviera'])->assertSessionHasNoErrors();
    expect(Design::query()->latest('id')->firstOrFail()->collection)->toBe('riviera');

    $this->actingAs($this->jane)->post('/studio/styles/match', ['liked' => ['riviera', 'desert_modern']])->assertRedirect('/studio/styles?match=riviera,desert_modern,coastal_light');
    expect(Styles::match(['noir_atelier', 'emerald_club', 'gallery_white']))->toBe(['noir_atelier', 'emerald_club', 'heritage_navy']);
});

it('keeps agents to the styles the brokerage allows when the brand is locked', function (): void {
    $this->actingAs($this->owner)->post('/studio/brand', ['for' => 'brokerage', 'collection' => 'heritage_navy', 'brand_lock' => '1', 'styles' => ['heritage_navy', 'emerald_club']])
        ->assertSessionHasNoErrors();
    expect(app(BrandKits::class)->forUser($this->jane)['collection'])->toBe('heritage_navy');
    $this->actingAs($this->jane)->post('/studio/styles', ['style' => 'bold_contemporary'])->assertSessionHasErrors('collection');
    $this->actingAs($this->jane)->post('/studio/styles', ['style' => 'emerald_club', 'favorites' => ['emerald_club', 'riviera']])->assertSessionHasNoErrors();
    $kit = app(BrandKits::class)->forUser($this->jane);
    expect($kit['collection'])->toBe('emerald_club')->and($kit['favorites'])->toBe(['emerald_club'])->and($kit['allowed'])->toBe(['heritage_navy', 'emerald_club']);
    $this->actingAs($this->jane)->post('/studio/designs', ['moment' => 'tip', 'headline' => 'Three ways to price well', 'style' => 'riviera'])->assertSessionHasNoErrors();
    expect(Design::query()->pluck('collection')->unique()->values()->all())->toBe(['emerald_club', 'heritage_navy']); // never a style outside the brokerage's
});
