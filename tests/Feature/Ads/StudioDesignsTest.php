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
use Modules\Ads\Studio\ArtDirector;
use Modules\Ads\Studio\Design;
use Modules\Ads\Studio\Render\FakeRenderer;
use Modules\Ads\Studio\Render\StudioRenderer;
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

/* Sprint SA1b: Ally Studio designs — three designs per moment in the person's brand and three sizes, checked by the
   art director (sharp photos only, readable colours, no blank renders), listing rules, Fair Housing; free to make,
   counted once when downloaded or posted; a design starts a social post. */

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

it('makes three listing designs on three sharp photos in three sizes, counted once when used, and starts a post', function (): void {
    $this->actingAs($this->jane)->post('/studio/designs', ['moment' => 'just_listed', 'listing' => $this->home->ulid])->assertSessionHasNoErrors()->assertRedirect();
    $designs = Design::query()->orderBy('id')->get();
    expect($designs->pluck('variant')->all())->toBe(['full', 'split', 'frame'])->and($designs->pluck('status')->unique()->all())->toBe(['ready'])
        ->and($designs->map(fn (Design $d): string => basename((string) $d->content['photo']))->all())->toBe(['sharp-1-1600.webp', 'sharp-2-1600.webp', 'sharp-3-1600.webp'])
        ->and($designs[0]->content)->toMatchArray(['kicker' => 'Just listed', 'headline' => '201 Pine Rd', 'sub' => 'Austin, TX 78704', 'facts' => ['3 Beds', '2.5 Baths', '2,140 Sq Ft'],
            'price' => '$501,000']);
    foreach (['square', 'portrait', 'story'] as $size) {
        Storage::disk('public')->assertExists((string) $designs[0]->files[$size]);
    }
    expect($this->renderer->pages)->toHaveCount(9)->and($this->renderer->pages[0])->toContain('201 Pine Rd')->toContain('$501,000')->toContain('Equal Housing Opportunity')
        ->toContain('Listing courtesy of Acme Realty')->toContain('TX #0123456')->toContain('512-555-0100')->not->toContain('<script');
    expect(app(StudioPlans::class)->usage($this->jane)['post']['used'])->toBe(0); // making designs is free

    $this->actingAs($this->jane)->get('/studio/create?batch='.$designs[0]->batch)->assertInertia(fn (Assert $p) => $p->component('studio/create')->has('batch', 3)
        ->where('batch.0.images.portrait', fn ($u): bool => str_ends_with((string) $u, '-portrait.jpg')));
    $this->actingAs($this->jane)->get('/studio/designs/'.$designs[0]->ulid.'/download?size=story')->assertOk()->assertDownload('rightally-just_listed-story.jpg');
    $this->actingAs($this->jane)->get('/studio/designs/'.$designs[0]->ulid.'/download?size=square')->assertOk();
    expect(app(StudioPlans::class)->usage($this->jane)['post']['used'])->toBe(1); // once per design
    $this->actingAs($this->owner)->get('/studio/designs/'.$designs[1]->ulid.'/download')->assertNotFound(); // only theirs

    $this->actingAs($this->jane)->post('/studio/designs/'.$designs[1]->ulid.'/post')->assertRedirect('/social?design='.$designs[1]->ulid);
    $this->actingAs($this->jane)->get('/social?design='.$designs[1]->ulid)->assertInertia(fn (Assert $p) => $p->component('ads/social')
        ->where('start.text', fn ($t): bool => str_starts_with((string) $t, 'Just listed: 201 Pine Rd'))->where('start.image', fn ($u): bool => str_ends_with((string) $u, '-portrait.jpg'))
        ->where('start.listing', $this->home->ulid));
    expect(app(StudioPlans::class)->usage($this->jane)['post']['used'])->toBe(2);
});

it('keeps designs honest: MLS status, Fair Housing, open house time, a photo of the agent, the package', function (): void {
    $this->actingAs($this->jane)->post('/studio/designs', ['moment' => 'just_sold', 'listing' => $this->home->ulid])->assertSessionHasErrors('listing');
    $this->actingAs($this->jane)->post('/studio/designs', ['moment' => 'just_listed', 'listing' => $this->home->ulid, 'headline' => 'Perfect for families'])->assertSessionHasErrors('headline');
    $this->actingAs($this->jane)->post('/studio/designs', ['moment' => 'open_house', 'listing' => $this->home->ulid])->assertSessionHasErrors('when');
    $this->actingAs($this->jane)->post('/studio/designs', ['moment' => 'recruiting', 'headline' => 'Join us'])->assertSessionHasErrors('moment'); // brokerage package, owners
    $this->actingAs($this->owner)->post('/studio/designs', ['moment' => 'tip', 'headline' => 'Three ways to price well'])->assertSessionHasErrors('moment'); // no package
    expect(Design::query()->count())->toBe(0);

    $this->actingAs($this->jane)->post('/studio/designs', ['moment' => 'market_update', 'headline' => 'Austin this week', 'body' => 'Buyers are back.',
        'stats' => [['value' => '42', 'label' => 'New listings'], ['value' => '<b>$485K</b>', 'label' => 'Median price']]])->assertSessionHasNoErrors();
    $text = Design::query()->orderBy('id')->get();
    expect($text->pluck('collection')->all())->toBe(['luxury_noir', 'modern', 'coastal'])->and($text->pluck('variant')->unique()->all())->toBe(['type'])
        ->and($text[0]->content['stats'][1])->toBe(['value' => '$485K', 'label' => 'Median price']);

    $this->renderer->blank = true;
    $this->actingAs($this->jane)->post('/studio/designs', ['moment' => 'meet_agent', 'headline' => 'Meet Jane'])->assertSessionHasNoErrors();
    $blank = Design::query()->where('moment', 'meet_agent')->first();
    expect($blank?->status)->toBe('failed')->and($blank?->checks)->toContain('The square design came out blank.');
});

it('makes colours readable and spots blurry photos', function (): void {
    $p = app(ArtDirector::class)->palette(['primary' => '#111111', 'accent' => '#F5E6A8', 'ink' => '#777777', 'paper' => '#FFFFFF'], 'modern');
    expect(ArtDirector::contrast($p['fg'], $p['bg']))->toBeGreaterThanOrEqual(7.0)->and(ArtDirector::contrast($p['accent'], $p['bg']))->toBeGreaterThanOrEqual(3.0);
    $flat = imagecreatetruecolor(1600, 1067);
    ob_start();
    imagejpeg($flat);
    expect(app(ArtDirector::class)->photoProblem((string) ob_get_clean()))->toBe('photo too blurry');
    $small = imagecreatetruecolor(400, 300);
    ob_start();
    imagejpeg($small);
    expect(app(ArtDirector::class)->photoProblem((string) ob_get_clean()))->toBe('photo too small');
});
