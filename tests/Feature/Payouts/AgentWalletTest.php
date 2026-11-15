<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Calculation;
use Modules\Payouts\Receipts;
use Modules\Payouts\Statements;
use Modules\Payouts\Wallet;
use Modules\Transactions\Models\Deal;

/* Sprint AW1: the Agent Wallet — the year's earnings, cap progress, deals on their way, revenue share and what is
   owed, on one screen, every figure from the ledger and tied to the monthly statements. */

beforeEach(function (): void {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-06-10 15:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive']);
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
});

function walletDeal(object $t, string $street, string $gci, string $closing): Deal
{
    $t->actingAs($t->ann)->post('/deals', ['type' => 'listing', 'street' => $street, 'city' => 'Austin', 'state_code' => 'TX', 'price' => '500000']);
    $deal = Deal::query()->latest('id')->firstOrFail();
    $t->actingAs($t->ann)->post("/deals/{$deal->ulid}/status", ['status' => 'under_contract', 'price' => '500000', 'gci' => $gci, 'contract_date' => '2026-06-01', 'closing_date' => $closing]);

    return $deal->fresh() ?? $deal;
}

it('shows the year so far, tied to the monthly statements, and what is on its way', function (): void {
    $paid = walletDeal($this, '7 Lake Dr', '15000', '2026-06-05');
    $receipts = app(Receipts::class);
    $receipts->record($paid, $receipts->expected($paid, Calculation::query()->findOrFail($paid->calculation_id)), CarbonImmutable::today(), 'wire', null, $this->owner);
    walletDeal($this, '9 Hill Rd', '10000', '2026-07-15');

    $w = app(Wallet::class)->for($this->ann);
    $june = app(Statements::class)->month($this->ann, '2026-06');
    expect($w['year'])->toBe(2026)
        ->and($w['earned_ytd_cents'])->toBe($june['earned'])->and($w['earned_ytd_cents'])->toBe(1_200_000) // 80% of $15,000
        ->and($w['months'][0])->toBe(['month' => '2026-06', 'earned_cents' => 1_200_000, 'paid_cents' => 0])->and(count($w['months']))->toBe(6)
        ->and($w['now']['available_cents'])->toBe(1_200_000)
        ->and($w['pending_deals'])->toHaveCount(1)->and($w['pending_deals'][0])->toMatchArray(['street' => '9 Hill Rd, Austin', 'closing_date' => '2026-07-15', 'expected_cents' => 800_000])
        ->and($w['cap'])->toMatchArray(['plan' => '80/20', 'cap_cents' => null])
        ->and($w['revenue_share'])->toBe(['earned_ytd_cents' => 0, 'pending_cents' => 0])->and($w['owed_cents'])->toBe(0);

    $this->actingAs($this->ann)->get('/my-money')->assertInertia(fn (Assert $p) => $p->component('finance/my-money')
        ->where('wallet.earned_ytd_cents', 1_200_000)->where('wallet.pending_deals.0.expected_cents', 800_000));
});

it('starts empty for a new agent', function (): void {
    $w = app(Wallet::class)->for($this->ann);
    expect($w['earned_ytd_cents'])->toBe(0)->and($w['paid_ytd_cents'])->toBe(0)->and($w['pending_deals'])->toBe([])->and($w['now']['available_cents'])->toBe(0);
});
