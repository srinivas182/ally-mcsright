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
use Modules\Core\Audit\FlushAuditOutbox;
use Modules\Payouts\AutoPayouts;
use Modules\Payouts\AutoPayoutWaiting;
use Modules\Payouts\BankAccounts;
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
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->staff = User::factory()->staff()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    app(BankAccounts::class)->set($this->ann, 'Ann A', '021000021', '111122223333', 'checking', $this->ann);
    $this->travel(73)->hours();
    $this->auto = app(AutoPayouts::class);
});

function autoDeal(): Deal
{
    test()->actingAs(test()->ann)->post('/deals', ['type' => 'listing', 'street' => uniqid().' Birch', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '500000']);
    $deal = Deal::query()->latest('id')->firstOrFail();
    test()->actingAs(test()->ann)->post("/deals/{$deal->ulid}/status", ['status' => 'under_contract', 'price' => '500000', 'gci' => '15000', 'contract_date' => '2026-06-01', 'closing_date' => '2026-06-04']);

    return $deal->fresh() ?? $deal;
}

function settle(Deal $deal, ?int $cents = null): string
{
    $receipts = app(Receipts::class);

    return $receipts->record($deal, $cents ?? $receipts->expected($deal, Calculation::query()->findOrFail($deal->calculation_id)), CarbonImmutable::today(), 'wire', null, test()->owner);
}

function automaticRail(): FakeRail
{
    $fake = new FakeRail('dwolla');
    app(Rails::class)->use($fake);
    app(RailPlatform::class)->setEnabled('dwolla', true, test()->owner);
    app(RailSetup::class)->choose('dwolla', false, test()->owner);

    return $fake;
}

it('renders the payouts page with rails, get paid now and automatic payout settings', function (): void {
    $this->actingAs($this->owner)->get('/finance/payouts')->assertInertia(fn (Assert $page) => $page->component('finance/payouts')
        ->where('rail.rail', 'bank_file')->where('instant.enabled', false)->where('auto.enabled', false)->has('settings')->has('runs'));
});

it('does nothing until the owner turns it on, and only an owner can', function (): void {
    settle(autoDeal());
    expect(DB::table('pay_runs')->count())->toBe(0)
        ->and(fn () => $this->auto->configure(true, 1_500_000, false, $this->staff))->toThrow(ValidationException::class);
});

it('pays agents straight away through an automatic rail when the money matches and the amount is within the limit', function (): void {
    Notification::fake();
    $fake = automaticRail();
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->post('/finance/auto-payouts', ['enabled' => true, 'limit' => '15000', 'same_day' => true])
        ->assertSessionHasNoErrors();

    settle(autoDeal());
    $run = DB::table('pay_runs')->first();
    expect($run)->toMatchObject(['kind' => 'auto', 'status' => 'sent', 'approved_by' => null, 'same_day' => 1])
        ->and(array_values($fake->payments)[0]['cents'])->toBe(1_200_000);
    Notification::assertSentTo($this->ann, YouvePaid::class);
    (new FlushAuditOutbox)->handle();
    expect(DB::table('audit_events')->where('action', 'payouts.run.auto_approved')->exists())->toBeTrue();
});

it('leaves payouts over the limit for approval, and tells the owners', function (): void {
    Notification::fake();
    automaticRail();
    $this->auto->configure(true, 1_000_000, false, $this->owner);
    settle(autoDeal());

    expect(DB::table('pay_runs')->value('status'))->toBe('draft');
    Notification::assertSentTo($this->owner, AutoPayoutWaiting::class, fn (AutoPayoutWaiting $n): bool => ! $n->readyToSend && $n->cents === 1_200_000);
});

it('on the bank file, approves and asks the owners to send it, and a regular run never includes the same agent', function (): void {
    Notification::fake();
    $this->auto->configure(true, 1_500_000, false, $this->owner);
    settle(autoDeal());

    expect(DB::table('pay_runs')->value('status'))->toBe('approved');
    Notification::assertSentTo($this->owner, AutoPayoutWaiting::class, fn (AutoPayoutWaiting $n): bool => $n->readyToSend);
    $regular = app(PayoutRuns::class)->build(CarbonImmutable::today(), false, $this->owner);
    expect(DB::table('pay_run_lines')->where('run_id', DB::table('pay_runs')->where('ulid', $regular)->value('id'))->count())->toBe(0);
});

it('also pays out when a broker accepts a short payment as final', function (): void {
    automaticRail();
    $this->auto->configure(true, 1_500_000, false, $this->owner);
    $short = settle(autoDeal(), 1_497_500);
    expect(DB::table('pay_runs')->count())->toBe(0);

    app(Receipts::class)->accept($short, 'Title deducted a $25 wire fee', $this->owner);
    expect(DB::table('pay_runs')->value('status'))->toBe('sent');
});
