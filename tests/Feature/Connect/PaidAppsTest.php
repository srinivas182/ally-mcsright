<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Credits\AutoTopUp;
use Modules\Billing\Credits\CreditLedger;
use Modules\Billing\Gateway\FakeGateway;
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Billing\Models\Package;
use Modules\Billing\PaymentSettings;
use Modules\Connect\AppRegistry;
use Modules\Connect\Models\AppAddon;
use Modules\Connect\Models\Connection;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Support\Connections;

/* Sprint C6b: paid apps — HQ sets an app free for everyone or a monthly add-on (with packages that include it);
   a brokerage adds it before connecting, it renews monthly from credits, and unpaid connections pause. */

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->staff = User::factory()->staff()->create();
    $this->hq = User::factory()->make(['id' => 999999]);
    DB::connection(Connections::control())->table('credit_settings')->insert(['credit_value_microcents' => 1_000_000, 'enforce' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->xero = app(AppCatalog::class)->find('xero');
    app(AppRegistry::class)->save($this->xero, true, ['client_id' => 'xe-id', 'client_secret' => 'xe-secret'], null, $this->hq);
    app(AppRegistry::class)->savePrice($this->xero, 2000, [], $this->hq); // $20 a month = 2,000 credits at a cent each
});

function xeroStart(User $as): TestResponse
{
    return test()->actingAs($as)->post('/apps/xero/oauth', [], ['X-Inertia' => 'true']);
}

it('blocks connecting until the owner adds it, and charges the month from the brokerage\'s credits', function (): void {
    xeroStart($this->owner)->assertSessionHasErrors(['app' => 'Xero is an add-on: $20.00 a month. Add it first, then connect.']);

    $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->post('/apps/xero/addon')->assertSessionHasErrors('addon'); // only the owner buys for the brokerage
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->post('/apps/xero/addon')->assertSessionHasErrors('addon'); // no credits yet

    app(CreditLedger::class)->add(null, 5000, 'purchase', 'Test credits', $this->owner);
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->post('/apps/xero/addon')->assertSessionHasNoErrors();
    $addon = AppAddon::query()->sole();
    expect($addon->only(['status', 'price_cents', 'credits', 'payer_user_id']))->toBe(['status' => 'active', 'price_cents' => 2000, 'credits' => 2000, 'payer_user_id' => null])
        ->and($addon->period_end->toDateString())->toBe('2026-12-15')
        ->and(app(CreditLedger::class)->balanceOf(null))->toBe(3000);

    xeroStart($this->owner)->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->get('/apps/xero')->assertInertia(fn (Assert $p) => $p->where('app.price.active', true)->where('app.price.cents', 2000)
        ->where('app.price.payer', 'brokerage'));
});

it('is open without an add-on when the brokerage\'s package includes it, and free apps are untouched', function (): void {
    $pkg = Package::query()->create(['name' => 'Enterprise', 'monthly_cents' => 49_900, 'yearly_cents' => 0, 'ailly_conversations' => 0, 'features' => [], 'active' => true]);
    app(AppRegistry::class)->savePrice($this->xero, 2000, [$pkg->id], $this->hq);
    $this->tenant->forceFill(['billing_package_id' => $pkg->id])->save();
    xeroStart($this->owner)->assertSessionHasNoErrors();

    app(AppRegistry::class)->savePrice($this->xero, null, [], $this->hq);
    expect(app(AppRegistry::class)->price($this->xero))->toBeNull();
});

it('renews monthly, pauses the connection when credits run out, and resumes when added again', function (): void {
    app(CreditLedger::class)->add(null, 2500, 'purchase', 'Test credits', $this->owner);
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->post('/apps/xero/addon')->assertSessionHasNoErrors();
    $c = Connection::query()->create(['app_key' => 'xero', 'label' => 'Acme', 'status' => 'connected', 'credentials' => [], 'settings' => [], 'connected_by' => $this->owner->id]);

    $this->travel(31)->days(); // 500 credits left: not enough for the next month
    $this->artisan('connect:renew-addons')->assertSuccessful();
    expect(AppAddon::query()->sole()->status)->toBe('lapsed')->and($c->fresh()?->status)->toBe('paused')
        ->and($c->fresh()?->last_error)->toContain('Not enough RightAlly credits');

    app(CreditLedger::class)->add(null, 2000, 'purchase', 'Top up', $this->owner);
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->post('/apps/xero/addon')->assertSessionHasNoErrors();
    expect($c->fresh()?->status)->toBe('connected')->and(AppAddon::query()->sole()->status)->toBe('active');
});

