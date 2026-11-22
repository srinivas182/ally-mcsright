<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Gateway\StripeSetupCheck;
use Modules\Billing\PaymentSettings;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/*
| HQ3: the Payments page explains how to connect Stripe step by step, and "Check my setup" says, in plain words, which
| part is right and what to fix: keys, the webhook address, the events, and whether Stripe has reached RightAlly.
*/

beforeEach(function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
});

it('lists the webhook address and every event to tick, each with what it is for', function (): void {
    $this->actingAs($this->staff)->get('http://app.rightally.io/hq/payments')->assertOk()->assertInertia(fn (Assert $p) => $p->component('hq/payments')
        ->where('webhookUrl', 'https://app.rightally.io/billing/webhook')
        ->where('events', fn ($e): bool => count($e) === 8 && $e['checkout.session.completed'] === 'A sign-up or purchase was paid (creates the brokerage)')
        ->where('check', null));
});

it('checks the Stripe setup step by step and says what to fix', function (): void {
    $url = 'https://app.rightally.io/billing/webhook';
    $check = fn () => app(StripeSetupCheck::class)->run($url);

    expect($check())->toBe([['step' => 'keys', 'ok' => false, 'message' => 'No test keys are saved yet. Paste them in the test box below (step 2).']]);

    app(PaymentSettings::class)->saveKeys('test', 'pk_test_abc', 'sk_test_secret', 'whsec_hook', $this->staff);
    Http::fake([
        'api.stripe.com/v1/balance' => Http::response(['object' => 'balance']),
        'api.stripe.com/v1/webhook_endpoints*' => Http::response(['data' => [['url' => $url, 'status' => 'enabled', 'enabled_events' => ['checkout.session.completed', 'invoice.paid']]]]),
    ]);
    $result = collect($check())->keyBy('step');
    expect($result['secret']['ok'])->toBeTrue()->and($result['webhook']['ok'])->toBeTrue()
        ->and($result['events']['ok'])->toBeFalse()->and($result['events']['message'])->toContain('invoice.payment_failed')->toContain('charge.refunded')
        ->and($result['received']['ok'])->toBeFalse()->and($result['received']['message'])->toContain('Send test event');
    Http::assertSent(fn (HttpRequest $r): bool => $r->hasHeader('Authorization', 'Bearer sk_test_secret'));

    // Once a correctly signed event arrives, the last step turns green.
    StripeSetupCheck::received('checkout.session.completed');
    expect(collect($check())->keyBy('step')['received']['ok'])->toBeTrue();

    // The button on the page runs the same check.
    $this->actingAs($this->staff)->from('http://app.rightally.io/hq/payments')->post('http://app.rightally.io/hq/payments/check')->assertRedirect()->assertSessionHas('stripe_check');
});

it('says when Stripe does not accept the secret key', function (): void {
    app(PaymentSettings::class)->saveKeys('test', 'pk_test_abc', 'sk_test_wrong', 'whsec_hook', $this->staff);
    Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'Invalid API Key']], 401)]);

    expect(collect(app(StripeSetupCheck::class)->run('https://app.rightally.io/billing/webhook'))->last())
        ->toBe(['step' => 'secret', 'ok' => false, 'message' => 'Stripe did not accept the secret key. Copy it again from Stripe › Developers › API keys and save.']);
});
