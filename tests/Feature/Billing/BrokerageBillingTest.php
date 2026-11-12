<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\BrokeragePayments;
use Modules\Billing\Catalogue;
use Modules\Billing\Gateway\FakeGateway;
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Billing\Models\AgentSubscription;
use Modules\Billing\PaymentSettings;
use Modules\Billing\Pricing;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00'));
    config(['core.hq_host' => 'app.rightally.io']);
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['email' => 'boss@acme.test']);
    $this->agent = User::factory()->create();
    $this->gateway = new FakeGateway;
    app()->instance(PaymentGateway::class, $this->gateway);
    app(PaymentSettings::class)->setOptions(true, true, $this->owner);
    $this->brokerage = app(BrokeragePayments::class);
    $this->package = $this->brokerage->savePackage(null, ['name' => 'Brokerage', 'monthly_cents' => 29_900, 'yearly_cents' => 299_000, 'ailly_conversations' => 500, 'features' => ['backoffice.basic', 'crm.ai']]);
    $catalogue = app(Catalogue::class);
    $catalogue->saveTiers([['min_paying_agents' => 1, 'percent' => '20']]);
    $this->pro = $catalogue->savePlan(null, ['key' => 'pro', 'name' => 'Pro', 'list_cents' => 20_000, 'floor_cents' => 10_000, 'features' => ['backoffice.basic']]);
    app(Pricing::class)->setPrice($this->pro, 15_000, true, $this->owner);
});

function brokerageEvent(string $id, string $type, array $object, string $interval = 'year'): array
{
    return ['id' => $id, 'type' => $type, 'data' => ['object' => $object + ['metadata' => [
        'kind' => 'brokerage', 'tenant_id' => (string) test()->tenant->id, 'package_id' => (string) test()->package->id, 'interval' => $interval, 'price_cents' => '299000',
    ]]]];
}

function signed(array $event)
{
    return test()->call('POST', 'http://app.rightally.io/billing/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'fake-signature', 'CONTENT_TYPE' => 'application/json'], json_encode($event));
}

it('lets owners pay the brokerage fee monthly or yearly, with sales tax', function (): void {
    $this->actingAs($this->owner)->post('/settings/billing/checkout', ['package' => $this->package->ulid, 'interval' => 'year'], ['X-Inertia' => 'true'])
        ->assertHeader('X-Inertia-Location', 'https://checkout.example/session/1');
    expect($this->gateway->checkouts[0])->toMatchArray(['email' => 'boss@acme.test', 'unitCents' => 299_000, 'interval' => 'year', 'automaticTax' => true])
        ->and($this->gateway->checkouts[0]['metadata']['kind'])->toBe('brokerage');

    $this->actingAs($this->agent)->post('/settings/billing/checkout', ['package' => $this->package->ulid, 'interval' => 'month'])->assertSessionHasErrors('package');
    expect($this->gateway->checkouts)->toHaveCount(1);
    $this->actingAs($this->agent)->get('/settings/billing')->assertForbidden();
});

it('makes the brokerage read-only for owners and staff after unpaid grace, never for paying agents', function (): void {
    expect($this->brokerage->readOnly())->toBeFalse();
    signed(brokerageEvent('evt_b1', 'checkout.session.completed', ['subscription' => 'sub_b', 'customer' => 'cus_b']));
    $this->actingAs($this->owner)->get('/settings/billing')->assertInertia(fn (Assert $page) => $page
        ->where('current.status', 'active')->where('current.package', 'Brokerage')->where('current.customer', 'cus_b'));

    signed(brokerageEvent('evt_b2', 'invoice.payment_failed', ['subscription' => 'sub_b']));
    $this->travel(8)->days();
    $this->artisan('billing:end-grace')->assertSuccessful();
    expect($this->brokerage->readOnly())->toBeTrue();

    $this->actingAs($this->owner)->post('/contacts', ['first_name' => 'Sam', 'email' => 's@x.test'])->assertRedirect('/settings/billing');
    $this->actingAs($this->owner)->get('/contacts')->assertOk();
    AgentSubscription::query()->create(['user_id' => $this->agent->id, 'agent_plan_id' => $this->pro->id, 'status' => 'active', 'price_cents' => 15_000]);
    $this->actingAs($this->agent)->post('/contacts', ['first_name' => 'Kim', 'email' => 'k@x.test'])->assertSessionHasNoErrors();

    signed(brokerageEvent('evt_b3', 'checkout.session.completed', ['subscription' => 'sub_b2', 'customer' => 'cus_b']));
    expect($this->brokerage->readOnly())->toBeFalse();
});

it('lets the brokerage pay part of an agent\'s plan and bills it monthly, once', function (): void {
    $sub = AgentSubscription::query()->create(['user_id' => $this->agent->id, 'agent_plan_id' => $this->pro->id, 'status' => 'active', 'price_cents' => 15_000, 'gateway_ref' => 'sub_a']);
    expect(fn () => $this->brokerage->setSubsidy($this->agent, 5_000, $this->owner))->toThrow(ValidationException::class, 'card');

    signed(brokerageEvent('evt_b1', 'checkout.session.completed', ['subscription' => 'sub_b', 'customer' => 'cus_b']));
    expect(fn () => $this->brokerage->setSubsidy($this->agent, 15_000, $this->owner))->toThrow(ValidationException::class);
    $this->actingAs($this->owner)->post('/settings/agent-plans-subsidy', ['agent' => $this->agent->ulid, 'amount' => '50'])->assertSessionHasNoErrors();
    expect($sub->fresh()?->subsidy_cents)->toBe(5_000)->and($this->gateway->amountChanges)->toBe([['subscription' => 'sub_a', 'cents' => 10_000]]);

    $sponsoredAgent = User::factory()->create();
    AgentSubscription::query()->create(['user_id' => $sponsoredAgent->id, 'agent_plan_id' => $this->pro->id, 'status' => 'active', 'price_cents' => 15_000, 'subsidy_cents' => 15_000, 'sponsored' => true]);

    $this->artisan('billing:bill-subsidies')->assertSuccessful();
    $this->artisan('billing:bill-subsidies')->assertSuccessful();
    expect($this->gateway->invoiceItems)->toHaveCount(1)
        ->and($this->gateway->invoiceItems[0])->toMatchArray(['customer' => 'cus_b', 'cents' => 20_000]);
});

it('gives each brokerage a partner code and shows its prices from it', function (): void {
    $code = $this->brokerage->partnerCode();
    expect($code)->toMatch('/^[A-Z]{1,4}-[A-Z0-9]{4}$/')->and($this->brokerage->partnerCode())->toBe($code);

    $this->getJson('http://app.rightally.io/partners/'.strtolower($code))->assertOk()
        ->assertJsonPath('brokerage', $this->tenant->name)->assertJsonPath('plans.0.price_cents', 15_000)->assertJsonPath('plans.0.list_cents', 20_000);
    $this->getJson('http://app.rightally.io/partners/NOPE-0000')->assertNotFound();

    app(Pricing::class)->setPrice($this->pro, 15_000, false, $this->owner);
    $this->getJson('http://app.rightally.io/partners/'.$code)->assertJsonCount(0, 'plans');
});
