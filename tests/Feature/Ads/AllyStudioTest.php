<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ads\Studio\BrandKits;
use Modules\Ads\Studio\StudioPlans;
use Modules\Billing\Credits\CreditLedger;
use Modules\Connect\AppRegistry;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/* Sprint SA1a: Ally Studio packages (HQ-priced monthly add-ons with allowances, extra items paid with credits, HQ
   cost and margin) and brand kits (headshot cut out, brokerage brand lock). */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    Storage::fake('public');
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive', 'last_name' => 'Owner']);
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $this->hq = User::factory()->make(['id' => 999999]);
    DB::connection(Connections::control())->table('credit_settings')->insert(['credit_value_microcents' => 1_000_000, 'enforce' => true, 'created_at' => now(), 'updated_at' => now()]);
    app(CreditLedger::class)->savePolicy(false, null, $this->owner);
    app(CreditLedger::class)->add($this->ann->id, 10_000, 'adjust', 'Test credits', $this->owner);
});

function studioPrice(string $key, int $cents): void
{
    app(AppRegistry::class)->save(app(AppCatalog::class)->find($key), true, [], null, test()->hq);
    app(AppRegistry::class)->savePrice(app(AppCatalog::class)->find($key), $cents, [], test()->hq);
}

function studioBuy(User $as, string $key): void
{
    test()->actingAs($as)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->post('/apps/'.$key.'/addon')->assertSessionHasNoErrors();
}

it('is never free: unpriced Studio packages aren\'t on sale; a bought package gives its allowance, then credits', function (): void {
    expect(app(StudioPlans::class)->current($this->ann))->toBeNull();
    $this->actingAs($this->ann)->get('/studio')->assertInertia(fn (Assert $p) => $p->component('studio/index')->where('plan', null)->has('offers', 0));
    expect(fn () => app(StudioPlans::class)->use($this->ann, 'post', 'x1'))->toThrow(ValidationException::class);

    studioPrice('studio_essentials', 3900);
    studioPrice('studio_luxury', 7900);
    $this->actingAs($this->ann)->get('/studio')->assertInertia(fn (Assert $p) => $p->has('offers', 2)->where('offers.0.allowances.posts', 30)); // the brokerage package is for owners
    studioBuy($this->ann, 'studio_essentials');
    expect(app(CreditLedger::class)->balanceOf($this->ann->id))->toBe(6_100);

    $plan = app(StudioPlans::class)->current($this->ann);
    expect($plan['package'])->toBe('studio_essentials')->and($plan['allowances'])->toBe(['post' => 30, 'reel' => 4, 'tour' => 0, 'enhance' => 0, 'voice' => 0]);
    $plans = app(StudioPlans::class);
    foreach (range(1, 30) as $i) {
        expect($plans->use($this->ann, 'post', 'p'.$i))->toBe('allowance');
    }
    expect($plans->use($this->ann, 'post', 'p31'))->toBe('credits')
        ->and(app(CreditLedger::class)->balanceOf($this->ann->id))->toBe(6_090) // 10 credits for the 31st post
        ->and($plans->usage($this->ann)['post'])->toBe(['used' => 30, 'extra' => 1, 'allowance' => 30])
        ->and($plans->use($this->ann, 'tour', 't1'))->toBe('credits'); // no tours in Essentials: 120 credits
    expect(app(CreditLedger::class)->balanceOf($this->ann->id))->toBe(5_970)
        ->and(app(StudioPlans::class)->usage($this->owner)['post']['used'])->toBe(0); // per person
});

it('lets the owner buy Studio for the whole brokerage, which beats an agent\'s own package', function (): void {
    studioPrice('studio_essentials', 3900);
    studioPrice('studio_brokerage', 29900);
    app(CreditLedger::class)->add(null, 50_000, 'adjust', 'Pool', $this->owner);
    $this->actingAs($this->ann)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->post('/apps/studio_brokerage/addon')->assertForbidden();
    studioBuy($this->ann, 'studio_essentials');
    studioBuy($this->owner, 'studio_brokerage');
    expect(app(StudioPlans::class)->current($this->ann)['package'])->toBe('studio_brokerage')
        ->and(app(StudioPlans::class)->current($this->ann)['allowances']['tour'])->toBe(2)
        ->and(app(StudioPlans::class)->has($this->ann, 'recruiting'))->toBeTrue()
        ->and(app(StudioPlans::class)->current($this->owner)['package'])->toBe('studio_brokerage');
});

