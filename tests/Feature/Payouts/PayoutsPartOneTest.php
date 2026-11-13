<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\LedgerService;
use Modules\Payouts\Balances;
use Modules\Payouts\BankAccounts;
use Modules\Payouts\BankDetailsChanged;
use Modules\Payouts\Receipts;
use Modules\Transactions\Cdas;
use Modules\Transactions\Models\Deal;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
});

function payDeal(?string $referralPercent = null): Deal
{
    test()->actingAs(test()->agent)->post('/deals', ['type' => 'listing', 'street' => '5 Pine St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '500000']);
    $deal = Deal::query()->latest('id')->firstOrFail();
    if ($referralPercent !== null) {
        test()->actingAs(test()->agent)->post("/deals/{$deal->ulid}/referrals", ['direction' => 'out', 'brokerage' => 'Coastal', 'agent' => 'Kim', 'percent' => $referralPercent]);
    }
    test()->actingAs(test()->agent)->post("/deals/{$deal->ulid}/status", ['status' => 'under_contract', 'price' => '500000', 'gci' => '15000', 'contract_date' => '2026-06-01', 'closing_date' => '2026-06-21'])
        ->assertSessionHasNoErrors();

    return $deal->fresh() ?? $deal;
}

function balanceOf(string $code, ?int $userId = null, ?int $dealId = null): int
{
    $accounts = app(ChartOfAccounts::class);
    $account = match (true) {
        $userId !== null => $accounts->subAccount($code, 'user', $userId, 'x'),
        $dealId !== null => $accounts->subAccount($code, 'deal', $dealId, 'x'),
        default => $accounts->account($code),
    };

    return app(LedgerService::class)->balance($account)->cents;
}

it('recognises the commission and brings the cash in when title pays what the CDA says', function (): void {
    $deal = payDeal();
    $this->travelTo(CarbonImmutable::parse('2026-06-21 15:00'));
    $this->actingAs($this->owner)->post("/finance/receipts/{$deal->ulid}", ['amount' => '15000', 'received_on' => '2026-06-21', 'method' => 'wire', 'reference' => 'FED-1'])->assertSessionHasNoErrors();

    expect(DB::table('pay_receipts')->value('status'))->toBe('matched')
        ->and(balanceOf('1000'))->toBe(1_500_000)
        ->and(balanceOf('2000', $this->agent->id))->toBe(1_200_000)
        ->and(balanceOf('4000'))->toBe(300_000)
        ->and(balanceOf('1100', null, $deal->id))->toBe(0)
        ->and(app(Balances::class)->for($this->agent))->toMatchArray(['commission_cents' => 1_200_000, 'available_cents' => 1_200_000, 'pending_cents' => 0])
        ->and((int) DB::table('ledger_lines')->sum('debit_cents'))->toBe((int) DB::table('ledger_lines')->sum('credit_cents'));
});

it('holds the agent\'s commission until the full amount arrives', function (): void {
    $deal = payDeal();
    app(Receipts::class)->record($deal, 1_000_000, CarbonImmutable::parse('2026-06-21'), 'wire', null, $this->owner);
    expect(DB::table('pay_receipts')->value('status'))->toBe('short')
        ->and(app(Balances::class)->for($this->agent))->toMatchArray(['commission_cents' => 0, 'pending_cents' => 1_200_000, 'pending_deals' => 1]);

    app(Receipts::class)->record($deal, 500_000, CarbonImmutable::parse('2026-06-22'), 'check', '1042', $this->owner);
    expect(DB::table('pay_receipts')->orderByDesc('id')->value('status'))->toBe('matched')
        ->and(balanceOf('2000', $this->agent->id))->toBe(1_200_000);
});

it('lets the brokerage accept a short payment, absorbing the difference, and holds an overpayment', function (): void {
    $deal = payDeal();
    $receipts = app(Receipts::class);
    $short = $receipts->record($deal, 1_497_500, CarbonImmutable::parse('2026-06-21'), 'wire', null, $this->owner);
    expect(fn () => $receipts->accept($short, 'ok', $this->owner))->toThrow(ValidationException::class);
    $this->actingAs($this->owner)->post("/finance/receipts/{$short}/accept", ['note' => 'Title deducted a $25 wire fee; owner agreed'])->assertSessionHasNoErrors();

    expect(balanceOf('2000', $this->agent->id))->toBe(1_200_000)->and(balanceOf('4000'))->toBe(297_500)->and(balanceOf('1100', null, $deal->id))->toBe(0)
        ->and(DB::table('pay_receipts')->value('status'))->toBe('accepted');

    $over = payDeal();
    $receipts->record($over, 1_510_000, CarbonImmutable::parse('2026-06-21'), 'wire', null, $this->owner);
    expect(DB::table('pay_receipts')->where('deal_id', $over->id)->value('status'))->toBe('over')->and($receipts->settled($over->id))->toBeFalse();
});

it('expects what the CDA says the brokerage receives, and keeps recording to finance', function (): void {
    $deal = payDeal('10');
    $cdas = app(Cdas::class);
    $cdas->approve($cdas->generate($deal, $this->owner), $this->owner);

    $this->travelTo(CarbonImmutable::parse('2026-06-21 15:00'));
    $this->actingAs($this->agent)->post("/finance/receipts/{$deal->ulid}", ['amount' => '13500', 'received_on' => '2026-06-21', 'method' => 'wire'])->assertForbidden();
    $this->actingAs($this->owner)->post("/finance/receipts/{$deal->ulid}", ['amount' => '13500', 'received_on' => '2026-06-21', 'method' => 'wire'])->assertSessionHasNoErrors();
    expect(DB::table('pay_receipts')->value('status'))->toBe('matched')->and((int) DB::table('pay_receipts')->value('expected_cents'))->toBe(1_350_000);

    $this->actingAs($this->owner)->get('/finance/receipts?show=all')->assertInertia(fn (Assert $page) => $page->component('finance/receipts')->where('deals.0.settled', true));
});

it('protects agents\' bank details: validated, encrypted, emailed, and held for 72 hours', function (): void {
    Notification::fake();
    $banks = app(BankAccounts::class);
    expect(fn () => $banks->set($this->agent, 'Pat Agent', '123456789', '000123456789', 'checking', $this->agent))->toThrow(ValidationException::class, 'routing');

    $banks->set($this->agent, 'Pat Agent', '021000021', '000123456789', 'checking', $this->agent);
    expect(DB::table('pay_bank_accounts')->value('account_sealed'))->not->toContain('000123456789')
        ->and($banks->current($this->agent))->toMatchArray(['last4' => '6789', 'usable' => false])
        ->and($banks->forPayout($this->agent->id))->toBeNull();
    Notification::assertSentTo($this->agent, BankDetailsChanged::class);

    $this->travel(73)->hours();
    expect($banks->forPayout($this->agent->id))->toMatchArray(['routing' => '021000021', 'account' => '000123456789']);

    $staff = User::factory()->staff()->create();
    expect(fn () => $banks->set($this->agent, 'Pat', '021000021', '999988887777', 'checking', $staff))->toThrow(ValidationException::class);
    $banks->set($this->agent, 'Pat Agent', '011000015', '555500001111', 'savings', $this->owner);
    expect(DB::table('pay_bank_accounts')->where('status', 'replaced')->count())->toBe(1)->and($banks->forPayout($this->agent->id))->toBeNull();

    $this->actingAs($this->agent)->post('/my-money/bank', ['holder' => 'Pat', 'routing' => '021000021', 'account' => '1234', 'type' => 'checking'])->assertRedirectContains('confirm-password');
});

it('shows agents what is ready, what is on its way and where it is paid', function (): void {
    payDeal();
    $this->actingAs($this->agent)->get('/my-money')->assertInertia(fn (Assert $page) => $page
        ->component('finance/my-money')->where('balances.pending_cents', 1_200_000)->where('bank', null)->where('holdHours', 72));
});
