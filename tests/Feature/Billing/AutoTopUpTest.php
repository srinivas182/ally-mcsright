<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Credits\CardNeedsAttention;
use Modules\Billing\Credits\CreditLedger;
use Modules\Billing\Credits\RateCard;
use Modules\Billing\Gateway\FakeGateway;
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Billing\Models\Package;
use Modules\Billing\PaymentSettings;
use Modules\Billing\Usage\MapsPaused;
use Modules\Billing\Usage\PlatformMapViews;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Maps;
use Modules\Sites\SiteSetup;

/* Sprint 18B-2: saved cards, automatic top-up, a paused map for a brokerage that doesn't pay, one-hour map tokens. */

const PK = 'pk.eyJ1IjoicmlnaHRhbGx5In0.Zm9vYmFy';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-10 15:00', 'UTC'));
    Notification::fake();
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['email' => 'boss@acme.test']);
    $this->gateway = new FakeGateway;
    $this->gateway->cards['cus_1'] = ['brand' => 'visa', 'last4' => '4242'];
    app()->instance(PaymentGateway::class, $this->gateway);
    app(PaymentSettings::class)->setOptions(true, true, $this->owner);
    DB::connection(Connections::control())->table('credit_packs')->insert(['ulid' => $this->pack = (string) Str::ulid(), 'name' => 'Top-up 1k', 'credits' => 1000,
        'price_cents' => 1000, 'audience' => 'both', 'active' => true, 'sort' => 0, 'created_at' => now(), 'updated_at' => now()]);
});

function tuEvent(string $id, string $type, array $object): void
{
    test()->call('POST', 'http://app.rightally.io/billing/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'fake-signature', 'CONTENT_TYPE' => 'application/json'],
        json_encode(['id' => $id, 'type' => $type, 'data' => ['object' => $object]]))->assertOk();
    app()->forgetScopedInstances();
    app(TenantContext::class)->set(test()->tenant);
}

function saveCard(): void
{
    test()->actingAs(test()->owner)->post('/settings/credits/buy', ['pack' => test()->pack]);
    $p = DB::connection(Connections::cell())->table('credit_purchases')->orderByDesc('id')->first();
    tuEvent('evt_card', 'checkout.session.completed', ['id' => 'cs_1', 'customer' => 'cus_1', 'payment_intent' => 'pi_1', 'payment_status' => 'paid', 'amount_total' => 1000,
        'metadata' => ['kind' => 'credit_pack', 'tenant_id' => (string) test()->tenant->id, 'purchase' => $p->ulid]]);
}

it('saves the card at checkout, then buys in one click', function (): void {
    saveCard();
    expect($this->gateway->oneTime[0]['saveCard'])->toBeTrue()->and($this->gateway->oneTime[0]['customerRef'])->toBeNull();

    $this->actingAs($this->owner)->get('/settings/credits')->assertInertia(fn (Assert $p) => $p->where('autopay.card', ['brand' => 'visa', 'last4' => '4242']));
    $this->actingAs($this->owner)->post('/settings/credits/buy', ['pack' => $this->pack]);
    expect($this->gateway->oneTime[1]['customerRef'])->toBe('cus_1'); // the next checkout reuses the saved customer

    $this->actingAs($this->owner)->post('/settings/credits/buy-saved', ['pack' => $this->pack])->assertRedirect();
    app()->forgetScopedInstances();
    app(TenantContext::class)->set($this->tenant);
    expect(app(CreditLedger::class)->poolSummary()['available'])->toBe(2000)->and($this->gateway->charges)->toHaveCount(1);
});