it('cancels at the end of the paid month, then pauses the connection', function (): void {
    app(CreditLedger::class)->add(null, 5000, 'purchase', 'Test credits', $this->owner);
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->post('/apps/xero/addon');
    $c = Connection::query()->create(['app_key' => 'xero', 'label' => 'Acme', 'status' => 'connected', 'credentials' => [], 'settings' => [], 'connected_by' => $this->owner->id]);
    $this->actingAs($this->owner)->delete('/apps/xero/addon')->assertSessionHasNoErrors();

    $this->artisan('connect:renew-addons'); // still within the paid month
    expect($c->fresh()?->status)->toBe('connected');
    $this->travel(31)->days();
    $this->artisan('connect:renew-addons');
    expect(AppAddon::query()->sole()->status)->toBe('cancelled')->and($c->fresh()?->status)->toBe('paused')
        ->and(app(CreditLedger::class)->balanceOf(null))->toBe(3000); // no second month charged
});

it('can be paid on the payer\'s saved card instead of credits, and switched either way', function (): void {
    $gateway = new FakeGateway;
    $gateway->cards['cus_1'] = ['brand' => 'visa', 'last4' => '4242'];
    app()->instance(PaymentGateway::class, $gateway);
    $confirm = ['auth.password_confirmed_at' => now()->getTimestamp()];

    $this->actingAs($this->owner)->withSession($confirm)->post('/apps/xero/addon', ['pay_with' => 'card'])->assertSessionHasErrors('addon'); // no saved card yet
    app(AutoTopUp::class)->rememberCard(null, 'cus_1');
    $this->actingAs($this->owner)->get('/apps/xero')->assertInertia(fn (Assert $p) => $p->where('app.price.card.last4', '4242')->where('app.price.card_cents', 2091));

    $this->actingAs($this->owner)->withSession($confirm)->post('/apps/xero/addon', ['pay_with' => 'card'])->assertSessionHasNoErrors();
    expect(AppAddon::query()->sole()->pay_with)->toBe('card')->and(array_values($gateway->charges)[0]['cents'])->toBe(2091) // $20 plus Stripe's 2.9% + 30¢, grossed up
        ->and(app(CreditLedger::class)->balanceOf(null))->toBe(0); // no credits used

    $c = Connection::query()->create(['app_key' => 'xero', 'label' => 'Acme', 'status' => 'connected', 'credentials' => [], 'settings' => [], 'connected_by' => $this->owner->id]);
    $gateway->chargeStatus = 'failed';
    $this->travel(31)->days();
    $this->artisan('connect:renew-addons');
    expect(AppAddon::query()->sole()->status)->toBe('lapsed')->and($c->fresh()?->status)->toBe('paused');

    // Back on credits.
    app(CreditLedger::class)->add(null, 3000, 'purchase', 'Credits', $this->owner);
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->post('/apps/xero/addon', ['pay_with' => 'credits'])->assertSessionHasNoErrors();
    expect(AppAddon::query()->sole()->pay_with)->toBe('credits')->and(app(CreditLedger::class)->balanceOf(null))->toBe(1000)->and($c->fresh()?->status)->toBe('connected');
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->post('/apps/xero/addon/pay-with', ['pay_with' => 'card'])->assertSessionHasNoErrors();
    expect(AppAddon::query()->sole()->pay_with)->toBe('card');
});

it('lets HQ set the card fee, and a zero fee keeps the same price', function (): void {
    $settings = app(PaymentSettings::class);
    expect($settings->cardPrice(2000))->toBe(2091);
    $settings->setCardFee(0, 0, $this->owner);
    expect($settings->cardPrice(2000))->toBe(2000);
    $settings->setCardFee(350, 50, $this->owner);
    expect($settings->cardPrice(2000))->toBe(2125)->and($settings->summary()['card_fee_bps'])->toBe(350);
});
