<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Calculation;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\LedgerService;
use Modules\Payouts\BankAccounts;
use Modules\Payouts\PayoutRuns;
use Modules\Payouts\Receipts;
use Modules\Payouts\Words;
use Modules\Payouts\YouvePaid;
use Modules\Transactions\Cdas;
use Modules\Transactions\Models\Deal;

beforeEach(function (): void {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive']);
    $this->second = User::factory()->owner()->create(['first_name' => 'Second']);
    [$this->ann, $this->ben, $this->cat] = [User::factory()->create(['first_name' => 'Ann']), User::factory()->create(['first_name' => 'Ben']), User::factory()->create(['first_name' => 'Cat'])];
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $this->runs = app(PayoutRuns::class);
});

function settledDeal(User $agent, ?string $brokeragePaidReferral = null): Deal
{
    test()->actingAs($agent)->post('/deals', ['type' => 'listing', 'street' => uniqid().' Main St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '500000']);
    $deal = Deal::query()->latest('id')->firstOrFail();
    if ($brokeragePaidReferral !== null) {
        test()->actingAs($agent)->post("/deals/{$deal->ulid}/referrals", ['direction' => 'out', 'brokerage' => 'Coastal', 'agent' => 'Kim', 'percent' => $brokeragePaidReferral, 'paid_by' => 'brokerage']);
    }
    test()->actingAs($agent)->post("/deals/{$deal->ulid}/status", ['status' => 'under_contract', 'price' => '500000', 'gci' => '15000', 'contract_date' => '2026-06-01', 'closing_date' => '2026-06-01'])
        ->assertSessionHasNoErrors();
    $deal = $deal->fresh() ?? $deal;
    $receipts = app(Receipts::class);
    $calc = Calculation::query()->findOrFail($deal->calculation_id);
    $receipts->record($deal, $receipts->expected($deal, $calc), CarbonImmutable::parse('2026-06-01'), 'wire', null, test()->owner);

    return $deal;
}

function lines(string $run): array
{
    return DB::table('pay_run_lines')->where('run_id', DB::table('pay_runs')->where('ulid', $run)->value('id'))->orderBy('id')
        ->get(['name', 'method', 'status', 'amount_cents', 'skip_reason'])->map(fn ($l) => (array) $l)->all();
}

function payoutSettings(): void
{
    app(PayoutRuns::class)->saveSettings('ACME REALTY', '1123456789', '021000021', 'First National', '9876543210', 5001, test()->owner);
}

it('builds a run: direct deposit, checks for agents without bank details, and skips anyone in the bank-change hold', function (): void {
    $banks = app(BankAccounts::class);
    $banks->set($this->ann, 'Ann A', '021000021', '111122223333', 'checking', $this->ann);
    $this->travel(73)->hours();
    $banks->set($this->cat, 'Cat C', '011000015', '444455556666', 'savings', $this->cat);
    foreach ([$this->ann, $this->ben, $this->cat] as $agent) {
        settledDeal($agent);
    }

    $run = $this->runs->build(CarbonImmutable::today(), false, $this->owner);
    $rows = collect(lines($run))->keyBy(fn ($l) => explode(' ', $l['name'])[0]);
    expect($rows['Ann']['method'])->toBe('ach')->and($rows['Ann']['amount_cents'])->toBe(1_200_000)
        ->and($rows['Ben']['method'])->toBe('check')
        ->and($rows['Cat']['status'])->toBe('skipped')->and($rows['Cat']['skip_reason'])->toContain('Bank account changed recently')
        ->and((int) DB::table('pay_runs')->value('total_cents'))->toBe(2_400_000)
        ->and(fn () => $this->runs->build(CarbonImmutable::today(), false, $this->owner))->toThrow(ValidationException::class, 'open payout run');
});

it('needs an approver who is not paid in the run and has not just changed a payee\'s bank details', function (): void {
    app(BankAccounts::class)->set($this->ann, 'Ann A', '021000021', '111122223333', 'checking', $this->owner);
    $this->travel(73)->hours();
    settledDeal($this->ann);
    $run = $this->runs->build(CarbonImmutable::today(), false, $this->owner);

    expect(fn () => $this->runs->approve($run, $this->ann))->toThrow(ValidationException::class)
        ->and(fn () => $this->runs->approve($run, $this->owner))->toThrow(ValidationException::class, 'changed a payee');
    $this->runs->approve($run, $this->second);
    expect(DB::table('pay_runs')->value('status'))->toBe('approved');
});

it('sends a valid bank file and checks, posts the payments, and tells agents they have been paid', function (): void {
    Notification::fake();
    app(BankAccounts::class)->set($this->ann, 'Ann A', '021000021', '111122223333', 'checking', $this->ann);
    $this->travel(73)->hours();
    settledDeal($this->ann);
    settledDeal($this->ben);
    $run = $this->runs->build(CarbonImmutable::today(), true, $this->owner);
    $this->runs->approve($run, $this->owner);

    expect(fn () => $this->runs->send($run, $this->owner))->toThrow(ValidationException::class, 'ACH company ID');
    payoutSettings();
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->post("/finance/payouts/{$run}/send")->assertSessionHasNoErrors();

    $nacha = (string) $this->actingAs($this->owner)->get("/finance/payouts/{$run}/files/ach")->assertOk()->getContent();
    $records = explode("\n", trim($nacha));
    expect(count($records) % 10)->toBe(0)
        ->and(collect($records)->every(fn ($r) => strlen($r) === 94))->toBeTrue()
        ->and($records[2])->toStartWith('622021000021111122223333     0001200000')
        ->and(substr($records[3], 10, 10))->toBe('0002100002') // entry hash: first 8 digits of 021000021
        ->and(Storage::disk('local')->get("payouts/{$run}/ach.enc"))->not->toContain('111122223333');

    $positive = (string) $this->actingAs($this->owner)->get("/finance/payouts/{$run}/files/positive-pay")->assertOk()->getContent();
    expect($positive)->toContain('9876543210,5001,')->toContain('12000.00');
    $this->actingAs($this->owner)->get("/finance/payouts/{$run}/files/checks")->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $accounts = app(ChartOfAccounts::class);
    $ledger = app(LedgerService::class);
    expect($ledger->balance($accounts->subAccount('2000', 'user', $this->ann->id, 'x'))->cents)->toBe(0)
        ->and($ledger->balance($accounts->account('1000'))->cents)->toBe(3_000_000 - 2_400_000)
        ->and((int) DB::table('pay_settings')->value('next_check_number'))->toBe(5002);
    Notification::assertSentTo($this->ann, YouvePaid::class);
    Notification::assertSentTo($this->ben, YouvePaid::class, fn (YouvePaid $n): bool => $n->checkNumber === 5001);

    // Nothing is paid twice: the next run has nothing for them.
    $next = $this->runs->build(CarbonImmutable::today(), false, $this->owner);
    expect(lines($next))->toBe([]);
});

it('pays referral fees the brokerage owes, and keeps them inside the brokerage\'s CDA amount', function (): void {
    Notification::fake();
    $deal = settledDeal($this->ben, '20');
    $cda = app(Cdas::class)->generate($deal, $this->owner);
    $payload = app(Cdas::class)->forDeal($deal)[0]['payload'];
    expect(count($payload['payees']))->toBe(1)->and($payload['payees'][0]['amount_cents'])->toBe(1_500_000); // referral stays in the brokerage line

    $run = $this->runs->build(CarbonImmutable::today(), false, $this->owner);
    expect(collect(lines($run))->firstWhere('name', 'Coastal (Kim)')['amount_cents'])->toBe(300_000);
    $this->runs->approve($run, $this->owner);
    payoutSettings();
    $this->runs->send($run, $this->owner);
    expect(DB::table('tx_referrals')->whereNotNull('paid_at')->count())->toBe(1);
});

it('writes check amounts in words', function (): void {
    expect(Words::dollars(123_450))->toBe('One thousand two hundred thirty-four and 50/100')
        ->and(Words::dollars(1_200_000))->toBe('Twelve thousand and 00/100')
        ->and(Words::dollars(99))->toBe('Zero and 99/100');
});