it('tops up automatically when credits drop below the threshold, once per cooldown', function (): void {
    saveCard();
    $this->actingAs($this->owner)->post('/settings/credits/autopay', ['enabled' => true, 'threshold' => 990, 'pack' => $this->pack])->assertSessionHasNoErrors();
    app()->forgetScopedInstances();
    app(TenantContext::class)->set($this->tenant);

    app(CreditLedger::class)->charge('ai.site_post', $this->owner->id, 'a1'); // 1000 → 980: below 990
    expect($this->gateway->charges)->toHaveCount(1)->and(app(CreditLedger::class)->poolSummary()['available'])->toBe(1980);

    app(CreditLedger::class)->add(null, -1000, 'adjust', 'test', $this->owner);
    app(CreditLedger::class)->charge('ai.site_post', $this->owner->id, 'a2'); // below again, but within 10 minutes
    expect($this->gateway->charges)->toHaveCount(1);

    $this->travel(11)->minutes();
    app(CreditLedger::class)->charge('ai.site_post', $this->owner->id, 'a3');
    expect($this->gateway->charges)->toHaveCount(2);
});

it('tells the owner when the saved card fails, and stops after three failures', function (): void {
    saveCard();
    $this->actingAs($this->owner)->post('/settings/credits/autopay', ['enabled' => true, 'threshold' => 990, 'pack' => $this->pack]);
    app()->forgetScopedInstances();
    app(TenantContext::class)->set($this->tenant);
    $this->gateway->chargeStatus = 'failed';

    foreach (range(1, 4) as $i) {
        app(CreditLedger::class)->charge('ai.site_post', $this->owner->id, 'f'.$i);
        $this->travel(11)->minutes();
    }
    expect($this->gateway->charges)->toHaveCount(3);
    Notification::assertSentToTimes($this->owner, CardNeedsAttention::class, 3);
    $this->actingAs($this->owner)->get('/settings/credits')->assertInertia(fn (Assert $p) => $p->where('autopay.failures', 3)->where('autopay.last_error', 'Your card was declined.'));
});

it('adds credits for a top-up that clears later, and reports one that fails later', function (): void {
    saveCard();
    $this->gateway->chargeStatus = 'processing';
    $this->actingAs($this->owner)->post('/settings/credits/buy-saved', ['pack' => $this->pack]);
    $p = DB::connection(Connections::cell())->table('credit_purchases')->orderByDesc('id')->first();
    expect($p->status)->toBe('pending')->and($p->source)->toBe('saved');

    tuEvent('evt_ok', 'payment_intent.succeeded', ['id' => 'pi_x', 'amount_received' => 1000, 'customer' => 'cus_1',
        'metadata' => ['kind' => 'credit_pack', 'tenant_id' => (string) $this->tenant->id, 'purchase' => $p->ulid]]);
    expect(app(CreditLedger::class)->poolSummary()['available'])->toBe(2000);

    $this->actingAs($this->owner)->post('/settings/credits/buy-saved', ['pack' => $this->pack]);
    $q = DB::connection(Connections::cell())->table('credit_purchases')->orderByDesc('id')->first();
    tuEvent('evt_bad', 'payment_intent.payment_failed', ['id' => 'pi_y', 'last_payment_error' => ['message' => 'Insufficient funds.'],
        'metadata' => ['kind' => 'credit_pack', 'tenant_id' => (string) $this->tenant->id, 'purchase' => $q->ulid]]);
    expect(DB::connection(Connections::cell())->table('credit_purchases')->where('ulid', $q->ulid)->value('status'))->toBe('failed');
    Notification::assertSentTo($this->owner, CardNeedsAttention::class, fn (CardNeedsAttention $n): bool => $n->error === 'Insufficient funds.');
});

