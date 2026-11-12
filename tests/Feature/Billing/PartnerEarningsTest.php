<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Catalogue;
use Modules\Billing\Gateway\FakeGateway;
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Billing\Models\AgentSubscription;
use Modules\Billing\PartnerEarnings;
use Modules\Billing\PaymentSettings;
use Modules\Billing\Pricing;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-10 10:00'));
    config(['core.hq_host' => 'app.rightally.io']);
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['email' => 'boss@acme.test']);
    $this->pat = User::factory()->create(['first_name' => 'Pat']);
    $this->kim = User::factory()->create(['first_name' => 'Kim']);
    $this->gateway = new FakeGateway;
    app()->instance(PaymentGateway::class, $this->gateway);
    app(PaymentSettings::class)->setOptions(true, false, $this->owner);
    $catalogue = app(Catalogue::class);
    $catalogue->saveTiers([['min_paying_agents' => 1, 'percent' => '20']]);
    $this->pro = $catalogue->savePlan(null, ['key' => 'pro', 'name' => 'Pro', 'list_cents' => 20_000, 'floor_cents' => 10_000, 'features' => ['backoffice.basic']]);
    app(Pricing::class)->setPrice($this->pro, 15_000, true, $this->owner);
    foreach ([$this->pat, $this->kim] as $i => $agent) {
        AgentSubscription::query()->create(['user_id' => $agent->id, 'agent_plan_id' => $this->pro->id, 'status' => 'active', 'price_cents' => 15_000, 'gateway_ref' => 'sub_'.$i]);
    }
});

function paidInvoice(User $agent, string $invoice, int $paid = 15_900, int $tax = 900): void
{
    $event = ['id' => 'evt_'.$invoice, 'type' => 'invoice.paid', 'data' => ['object' => ['id' => $invoice, 'subscription' => 'sub', 'amount_paid' => $paid, 'tax' => $tax,
        'subscription_details' => ['metadata' => ['tenant_id' => (string) test()->tenant->id, 'user_id' => (string) $agent->id]]]]];
    test()->call('POST', 'http://app.rightally.io/billing/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'fake-signature', 'CONTENT_TYPE' => 'application/json'], json_encode($event));
}

function hook(array $event): void
{
    test()->call('POST', 'http://app.rightally.io/billing/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'fake-signature', 'CONTENT_TYPE' => 'application/json'], json_encode($event));
}

it('records what agents actually paid, without sales tax, once per invoice', function (): void {
    paidInvoice($this->pat, 'in_1');
    paidInvoice($this->pat, 'in_1');

    $row = DB::table('billing_collections')->sole();
    expect((int) $row->amount_cents)->toBe(15_000)->and((int) $row->price_cents)->toBe(15_000)->and((int) $row->floor_cents)->toBe(10_000)->and($row->month)->toBe('2026-06');
});

it('creates the monthly statement: commission on the floor plus all of the markup, per agent', function (): void {
    paidInvoice($this->pat, 'in_1');
    paidInvoice($this->kim, 'in_2');
    $earnings = app(PartnerEarnings::class);

    $s = $earnings->statement($this->tenant, '2026-06');
    expect($s['paying_agents'])->toBe(2)->and($s['commission_cents'])->toBe(4_000)->and($s['markup_cents'])->toBe(10_000)->and($s['payable_cents'])->toBe(14_000)
        ->and(json_decode((string) $s['lines'], true)[0])->toMatchArray(['agent' => 'Pat '.$this->pat->last_name, 'commission_cents' => 2_000, 'markup_cents' => 5_000]);
    $earnings->statement($this->tenant, '2026-06');
    expect(DB::table('billing_partner_statements')->count())->toBe(1);
});

it('reverses earnings for a refund on the next statement, once', function (): void {
    paidInvoice($this->pat, 'in_1');
    app(PartnerEarnings::class)->statement($this->tenant, '2026-06');
    hook(['id' => 'evt_r1', 'type' => 'charge.refunded', 'data' => ['object' => ['invoice' => 'in_1', 'amount_refunded' => 15_000]]]);

    $july = app(PartnerEarnings::class)->statement($this->tenant, '2026-07');
    $august = app(PartnerEarnings::class)->statement($this->tenant, '2026-08');
    expect($july['adjustments_cents'])->toBe(-7_000)->and($august['adjustments_cents'])->toBe(0);
});

it('carries amounts until payouts are set up and reach the minimum, then pays once', function (): void {
    paidInvoice($this->pat, 'in_1');
    $this->travelTo(CarbonImmutable::parse('2026-07-02 06:00'));
    $this->artisan('billing:partner-payouts')->assertSuccessful();
    expect(DB::table('billing_partner_statements')->where('month', '2026-06')->value('status'))->toBe('carried')->and($this->gateway->transfers)->toBe([]);

    // Owner connects Stripe; Stripe confirms the account can receive payouts.
    $location = (string) $this->actingAs($this->owner)->post('/settings/partner/connect', [], ['X-Inertia' => 'true'])->headers->get('X-Inertia-Location');
    expect($location)->toStartWith('https://connect.example/onboard/acct_fake_');
    $account = (string) $this->tenant->fresh()?->getAttribute('connect_account');
    hook(['id' => 'evt_a1', 'type' => 'account.updated', 'data' => ['object' => ['id' => $account, 'payouts_enabled' => true, 'details_submitted' => true]]]);
    expect((bool) $this->tenant->fresh()?->getAttribute('connect_ready'))->toBeTrue();

    paidInvoice($this->kim, 'in_2');
    $this->travelTo(CarbonImmutable::parse('2026-08-02 06:00'));
    $this->artisan('billing:partner-payouts')->assertSuccessful();
    $this->artisan('billing:partner-payouts')->assertSuccessful();

    $july = DB::table('billing_partner_statements')->where('month', '2026-07')->sole();
    expect((int) $july->carried_in_cents)->toBe(7_000)->and((int) $july->payable_cents)->toBe(14_000)->and($july->status)->toBe('paid')
        ->and($this->gateway->transfers)->toHaveCount(1)->and(array_values($this->gateway->transfers)[0])->toMatchArray(['account' => $account, 'cents' => 14_000]);
});

it('holds amounts under the minimum payout', function (): void {
    app(Catalogue::class)->saveTiers([['min_paying_agents' => 1, 'percent' => '1']]);
    app(Pricing::class)->setPrice($this->pro, 10_000, true, $this->owner);
    AgentSubscription::query()->update(['price_cents' => 10_000]);
    $this->tenant->forceFill(['connect_account' => 'acct_x', 'connect_ready' => true])->save();
    paidInvoice($this->pat, 'in_1', 10_000, 0);

    app(PartnerEarnings::class)->statement($this->tenant->fresh(), '2026-06');
    expect(app(PartnerEarnings::class)->payout($this->tenant->fresh(), '2026-06'))->toBe('carried');
});

it('shows owners their partner earnings', function (): void {
    paidInvoice($this->pat, 'in_1');

    $this->actingAs($this->owner)->get('/settings/partner')->assertInertia(fn (Assert $page) => $page
        ->component('settings/partner')->where('thisMonth.commission_cents', 2_000)->where('thisMonth.markup_cents', 5_000)
        ->where('byPlan.0.agents', 2)->where('connect.ready', false));
    $this->actingAs($this->pat)->get('/settings/partner')->assertForbidden();
});
