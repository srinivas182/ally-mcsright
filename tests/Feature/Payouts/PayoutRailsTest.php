<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Billing\PaymentSettings;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Calculation;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\LedgerService;
use Modules\Payouts\BankAccounts;
use Modules\Payouts\PaymentReturned;
use Modules\Payouts\PayoutRuns;
use Modules\Payouts\Rails\DwollaRail;
use Modules\Payouts\Rails\FakeRail;
use Modules\Payouts\Rails\ModernTreasuryRail;
use Modules\Payouts\Rails\RailPlatform;
use Modules\Payouts\Rails\Rails;
use Modules\Payouts\Rails\RailSetup;
use Modules\Payouts\Rails\StripeTreasuryRail;
use Modules\Payouts\Receipts;
use Modules\Transactions\Models\Deal;

beforeEach(function (): void {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    config(['core.hq_host' => 'app.rightally.io']);
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive']);
    $this->second = User::factory()->owner()->create(['first_name' => 'Second']);
    [$this->ann, $this->ben] = [User::factory()->create(['first_name' => 'Ann']), User::factory()->create(['first_name' => 'Ben'])];
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $this->platform = app(RailPlatform::class);
});

function railDeal(User $agent): void
{
    test()->actingAs($agent)->post('/deals', ['type' => 'listing', 'street' => uniqid().' Oak', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '500000']);
    $deal = Deal::query()->latest('id')->firstOrFail();
    test()->actingAs($agent)->post("/deals/{$deal->ulid}/status", ['status' => 'under_contract', 'price' => '500000', 'gci' => '15000', 'contract_date' => '2026-06-01', 'closing_date' => '2026-06-01']);
    $deal = $deal->fresh();
    $receipts = app(Receipts::class);
    $receipts->record($deal, $receipts->expected($deal, Calculation::query()->findOrFail($deal->calculation_id)), CarbonImmutable::parse('2026-06-01'), 'wire', null, test()->owner);
}

function fakeDwolla(): FakeRail
{
    $fake = new FakeRail('dwolla');
    app(Rails::class)->use($fake);
    test()->platform->setEnabled('dwolla', true, test()->owner);
    app(RailSetup::class)->choose('dwolla', true, test()->owner);

    return $fake;
}

function sentRun(): string
{
    $runs = app(PayoutRuns::class);
    $run = $runs->build(CarbonImmutable::today(), true, test()->owner);
    $runs->approve($run, test()->second);
    $runs->send($run, test()->owner);

    return $run;
}

it('lets brokerages choose only the rails HQ has switched on', function (): void {
    expect(fn () => app(RailSetup::class)->choose('dwolla', false, $this->owner))->toThrow(ValidationException::class, 'switched this payout option on');
    $this->platform->setEnabled('dwolla', true, $this->owner);
    $this->platform->saveKeys('dwolla', 'test', ['key' => 'k', 'secret' => 's3cret', 'webhook_secret' => 'wh'], $this->owner);
    expect(DB::table('pay_rail_platform')->value('test_keys'))->not->toContain('s3cret')
        ->and($this->platform->summary()['dwolla'])->toMatchArray(['enabled' => true, 'keys_set' => true]);

    $this->actingAs($this->ann)->withSession(['auth.password_confirmed_at' => time()])->post('/finance/payout-rail', ['rail' => 'dwolla'])->assertSessionHasErrors('rail');
    app(RailSetup::class)->choose('dwolla', false, $this->owner);
    expect(app(Rails::class)->settings())->toMatchArray(['rail' => 'dwolla', 'status' => 'pending']);
});

it('sends direct deposits through the brokerage\'s rail, registering each payee once, with checks still printed', function (): void {
    $fake = fakeDwolla();
    app(BankAccounts::class)->set($this->ann, 'Ann A', '021000021', '111122223333', 'checking', $this->ann);
    $this->travel(73)->hours();
    railDeal($this->ann);
    railDeal($this->ben);

    $run = sentRun();
    $lines = DB::table('pay_run_lines')->orderBy('id')->get();
    expect($fake->payees)->toBe([['name' => 'Ann A', 'last4' => '3333']])
        ->and(array_values($fake->payments)[0])->toMatchArray(['cents' => 1_200_000, 'same_day' => true])
        ->and($lines[0]->rail_status)->toBe('submitted')->and($lines[1]->method)->toBe('check')
        ->and(DB::table('pay_rail_refs')->value('tenant_id'))->toBe($this->tenant->id)
        ->and(Storage::disk('local')->exists("payouts/{$run}/ach.enc"))->toBeFalse();
});

