<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Credits\CreditLedger;
use Modules\Billing\Gateway\FakeGateway;
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Billing\PaymentSettings;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/* Sprint 18B: buying credit packs (brokerage pool or agent), receipts, 12-month expiry, refunds, CSV export. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-10 15:00', 'UTC'));
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['email' => 'boss@acme.test']);
    $this->agent = User::factory()->create(['email' => 'jane@acme.test', 'first_name' => 'Jane']);
    $this->other = User::factory()->create();
    $this->gateway = new FakeGateway;
    app()->instance(PaymentGateway::class, $this->gateway);
    app(PaymentSettings::class)->setOptions(true, true, $this->owner);

    app(TenantContext::class)->forget();
    $hq = 'http://app.rightally.io';
    $this->actingAs($this->staff)->post($hq.'/hq/credits/packs', ['name' => 'Brokerage 5k', 'credits' => 5000, 'price' => 45, 'audience' => 'brokerage', 'active' => true])->assertRedirect();
    $this->actingAs($this->staff)->post($hq.'/hq/credits/packs', ['name' => 'Agent 500', 'credits' => 500, 'price' => 6, 'audience' => 'agent', 'active' => true])->assertRedirect();
    $this->actingAs($this->staff)->post($hq.'/hq/credits/packs', ['name' => 'Retired', 'credits' => 10, 'price' => 1, 'audience' => 'both', 'active' => false])->assertRedirect();
    $this->packs = DB::connection(Connections::control())->table('credit_packs')->pluck('ulid', 'name');
    app(TenantContext::class)->set($this->tenant);
});

function paidEvent(string $id, string $purchase, int $total, int $tax = 0): array
{
    return ['id' => $id, 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_'.$id, 'payment_intent' => 'pi_'.$id, 'payment_status' => 'paid',
        'amount_total' => $total, 'total_details' => ['amount_tax' => $tax], 'metadata' => ['kind' => 'credit_pack', 'tenant_id' => (string) test()->tenant->id, 'purchase' => $purchase]]]];
}

function sendEvent(array $event)
{
    $r = test()->call('POST', 'http://app.rightally.io/billing/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'fake-signature', 'CONTENT_TYPE' => 'application/json'], json_encode($event));
    app()->forgetScopedInstances();
    app(TenantContext::class)->set(test()->tenant);

    return $r;
}

function lastPurchase(): object
{
    return DB::connection(Connections::cell())->table('credit_purchases')->orderByDesc('id')->first();
}

it('shows each person the packs they may buy', function (): void {
    $this->actingAs($this->owner)->get('/settings/credits')->assertInertia(fn (Assert $p) => $p->where('packs', [
        ['ulid' => $this->packs['Brokerage 5k'], 'name' => 'Brokerage 5k', 'credits' => 5000, 'price_cents' => 4500]]));
    $this->actingAs($this->agent)->get('/settings/credits')->assertInertia(fn (Assert $p) => $p->where('packs.0.name', 'Agent 500')->has('packs', 1));

    $this->actingAs($this->owner)->post('/settings/credits/policy', ['agents_use_pool' => true, 'agents_can_buy' => false])->assertRedirect();
    $this->actingAs($this->agent)->get('/settings/credits')->assertInertia(fn (Assert $p) => $p->where('packs', []));
    $this->actingAs($this->agent)->post('/settings/credits/buy', ['pack' => $this->packs['Agent 500']])->assertSessionHasErrors('pack');
});

it('sells the brokerage a pack, adds the credits once when paid, and keeps a receipt', function (): void {
    $this->actingAs($this->owner)->post('/settings/credits/buy', ['pack' => $this->packs['Brokerage 5k']], ['X-Inertia' => 'true'])
        ->assertHeader('X-Inertia-Location', 'https://checkout.example/pay/1');
    $this->actingAs($this->owner)->post('/settings/credits/buy', ['pack' => $this->packs['Agent 500']])->assertSessionHasErrors('pack'); // agents' pack
    $purchase = lastPurchase();
    expect($this->gateway->oneTime[0])->toMatchArray(['email' => 'boss@acme.test', 'cents' => 4500, 'automaticTax' => true,
        'metadata' => ['kind' => 'credit_pack', 'tenant_id' => (string) $this->tenant->id, 'purchase' => $purchase->ulid]])
        ->and($purchase->status)->toBe('pending')->and(app(CreditLedger::class)->poolSummary()['available'])->toBe(0);

    sendEvent(paidEvent('evt_1', $purchase->ulid, 4871, 371))->assertOk()->assertJson(['processed' => true]);
    sendEvent(paidEvent('evt_1', $purchase->ulid, 4871, 371))->assertJson(['processed' => false]);
    sendEvent(paidEvent('evt_2', $purchase->ulid, 4871, 371)); // a second event for the same purchase adds nothing

    $purchase = lastPurchase();
    expect(app(CreditLedger::class)->poolSummary()['available'])->toBe(5000)
        ->and($purchase->status)->toBe('paid')->and($purchase->remaining)->toBe(5000)->and((string) $purchase->expires_at)->toStartWith('2027-06-10');

    $this->actingAs($this->owner)->get('/settings/credits/receipts/'.$purchase->ulid)->assertInertia(fn (Assert $p) => $p->component('settings/credit-receipt')
        ->where('receipt.total_cents', 4871)->where('receipt.tax_cents', 371)->where('receipt.for', 'Brokerage credits')->where('receipt.payment_ref', 'pi_evt_1'));
    $this->actingAs($this->agent)->get('/settings/credits/receipts/'.$purchase->ulid)->assertNotFound();
});

it('lets agents buy for themselves', function (): void {
    $this->actingAs($this->agent)->post('/settings/credits/buy', ['pack' => $this->packs['Agent 500']], ['X-Inertia' => 'true'])->assertHeader('X-Inertia-Location');
    sendEvent(paidEvent('evt_a', lastPurchase()->ulid, 600));

    expect(app(CreditLedger::class)->userSummary($this->agent->id)['available'])->toBe(500)
        ->and(app(CreditLedger::class)->poolSummary()['available'])->toBe(0);
    $this->actingAs($this->agent)->get('/settings/credits/receipts/'.lastPurchase()->ulid)->assertOk();
    $this->actingAs($this->other)->get('/settings/credits/receipts/'.lastPurchase()->ulid)->assertNotFound();
});

it('uses included credits first, then bought ones, and ends what is left after 12 months', function (): void {
    $ledger = app(CreditLedger::class);
    $ledger->grantIncluded(30);
    $this->actingAs($this->owner)->post('/settings/credits/buy', ['pack' => $this->packs['Brokerage 5k']]);
    sendEvent(paidEvent('evt_l', lastPurchase()->ulid, 4500));
    $ledger = app(CreditLedger::class);

    $ledger->charge('ai.site_post', $this->owner->id, 'u1'); // 20 from included
    $ledger->charge('ai.site_post', $this->owner->id, 'u2'); // 10 included + 10 bought
    expect(lastPurchase()->remaining)->toBe(4990);
    $ledger->allot($this->agent, 100, $this->owner); // given to an agent: from bought credits
    expect(lastPurchase()->remaining)->toBe(4890);

    $this->travelTo(CarbonImmutable::parse('2027-06-11 01:00', 'UTC'));
    app(TenantContext::class)->forget();
    $this->artisan('credits:expire-purchases')->assertSuccessful();
    app(TenantContext::class)->set($this->tenant);
    app()->forgetScopedInstances();
    app(TenantContext::class)->set($this->tenant);

    expect(lastPurchase()->remaining)->toBe(0)->and(lastPurchase()->expired_at)->not->toBeNull()
        ->and(app(CreditLedger::class)->balanceOf(null))->toBe(0)
        ->and(app(CreditLedger::class)->userSummary($this->agent->id)['available'])->toBe(100); // given credits never expire
    $this->artisan('credits:expire-purchases')->assertSuccessful(); // nothing twice
});

it('takes back what is left of a pack when the payment is refunded', function (): void {
    $this->actingAs($this->owner)->post('/settings/credits/buy', ['pack' => $this->packs['Brokerage 5k']]);
    $ulid = lastPurchase()->ulid;
    sendEvent(paidEvent('evt_r', $ulid, 4500));
    app(CreditLedger::class)->charge('ai.site_post', $this->owner->id, 'r1'); // 20 used

    sendEvent(['id' => 'evt_ref', 'type' => 'charge.refunded', 'data' => ['object' => ['amount_refunded' => 4500,
        'metadata' => ['kind' => 'credit_pack', 'tenant_id' => (string) $this->tenant->id, 'purchase' => $ulid]]]])->assertJson(['processed' => true]);

    expect(app(CreditLedger::class)->poolSummary()['available'])->toBe(0)->and(lastPurchase()->status)->toBe('refunded');
});

it('exports the credit history as CSV, safe to open in a spreadsheet', function (): void {
    $ledger = app(CreditLedger::class);
    $ledger->add(null, 50, 'adjust', '=HYPERLINK("http://evil")', $this->owner);
    $ledger->allot($this->agent, 10, $this->owner);
    $ledger->charge('ai.site_assist', $this->agent->id, 'x1');

    $csv = $this->actingAs($this->owner)->get('/settings/credits/export?month=2026-06')->assertOk()->streamedContent();
    expect($csv)->toContain('"Date (UTC)",Type,Credits,What,Person,Automatic,Note')->toContain("'=HYPERLINK")
        ->toContain('consume,-2,"AI editing help",'.'"'.$this->agent->name.'"');

    $mine = $this->actingAs($this->agent)->get('/settings/credits/export?month=2026-06')->streamedContent();
    expect($mine)->not->toContain('HYPERLINK')->toContain('allocate_in');
    $this->actingAs($this->owner)->get('/settings/credits/export?month=2026-13')->assertSessionHasErrors('month');
});

it('shows HQ the packs and this month\'s sales', function (): void {
    $this->actingAs($this->owner)->post('/settings/credits/buy', ['pack' => $this->packs['Brokerage 5k']]);
    sendEvent(paidEvent('evt_s', lastPurchase()->ulid, 4871, 371));

    app(TenantContext::class)->forget();
    $this->actingAs($this->staff)->get('http://app.rightally.io/hq/credits')->assertInertia(fn (Assert $p) => $p->has('packs', 3)
        ->where('sales.count', 1)->where('sales.revenue_cents', 4871)->where('sales.credits', 5000)->where('sales.recent.0.tenant', $this->tenant->name));
    $this->actingAs($this->staff)->put('http://app.rightally.io/hq/credits/packs/'.$this->packs['Retired'], ['name' => 'Retired', 'credits' => 10, 'price' => 0.5,
        'audience' => 'both', 'active' => false])->assertSessionHasErrors('price');
});
