<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Catalogue;
use Modules\Billing\Entitlements;
use Modules\Billing\Gateway\FakeGateway;
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Billing\Gateway\StripeGateway;
use Modules\Billing\Models\AgentSubscription;
use Modules\Billing\PaymentFailed;
use Modules\Billing\PaymentSettings;
use Modules\Billing\Pricing;
use Modules\Core\Audit\FlushAuditOutbox;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00'));
    config(['core.hq_host' => 'app.rightally.io']);
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'email' => 'pat@acme.test']);
    $this->gateway = new FakeGateway;
    app()->instance(PaymentGateway::class, $this->gateway);
    $this->settings = app(PaymentSettings::class);
    $this->settings->setOptions(true, false, $this->owner);
    $catalogue = app(Catalogue::class);
    $catalogue->saveTiers([['min_paying_agents' => 1, 'percent' => '20']]);
    $this->pro = $catalogue->savePlan(null, ['key' => 'pro', 'name' => 'Pro', 'list_cents' => 20_000, 'floor_cents' => 10_000, 'features' => ['backoffice.basic', 'crm.ai']]);
    app(Pricing::class)->setPrice($this->pro, 15_000, true, $this->owner);
});

function hqSupport(): User
{
    test()->artisan('tenants:bootstrap-hq');
    $hq = Tenant::query()->where('type', 'hq')->sole();

    return app(TenantContext::class)->runAs($hq, fn () => User::factory()->hqStaff()->create());
}