it('retries payments a partner did not accept, without paying twice', function (): void {
    $fake = fakeDwolla();
    app(BankAccounts::class)->set($this->ann, 'Ann A', '021000021', '111122223333', 'checking', $this->ann);
    $this->travel(73)->hours();
    railDeal($this->ann);
    $fake->failNext = true;

    $run = sentRun();
    expect(DB::table('pay_run_lines')->value('rail_status'))->toBe('failed');
    $this->actingAs($this->owner)->post("/finance/payouts/{$run}/retry")->assertSessionHasNoErrors();
    app(PayoutRuns::class)->submit($run);
    expect(DB::table('pay_run_lines')->value('rail_status'))->toBe('submitted')->and($fake->payments)->toHaveCount(1);
});

it('marks payments completed from webhooks and puts returned money back in the agent\'s balance, once', function (): void {
    Notification::fake();
    fakeDwolla();
    app(BankAccounts::class)->set($this->ann, 'Ann A', '021000021', '111122223333', 'checking', $this->ann);
    $this->travel(73)->hours();
    railDeal($this->ann);
    sentRun();
    $ref = (string) DB::table('pay_run_lines')->value('rail_ref');
    $hook = fn (string $status, string $sig = 'fake') => $this->call('POST', 'http://app.rightally.io/payout-rails/dwolla/webhook', [], [], [], ['HTTP_X_FAKE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
        json_encode([['ref' => $ref, 'status' => $status, 'reason' => 'R03 no account']]));

    $hook('returned', 'wrong')->assertStatus(400);
    $hook('returned')->assertJson(['applied' => 1]);
    $hook('returned');
    $payable = app(LedgerService::class)->balance(app(ChartOfAccounts::class)->subAccount('2000', 'user', $this->ann->id, 'x'))->cents;
    expect($payable)->toBe(1_200_000)->and(DB::table('pay_run_lines')->value('rail_status'))->toBe('returned')
        ->and(DB::table('ledger_journals')->where('posting_key', 'like', 'payout-return:%')->count())->toBe(1);
    Notification::assertSentToTimes($this->ann, PaymentReturned::class, 1);
});

it('sends partners exactly what they expect and checks their signatures', function (): void {
    $this->platform->saveKeys('dwolla', 'test', ['key' => 'k', 'secret' => 's', 'webhook_secret' => 'dwh'], $this->owner);
    Http::fake([
        'api-sandbox.dwolla.com/token' => Http::response(['access_token' => 'tok']),
        'api-sandbox.dwolla.com/customers' => Http::response([], 201, ['Location' => 'https://api-sandbox.dwolla.com/customers/c1']),
        'api-sandbox.dwolla.com/customers/c1/funding-sources' => Http::response([], 201, ['Location' => 'https://api-sandbox.dwolla.com/funding-sources/fs1']),
        'api-sandbox.dwolla.com/transfers' => Http::response([], 201, ['Location' => 'https://api-sandbox.dwolla.com/transfers/t1']),
        'app.moderntreasury.com/api/counterparties' => Http::response(['accounts' => [['id' => 'ea_1']]]),
        'app.moderntreasury.com/api/payment_orders' => Http::response(['id' => 'po_1']),
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
        'api.stripe.com/v1/payment_methods' => Http::response(['id' => 'pm_1']),
        'api.stripe.com/v1/payment_methods/pm_1/attach' => Http::response(['id' => 'pm_1']),
        'api.stripe.com/v1/treasury/outbound_payments' => Http::response(['id' => 'obp_1']),
    ]);
    $bank = ['routing' => '021000021', 'account' => '111122223333', 'type' => 'checking', 'holder' => 'Ann A'];

    $dwolla = app(DwollaRail::class);
    expect($dwolla->payee('Ann A', 'ann@x.test', $bank, []))->toBe('https://api-sandbox.dwolla.com/funding-sources/fs1')
        ->and($dwolla->pay('https://api-sandbox.dwolla.com/funding-sources/fs1', 1_200_000, true, 'key-1', 'Commission', ['funding_source' => 'https://api-sandbox.dwolla.com/funding-sources/b1']))
        ->toBe('https://api-sandbox.dwolla.com/transfers/t1');
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/transfers') && $r->hasHeader('Idempotency-Key', 'key-1')
        && $r['amount']['value'] === '12000.00' && $r['clearing']['destination'] === 'next-available');

    $mt = app(ModernTreasuryRail::class);
    $mtConfig = ['org_id' => 'org', 'api_key' => 'live_key', 'internal_account_id' => 'ia_1', 'webhook_key' => 'mwh'];
    expect($mt->pay($mt->payee('Ann A', 'ann@x.test', $bank, $mtConfig), 1_200_000, true, 'key-2', 'Commission', $mtConfig))->toBe('po_1');
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/payment_orders') && $r['priority'] === 'high' && $r['amount'] === 1_200_000
        && $r['receiving_account_id'] === 'ea_1' && $r->hasHeader('Authorization', 'Basic '.base64_encode('org:live_key')));

    app(PaymentSettings::class)->saveKeys('test', 'pk_test_x', 'sk_test_y', 'whsec_z', $this->owner);
    $stripe = app(StripeTreasuryRail::class);
    $sConfig = ['account' => 'acct_broker', 'financial_account' => 'fa_1'];
    expect($stripe->pay($stripe->payee('Ann A', 'ann@x.test', $bank, $sConfig), 1_200_000, false, 'key-3', 'Commission', $sConfig))->toBe('obp_1');
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/treasury/outbound_payments') && $r->hasHeader('Stripe-Account', 'acct_broker')
        && $r['financial_account'] === 'fa_1' && $r['destination_payment_method'] === 'pm_1');

    $payload = '{"topic":"customer_transfer_completed","_links":{"resource":{"href":"https://api-sandbox.dwolla.com/transfers/t1"}}}';
    expect($dwolla->webhook($payload, ['x-request-signature-sha-256' => hash_hmac('sha256', $payload, 'dwh')]))->toBe([['ref' => 'https://api-sandbox.dwolla.com/transfers/t1', 'status' => 'completed', 'reason' => null]])
        ->and(fn () => $dwolla->webhook($payload, ['x-request-signature-sha-256' => 'bad']))->toThrow(RuntimeException::class);
    $mtPayload = '{"event":"returned","data":{"id":"po_1","return":{"reason":"R01"}}}';
    expect($mt->webhook($mtPayload, ['x-signature' => hash_hmac('sha256', $mtPayload, 'mwh')], $mtConfig)[0])->toMatchArray(['ref' => 'po_1', 'status' => 'returned', 'reason' => 'R01']);
});