it('shows HQ each package\'s cost and margin and lets HQ change the allowances', function (): void {
    studioPrice('studio_luxury', 7900);
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $hq = 'http://app.rightally.io';
    $r = $this->actingAs($staff)->get($hq.'/hq/studio')->assertOk();
    $r->assertInertia(fn (Assert $p) => $p->component('hq/studio')
        ->where('packages.1.key', 'studio_luxury')->where('packages.1.price_cents', 7900)
        ->where('packages.1.cost_cents', 60 * 3 + 12 * 15 + 2 * 30 + 10 * 20 + 4 * 10 + 20) // full use at the cost list prices
        ->where('packages.1.margin_pct', 91)
        ->where('items.0.item', 'post')->where('items.0.credits', 10)->where('items.0.price_cents', 10)->where('items.0.cost_cents', 3)->where('items.0.margin_pct', 70));
    $this->actingAs($staff)->post($hq.'/hq/studio/packages/studio_luxury', ['posts' => 40, 'reels' => 8, 'tours' => 1, 'enhance' => 5, 'voice' => 2,
        'features' => ['spanish', 'nonsense']])->assertSessionHasNoErrors();
    expect(app(StudioPlans::class)->packages()['studio_luxury'])->toBe(['posts' => 40, 'reels' => 8, 'tours' => 1, 'enhance' => 5, 'voice' => 2, 'features' => ['spanish']]);
});

it('builds the brand kit: headshot re-encoded and cut out, brokerage logo and brand lock', function (): void {
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('studio_cutout'), true, ['api_key' => 'rbg-key'], null, $this->hq);
    Http::fake(['api.remove.bg/v1.0/removebg' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png'])]);
    $this->actingAs($this->ann)->post('/studio/brand', ['headshot' => UploadedFile::fake()->image('me.jpg', 300, 300)])->assertSessionHasErrors('headshot'); // too small
    $this->actingAs($this->ann)->post('/studio/brand', ['headshot' => UploadedFile::fake()->image('me.jpg', 800, 1000), 'collection' => 'coastal',
        'colors' => ['primary' => '#0a2540', 'accent' => 'red'], 'fonts' => ['heading' => 'Cormorant Garamond', 'body' => 'Comic Sans'],
        'details' => ['title' => 'Luxury Home Specialist', 'licence_line' => 'TX #0123456', 'phone' => '512-555-0100', 'tagline' => '<b>Homes</b> with heart'], 'languages' => ['en', 'es', 'fr']])
        ->assertSessionHasNoErrors();
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.remove.bg/v1.0/removebg' && $r->hasHeader('X-Api-Key', 'rbg-key'));
    $kit = app(BrandKits::class)->forUser($this->ann);
    expect($kit['collection'])->toBe('coastal')->and($kit['colors'])->toBe(['primary' => '#0A2540', 'accent' => '#C9A227', 'ink' => '#111111', 'paper' => '#FFFFFF'])
        ->and($kit['fonts'])->toBe(['heading' => 'Cormorant Garamond', 'body' => 'Inter'])->and($kit['details']['tagline'])->toBe('Homes with heart')
        ->and($kit['details']['display_name'])->toBe('Ann Agent')->and($kit['languages'])->toBe(['en', 'es'])
        ->and($kit['cutout'])->toEndWith('-cutout.png')->and($kit['headshot'])->toEndWith('.png');
    expect(app(BrandKits::class)->missing($this->ann))->toBe(['The brokerage logo (your broker adds it)']);

    $this->actingAs($this->ann)->post('/studio/brand', ['for' => 'brokerage', 'brand_lock' => true])->assertForbidden();
    $this->actingAs($this->owner)->post('/studio/brand', ['for' => 'brokerage', 'logo' => UploadedFile::fake()->image('logo.png', 600, 200), 'collection' => 'luxury_noir',
        'colors' => ['primary' => '#000000', 'accent' => '#d4af37'], 'brand_lock' => '1', 'details' => ['disclaimer' => 'Each office independently owned.']])->assertSessionHasNoErrors();
    $kit = app(BrandKits::class)->forUser($this->ann);
    expect($kit['locked'])->toBeTrue()->and($kit['collection'])->toBe('luxury_noir')->and($kit['colors']['primary'])->toBe('#000000')
        ->and($kit['details']['disclaimer'])->toBe('Each office independently owned.')->and($kit['details']['title'])->toBe('Luxury Home Specialist') // details stay hers
        ->and($kit['logo'])->not->toBeNull()->and(app(BrandKits::class)->missing($this->ann))->toBe([]);
    $this->actingAs($this->ann)->get('/studio/brand')->assertInertia(fn (Assert $p) => $p->component('studio/brand')->where('kit.locked', true)->where('brokerage', null));
});
