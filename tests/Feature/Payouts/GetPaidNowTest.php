<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Calculation;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\LedgerService;
use Modules\Payouts\BankAccounts;
use Modules\Payouts\GetPaidNow;
use Modules\Payouts\InstantRequested;
use Modules\Payouts\PayoutRuns;
use Modules\Payouts\Rails\FakeRail;
use Modules\Payouts\Rails\RailPlatform;
use Modules\Payouts\Rails\Rails;
use Modules\Payouts\Rails\RailSetup;
use Modules\Payouts\Receipts;
use Modules\Payouts\YouvePaid;
use Modules\Transactions\Models\Deal;

beforeEach(function (): void {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    config(['core.hq_host' => 'app.rightally.io']);
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive']);
    $this->ann = User::factory()->create(['first_name' => 'Ann']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $this->instant = app(GetPaidNow::class);
    app(BankAccounts::class)->set($this->ann, 'Ann A', '021000021', '111122223333', 'checking', $this->ann);
    $this->travel(73)->hours();
    $this->actingAs($this->ann)->post('/deals', ['type' => 'listing', 'street' => '1 Bay St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '500000']);
    $deal = Deal::query()->latest('id')->firstOrFail();
    $this->actingAs($this->ann)->post("/deals/{$deal->ulid}/status", ['status' => 'under_contract', 'price' => '500000', 'gci' => '15000', 'contract_date' => '2026-06-01', 'closing_date' => '2026-06-01']);
    $deal = $deal->fresh();
    $receipts = app(Receipts::class);
    $receipts->record($deal, $receipts->expected($deal, Calculation::query()->findOrFail($deal->calculation_id)), CarbonImmutable::parse('2026-06-01'), 'wire', null, $this->owner);
});

function bal(string $code, ?int $userId = null): int
{
    $accounts = app(ChartOfAccounts::class);

    return app(LedgerService::class)->balance($userId !== null ? $accounts->subAccount($code, 'user', $userId, 'x') : $accounts->account($code))->cents;
}

it('is off until the brokerage turns it on, with fees inside HQ\'s limits', function (): void {
    expect($this->instant->quote($this->ann)['blocked'])->toContain('not turned on')
        ->and(fn () => $this->instant->configure(true, 3_000, '1', 10_000, $this->owner))->toThrow(ValidationException::class);

    $this->instant->saveLimits(5_000, '3', $this->owner);
    $this->instant->configure(true, 500, '1', 10_000, $this->owner);
    expect($this->instant->quote($this->ann))->toMatchArray(['enabled' => true, 'available_cents' => 1_200_000, 'fee_cents' => 12_500, 'net_cents' => 1_187_500, 'blocked' => null]);
    $this->actingAs($this->ann)->get('/my-money')->assertInertia(fn (Assert $page) => $page->where('instant.net_cents', 1_187_500));
});

it('pays at once through an automatic rail, booking the fee as the brokerage\'s income', function (): void {
    Notification::fake();
    $fake = new FakeRail('dwolla');
    app(Rails::class)->use($fake);
    app(RailPlatform::class)->setEnabled('dwolla', true, $this->owner);
    app(RailSetup::class)->choose('dwolla', false, $this->owner);
    $this->instant->configure(true, 500, '1', 10_000, $this->owner);

    expect(fn () => $this->instant->request($this->ann, 999))->toThrow(ValidationException::class, 'amount changed');
    $this->actingAs($this->ann)->withSession(['auth.password_confirmed_at' => time()])->post('/my-money/get-paid-now', ['net_cents' => 1_187_500])->assertSessionHasNoErrors();

    expect(array_values($fake->payments)[0])->toMatchArray(['cents' => 1_187_500, 'same_day' => true])
        ->and(bal('2000', $this->ann->id))->toBe(0)->and(bal('4100'))->toBe(12_500)
        ->and(DB::table('pay_runs')->where('kind', 'instant')->value('status'))->toBe('sent')
        ->and($this->instant->quote($this->ann)['blocked'])->toContain('starts at');
    Notification::assertSentTo($this->ann, YouvePaid::class, fn (YouvePaid $n): bool => $n->cents === 1_187_500);

    // A bounce gives back the full amount and refunds the fee.
    app(PayoutRuns::class)->reverseLine((int) DB::table('pay_run_lines')->value('id'), 'returned', 'R03');
    expect(bal('2000', $this->ann->id))->toBe(1_200_000)->and(bal('4100'))->toBe(0);
});

it('queues it for the next same-day bank file when the brokerage uses bank files, without blocking or doubling regular runs', function (): void {
    Notification::fake();
    $this->instant->configure(true, 0, '0', 10_000, $this->owner);
    $run = $this->instant->request($this->ann, 1_200_000);

    expect(DB::table('pay_runs')->where('ulid', $run)->value('status'))->toBe('approved')->and(bal('2000', $this->ann->id))->toBe(0);
    Notification::assertSentTo($this->owner, InstantRequested::class);
    $regular = app(PayoutRuns::class)->build(CarbonImmutable::today(), false, $this->owner);
    expect(DB::table('pay_run_lines')->where('run_id', DB::table('pay_runs')->where('ulid', $regular)->value('id'))->count())->toBe(0);

    app(PayoutRuns::class)->saveSettings('ACME REALTY', '1123456789', '021000021', 'First National', null, null, $this->owner);
    $out = app(PayoutRuns::class)->send($run, $this->owner);
    expect(explode("\n", (string) $out['nacha'])[2])->toContain('0001200000')
        ->and(DB::table('ledger_journals')->where('posting_key', 'payout-run:'.$run)->count())->toBe(1);
});

it('respects the bank-change hold and the minimum', function (): void {
    $this->instant->configure(true, 0, '0', 2_000_000, $this->owner);
    expect($this->instant->quote($this->ann)['blocked'])->toContain('starts at $20,000.00');

    $this->instant->configure(true, 0, '0', 10_000, $this->owner);
    app(BankAccounts::class)->set($this->ann, 'Ann A', '021000021', '999988887777', 'checking', $this->ann);
    expect($this->instant->quote($this->ann)['blocked'])->toContain('changed recently')
        ->and(fn () => $this->instant->request($this->ann, 1_200_000))->toThrow(ValidationException::class);
});