it('walks a brokerage through Dwolla business verification, bank link and micro-deposits', function (): void {
    $this->platform->setEnabled('dwolla', true, $this->owner);
    $this->platform->saveKeys('dwolla', 'test', ['key' => 'k', 'secret' => 's', 'webhook_secret' => 'w'], $this->owner);
    Http::fake([
        'api-sandbox.dwolla.com/token' => Http::response(['access_token' => 'tok']),
        'api-sandbox.dwolla.com/customers' => Http::response([], 201, ['Location' => 'https://api-sandbox.dwolla.com/customers/biz']),
        'api-sandbox.dwolla.com/customers/biz/funding-sources' => Http::response([], 201, ['Location' => 'https://api-sandbox.dwolla.com/funding-sources/op']),
        'api-sandbox.dwolla.com/funding-sources/op/micro-deposits' => Http::response([], 201),
    ]);
    $setup = app(RailSetup::class);
    $setup->choose('dwolla', false, $this->owner);
    $setup->dwollaBusiness(['businessName' => 'Acme Realty LLC', 'type' => 'business'], $this->owner);
    $setup->dwollaBank('021000021', '555566667777', 'checking', $this->owner);
    expect(app(Rails::class)->settings()['status'])->toBe('pending');
    $setup->dwollaVerify('0.03', '0.09', $this->owner);

    expect(app(Rails::class)->settings())->toMatchArray(['rail' => 'dwolla', 'status' => 'ready'])
        ->and(DB::table('pay_rail_settings')->value('config_sealed'))->not->toContain('funding-sources/op');
    Http::assertNotSent(fn (Request $r): bool => str_contains((string) json_encode($r->data()), '555566667777') && ! str_contains($r->url(), 'funding-sources'));
});