function webhook(array $event, string $signature = 'fake-signature')
{
    return test()->call('POST', 'http://app.rightally.io/billing/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], json_encode($event));
}

function stripeEvent(string $id, string $type, array $object): array
{
    return ['id' => $id, 'type' => $type, 'data' => ['object' => $object + ['metadata' => [
        'tenant_id' => (string) test()->tenant->id, 'user_id' => (string) test()->agent->id, 'agent_plan_id' => (string) test()->pro->id, 'price_cents' => '15000',
    ]]]];
}

it('makes agents pay RightAlly by default, which brokers cannot change, unless HQ sets an exception', function (): void {
    expect(app(Entitlements::class)->model())->toBe('agent_pays')->and(app(Entitlements::class)->brokerMayChoose())->toBeFalse()
        ->and(fn () => app(Pricing::class)->configure('brokerage_pays', 'minimal', $this->owner))->toThrow(ValidationException::class);
    app(Pricing::class)->configure('agent_pays', 'blocked', $this->owner);
    expect($this->tenant->fresh()?->getAttribute('no_plan_policy'))->toBe('blocked');

    $support = hqSupport();
    $this->actingAs($support)->post("http://app.rightally.io/hq/tenants/{$this->tenant->ulid}/billing-exception", ['enabled' => true])->assertSessionHasErrors('reason');
    $this->actingAs($support)->post("http://app.rightally.io/hq/tenants/{$this->tenant->ulid}/billing-exception", ['enabled' => true, 'reason' => 'Signed brokerage agreement, 12 Jan']);
    app(TenantContext::class)->set($this->tenant->fresh());
    expect(app(Entitlements::class)->model())->toBe('brokerage_pays');
    (new FlushAuditOutbox)->handle();
    expect(DB::table('audit_events')->where('action', 'billing.exception.enabled')->where('tenant_id', $this->tenant->id)->exists())->toBeTrue();
});

it('keeps Stripe keys encrypted and never returns them, and only goes live with live keys and a fresh password', function (): void {
    expect(fn () => $this->settings->saveKeys('test', 'pk_live_x', 'sk_test_x', 'whsec_x', $this->owner))->toThrow(ValidationException::class);
    $this->settings->saveKeys('test', 'pk_test_abc', 'sk_test_secret123', 'whsec_hook', $this->owner);

    expect(DB::table('billing_settings')->value('test_keys'))->not->toContain('sk_test_secret123')
        ->and(json_encode($this->settings->summary()))->not->toContain('sk_test')
        ->and($this->settings->keys()['secret'])->toBe('sk_test_secret123')
        ->and(fn () => $this->settings->setMode('live', $this->owner))->toThrow(ValidationException::class);

    $support = hqSupport();
    $this->actingAs($support)->post('http://app.rightally.io/hq/payments/mode', ['mode' => 'live'])->assertRedirectContains('confirm-password');
    $this->actingAs($this->agent)->get('/plans')->assertInertia(fn (Assert $page) => $page->where('paymentsTestMode', true));
});

it('sends the agent to checkout at the brokerage price', function (): void {
    $this->actingAs($this->agent)->post("/plans/{$this->pro->ulid}/checkout", [], ['X-Inertia' => 'true'])
        ->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://checkout.example/session/1');

    expect($this->gateway->checkouts[0])->toMatchArray(['email' => 'pat@acme.test', 'unitCents' => 15_000, 'interval' => 'month', 'automaticTax' => false])
        ->and($this->gateway->checkouts[0]['metadata']['tenant_id'])->toBe((string) $this->tenant->id);
});

it('activates on a signed event, once, and handles failed payments with a 7-day grace period', function (): void {
    Notification::fake();
    webhook(stripeEvent('evt_bad', 'checkout.session.completed', ['subscription' => 'sub_1', 'customer' => 'cus_1']), 'wrong')->assertStatus(400);

    webhook(stripeEvent('evt_1', 'checkout.session.completed', ['subscription' => 'sub_1', 'customer' => 'cus_1']))->assertJson(['processed' => true]);
    webhook(stripeEvent('evt_1', 'checkout.session.completed', ['subscription' => 'sub_1', 'customer' => 'cus_1']))->assertJson(['processed' => false]);
    expect(AgentSubscription::query()->where('status', 'active')->count())->toBe(1);
    app(Entitlements::class)->forget();
    expect(app(Entitlements::class)->allows($this->agent, 'crm.ai'))->toBeTrue();

    webhook(stripeEvent('evt_2', 'invoice.payment_failed', ['subscription' => 'sub_1']));
    $sub = AgentSubscription::query()->where('user_id', $this->agent->id)->latest('id')->firstOrFail();
    expect($sub->status)->toBe('grace')->and($sub->grace_until?->toDateString())->toBe('2026-06-08');
    Notification::assertSentTo($this->agent, PaymentFailed::class);

    webhook(stripeEvent('evt_3', 'invoice.paid', ['subscription' => 'sub_1', 'period_end' => CarbonImmutable::parse('2026-07-01')->getTimestamp()]));
    expect($sub->fresh()?->status)->toBe('active');

    webhook(stripeEvent('evt_4', 'invoice.payment_failed', ['subscription' => 'sub_1']));
    $this->travel(8)->days();
    $this->artisan('billing:end-grace')->assertSuccessful();
    app(Entitlements::class)->forget();
    expect($sub->fresh()?->status)->toBe('ended')->and($this->gateway->cancelled)->toBe(['sub_1'])
        ->and(app(Entitlements::class)->allows($this->agent, 'crm.ai'))->toBeFalse();
});

it('lets owners sponsor an agent and agents manage their card', function (): void {
    $this->actingAs($this->agent)->get('/plans/portal')->assertSessionHasErrors('portal');

    $this->actingAs($this->owner)->post('/settings/agent-plans-sponsor', ['agent' => $this->agent->ulid, 'plan' => $this->pro->ulid])->assertSessionHasNoErrors();
    $this->actingAs($this->agent)->get('/plans')->assertInertia(fn (Assert $page) => $page->where('subscription.sponsored', true)->where('current', 'Pro'));

    AgentSubscription::query()->update(['sponsored' => false, 'customer_ref' => 'cus_9']);
    $this->actingAs($this->agent)->get('/plans/portal')->assertRedirect('https://portal.example/cus_9');
    $this->actingAs($this->agent)->post('/settings/agent-plans-sponsor', ['agent' => $this->agent->ulid, 'plan' => $this->pro->ulid])->assertSessionHasErrors('agent');
});

it('verifies Stripe signatures and sends Stripe the brokerage price', function (): void {
    $this->settings->saveKeys('test', 'pk_test_abc', 'sk_test_key', 'whsec_secret', $this->owner);
    $stripe = app(StripeGateway::class);
    $payload = '{"id":"evt_x","type":"invoice.paid"}';
    $t = time();

    expect($stripe->verifyEvent($payload, "t={$t},v1=".hash_hmac('sha256', "{$t}.{$payload}", 'whsec_secret'))['id'])->toBe('evt_x')
        ->and(fn () => $stripe->verifyEvent($payload.' ', "t={$t},v1=".hash_hmac('sha256', "{$t}.{$payload}", 'whsec_secret')))->toThrow(RuntimeException::class)
        ->and(fn () => $stripe->verifyEvent($payload, 't='.($t - 600).',v1='.hash_hmac('sha256', ($t - 600).".{$payload}", 'whsec_secret')))->toThrow(RuntimeException::class);

    Http::fake(['https://api.stripe.com/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.com/c/1'])]);
    expect($stripe->subscriptionCheckout('pat@acme.test', 'RightAlly Pro', 15_000, 'month', 'https://ok', 'https://no', ['tenant_id' => '1'], true))->toBe('https://checkout.stripe.com/c/1');
    Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer sk_test_key')
        && $r['line_items'][0]['price_data']['unit_amount'] === 15_000 && $r['automatic_tax']['enabled'] === 'true' && $r['metadata']['tenant_id'] === '1');
});
