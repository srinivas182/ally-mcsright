<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Gateway\FakeGateway;
use Modules\Billing\Gateway\PaymentGateway;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Calculation;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\JournalDraft;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Money\Money;
use Modules\Payouts\AgentBilling;
use Modules\Payouts\BankAccounts;
use Modules\Payouts\GetPaidNow;
use Modules\Payouts\InvoiceReminder;
use Modules\Payouts\PayoutRuns;
use Modules\Payouts\Receipts;
use Modules\Transactions\Models\Deal;

beforeEach(function (): void {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    config(['core.hq_host' => 'app.rightally.io']);
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive']);
    $this->second = User::factory()->owner()->create(['first_name' => 'Second']);
    $this->ann = User::factory()->create(['first_name' => 'Ann']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $this->billing = app(AgentBilling::class);
});

function earn(User $agent): void
{
    test()->actingAs($agent)->post('/deals', ['type' => 'listing', 'street' => uniqid().' Elm', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '500000']);
    $deal = Deal::query()->latest('id')->firstOrFail();
    test()->actingAs($agent)->post("/deals/{$deal->ulid}/status", ['status' => 'under_contract', 'price' => '500000', 'gci' => '15000', 'contract_date' => '2026-06-01', 'closing_date' => '2026-06-01']);
    $deal = $deal->fresh();
    $receipts = app(Receipts::class);
    $receipts->record($deal, $receipts->expected($deal, Calculation::query()->findOrFail($deal->calculation_id)), CarbonImmutable::today(), 'wire', null, test()->owner);
}

function ledgerBal(string $code, ?int $userId = null): int
{
    $a = app(ChartOfAccounts::class);
    $a->seed();

    return app(LedgerService::class)->balance($userId !== null ? $a->subAccount($code, 'user', $userId, 'x') : $a->account($code))->cents;
}

it('invoices recurring fees on schedule as receivable against fee revenue', function (): void {
    expect(fn () => $this->billing->saveItem('Tech fee', 5_000, 'monthly', $this->ann))->toThrow(ValidationException::class);
    $tech = $this->billing->saveItem('Tech fee', 5_000, 'monthly', $this->owner);
    $setup = $this->billing->saveItem('Onboarding', 20_000, 'once', $this->owner);
    $this->billing->assign($this->ann, $tech, CarbonImmutable::parse('2026-06-01'), $this->owner);
    $this->billing->assign($this->ann, $setup, CarbonImmutable::parse('2026-06-01'), $this->owner);

    $this->artisan('payouts:agent-billing')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00'));
    $this->billing->daily();

    expect(DB::table('bill_invoices')->count())->toBe(3)
        ->and($this->billing->owed($this->ann->id))->toBe(30_000)
        ->and(ledgerBal('1200', $this->ann->id))->toBe(30_000)->and(ledgerBal('4100'))->toBe(30_000)
        ->and(DB::table('bill_assignments')->whereNotNull('ended_on')->count())->toBe(1);
});

it('takes unpaid invoices from commission in a payout run and sends the rest', function (): void {
    app(BankAccounts::class)->set($this->ann, 'Ann A', '021000021', '111122223333', 'checking', $this->ann);
    $this->travel(73)->hours();
    earn($this->ann);
    $this->billing->charge($this->ann, 'Sign rider', 5_000, $this->owner);

    $runs = app(PayoutRuns::class);
    $run = $runs->build(CarbonImmutable::today(), false, $this->owner);
    $runs->approve($run, $this->second);
    $runs->saveSettings('ACME REALTY', '1123456789', '021000021', 'First National', null, null, $this->owner);
    $out = $runs->send($run, $this->owner);

    expect((int) DB::table('pay_run_lines')->value('deduction_cents'))->toBe(5_000)
        ->and(explode("\n", (string) $out['nacha'])[2])->toContain('0001195000')
        ->and($this->billing->owed($this->ann->id))->toBe(0)->and(DB::table('bill_invoices')->value('status'))->toBe('paid')
        ->and(ledgerBal('1200', $this->ann->id))->toBe(0)->and(ledgerBal('2000', $this->ann->id))->toBe(0)
        ->and((int) DB::table('ledger_lines')->sum('debit_cents'))->toBe((int) DB::table('ledger_lines')->sum('credit_cents'));
});

it('pays nothing out when invoices use up the whole amount, and deducts nothing when switched off', function (): void {
    earn($this->ann);
    $this->billing->charge($this->ann, 'Annual E&O', 2_000_000, $this->owner);
    $run = app(PayoutRuns::class)->build(CarbonImmutable::today(), false, $this->owner);
    expect(DB::table('pay_run_lines')->first())->toMatchObject(['method' => 'none', 'deduction_cents' => 1_200_000]);
    app(PayoutRuns::class)->cancel($run, $this->owner);

    $this->billing->saveSettings(false, 0, 5, 15, $this->owner);
    app(PayoutRuns::class)->build(CarbonImmutable::today(), false, $this->owner);
    expect((int) DB::table('pay_run_lines')->orderByDesc('id')->value('deduction_cents'))->toBe(0);
});

it('lets agents pay an invoice online to the brokerage\'s own Stripe account, once', function (): void {
    $gateway = new FakeGateway;
    app()->instance(PaymentGateway::class, $gateway);
    $this->tenant->forceFill(['connect_account' => 'acct_broker', 'connect_ready' => true])->save();
    app(TenantContext::class)->set($this->tenant->fresh());
    $invoice = $this->billing->charge($this->ann, 'Sign rider', 5_000, $this->owner);

    $this->actingAs($this->ann)->post("/my-money/invoices/{$invoice}/pay", [], ['X-Inertia' => 'true'])->assertHeader('X-Inertia-Location', 'https://checkout.example/pay/1');
    expect($gateway->payments[0])->toMatchArray(['connectedAccount' => 'acct_broker', 'cents' => 5_000])
        ->and($gateway->payments[0]['metadata'])->toMatchArray(['kind' => 'agent_invoice', 'invoice' => $invoice]);

    $event = ['id' => 'evt_inv', 'type' => 'checkout.session.completed', 'data' => ['object' => ['amount_total' => 5_000, 'metadata' => $gateway->payments[0]['metadata']]]];
    foreach ([1, 2] as $_) {
        $this->call('POST', 'http://app.rightally.io/billing/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'fake-signature', 'CONTENT_TYPE' => 'application/json'], json_encode($event));
    }
    expect(DB::table('bill_invoices')->value('status'))->toBe('paid')->and(ledgerBal('1300'))->toBe(5_000)->and(ledgerBal('1200', $this->ann->id))->toBe(0);
});

it('reminds agents before and after the due date and adds a late fee once', function (): void {
    Notification::fake();
    $this->billing->saveSettings(true, 2_500, 5, 15, $this->owner);
    $this->billing->charge($this->ann, 'Desk fee', 10_000, $this->owner); // due 16 June

    $this->travelTo(CarbonImmutable::parse('2026-06-14 09:00'));
    $this->billing->daily();
    $this->travelTo(CarbonImmutable::parse('2026-06-17 09:00'));
    $this->billing->daily();
    $this->travelTo(CarbonImmutable::parse('2026-06-23 09:00'));
    $this->billing->daily();
    $this->billing->daily();

    Notification::assertSentToTimes($this->ann, InvoiceReminder::class, 2);
    expect((int) DB::table('bill_invoices')->value('total_cents'))->toBe(12_500)->and(ledgerBal('4100'))->toBe(12_500);
});

it('moves what an agent owes into a payment plan that future commission pays off', function (): void {
    $a = app(ChartOfAccounts::class);
    $a->seed();
    app(LedgerService::class)->post(JournalDraft::make('test', 'owes')->describe('Commission reversed after payout')
        ->debit($a->subAccount('2000', 'user', $this->ann->id, 'x'), Money::of(60_000))->credit($a->account('1000'), Money::of(60_000)));

    expect(fn () => $this->billing->plan($this->ann, 70_000, 3, 'Reversed deal on 12 Oak St', $this->owner))->toThrow(ValidationException::class);
    $this->billing->plan($this->ann, 60_000, 3, 'Reversed deal on 12 Oak St', $this->owner);

    expect(DB::table('bill_invoices')->pluck('total_cents')->all())->toBe([20_000, 20_000, 20_000])
        ->and(ledgerBal('2000', $this->ann->id))->toBe(0)->and(ledgerBal('1200', $this->ann->id))->toBe(60_000)->and(ledgerBal('4100'))->toBe(0);

    earn($this->ann);
    app(PayoutRuns::class)->build(CarbonImmutable::today(), false, $this->owner);
    expect((int) DB::table('pay_run_lines')->value('deduction_cents'))->toBe(60_000);
});

it('takes unpaid invoices off a get-paid-now request first', function (): void {
    app(BankAccounts::class)->set($this->ann, 'Ann A', '021000021', '111122223333', 'checking', $this->ann);
    $this->travel(73)->hours();
    earn($this->ann);
    $this->billing->charge($this->ann, 'Tech fee', 5_000, $this->owner);
    app(GetPaidNow::class)->configure(true, 0, '1', 10_000, $this->owner);

    expect(app(GetPaidNow::class)->quote($this->ann))->toMatchArray(['available_cents' => 1_200_000, 'deduction_cents' => 5_000, 'fee_cents' => 11_950, 'net_cents' => 1_183_050]);
});