it('pauses the map only for a brokerage that doesn\'t pay, counts who missed it, and brings it back when paid', function (): void {
    $package = Package::query()->create(['name' => 'Growth', 'monthly_cents' => 10_000, 'yearly_cents' => 0, 'ailly_conversations' => 0, 'features' => [], 'active' => true]);
    $package->forceFill(['included_map_views' => 1])->save();
    DB::connection(Connections::control())->table('billing_tenant_subscriptions')->insert(['tenant_id' => $this->tenant->id, 'package_id' => $package->id,
        'interval' => 'month', 'status' => 'active', 'price_cents' => 10_000, 'created_at' => now(), 'updated_at' => now()]);
    app(Maps::class)->save(PK, $this->staff);
    $sites = app(SiteSetup::class);
    $sites->publish($sites->create('modern', ['name' => 'Acme Realty', 'area' => 'Austin'], $this->owner), true, $this->owner);
    app(RateCard::class)->saveEnforced(true, $this->staff);
    $web = 'http://acme.rightally.site';

    $this->postJson($web.'/_map')->assertJson(['ok' => true]); // the included view
    $this->postJson($web.'/_map')->assertJson(['ok' => false]); // out: paused
    $this->postJson($web.'/_map')->assertJson(['ok' => false]);
    Notification::assertSentToTimes($this->owner, MapsPaused::class, 1);

    app(TenantContext::class)->set($this->tenant);
    $this->actingAs($this->owner)->get('/settings/credits')->assertInertia(fn (Assert $p) => $p->where('credits.maps_paused.missed', 2));

    app(TenantContext::class)->forget();
    $this->artisan('credits:paused-maps')->assertSuccessful();
    Notification::assertSentTo($this->owner, MapsPaused::class, fn (MapsPaused $n): bool => $n->reminder && $n->missed === 2);

    app(TenantContext::class)->set($this->tenant);
    app(CreditLedger::class)->add(null, 100, 'adjust', 'paid', $this->owner);
    $this->postJson($web.'/_map')->assertJson(['ok' => true]); // back as soon as there are credits
    app(TenantContext::class)->set($this->tenant);
    $this->actingAs($this->owner)->get('/settings/credits')->assertInertia(fn (Assert $p) => $p->where('credits.maps_paused', null));
});

it('gives website visitors map tokens that expire within an hour, minted from the secret token', function (): void {
    app(Maps::class)->save(PK, $this->staff);
    $sites = app(SiteSetup::class);
    $sites->publish($sites->create('modern', ['name' => 'Acme Realty', 'area' => 'Austin'], $this->owner), true, $this->owner);
    $secret = 'sk.'.rtrim(strtr(base64_encode('{"u":"rightally","a":"x"}'), '+/', '-_'), '=').'.c2VjcmV0';
    Http::fake(['https://api.mapbox.com/tokens/v2/rightally*' => Http::sequence()->push(['token' => 'tk.check'])->push(['token' => 'tk.visitor'])]);

    app(TenantContext::class)->forget();
    $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()])
        ->post('http://app.rightally.io/hq/sites/maps/secret', ['mapbox_secret' => 'pk.not-secret.x'])->assertSessionHasErrors('mapbox_secret');
    $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()])
        ->post('http://app.rightally.io/hq/sites/maps/secret', ['mapbox_secret' => $secret])->assertSessionHasNoErrors();
    expect(DB::connection(Connections::control())->table('hq_site_branding')->value('mapbox_secret'))->not->toContain('sk.');

    $this->postJson('http://acme.rightally.site/_map')->assertJson(['ok' => true, 'token' => 'tk.visitor']);
    $this->postJson('http://acme.rightally.site/_map')->assertJson(['token' => 'tk.visitor']); // shared for up to 50 minutes
    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => $r['scopes'] === Maps::VISITOR_SCOPES && str_ends_with((string) $r['expires'], 'Z'));

    Cache::flush();
    Http::fake(['*' => Http::response([], 503)]);
    $this->postJson('http://acme.rightally.site/_map')->assertJson(['token' => PK]); // Mapbox down: the public token keeps maps working
});

it('emails HQ once a month when all websites pass the map-view line, without pausing anything', function (): void {
    DB::connection(Connections::cell())->table('usage_meters')->insert(['tenant_id' => $this->tenant->id, 'period' => '2026-06', 'meter' => 'maps', 'used' => 61_000, 'over' => 0,
        'created_at' => now(), 'updated_at' => now()]);
    app(TenantContext::class)->forget();
    $this->artisan('credits:margin-watch')->assertSuccessful();
    $this->artisan('credits:margin-watch')->assertSuccessful();

    Notification::assertSentToTimes($this->staff, PlatformMapViews::class, 1);
});
