<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Calculator\AgentShare;
use Modules\Compensation\Calculator\DealSideInput;
use Modules\Compensation\CommissionService;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\RevenueShare\Sponsorships;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\Money\Money;

/*
| CM3: the plan builder's revenue share: a yearly maximum per person at each level, and flat fee plans.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01'));
    app(TenantContext::class)->set(Tenant::factory()->create());
    $this->agent = User::factory()->create();
    $this->sponsor = User::factory()->create();
    app(Sponsorships::class)->set($this->agent, $this->sponsor, CarbonImmutable::parse('2026-01-01'));
});

function builderPlan(array $rules): void
{
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, 'P '.uniqid(), $rules);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
}

function builderDeal(User $agent, int $gci): int
{
    $svc = app(CommissionService::class);

    return $svc->finalise($svc->calculate(new DealSideInput(Money::of($gci), 40_000_000, CarbonImmutable::parse('2026-05-01'), [new AgentShare($agent->id)]), 'B'.uniqid()))->id;
}

it('stops paying a level once its yearly maximum is reached', function (): void {
    builderPlan(['split' => ['mode' => 'percentage', 'agent_percent' => '80'],
        'revenue_share' => ['basis' => 'net_profit', 'levels' => ['100'], 'level_caps_cents' => [150_000]]]);

    builderDeal($this->agent, 1_000_000); // company $2,000 → level 1 earns $1,500 (the maximum), $500 missed
    $second = builderDeal($this->agent, 1_000_000);

    expect((int) DB::table('rs_entries')->where('recipient_user_id', $this->sponsor->id)->where('kind', 'share')->sum('amount_cents'))->toBe(150_000)
        ->and(DB::table('rs_entries')->where('calculation_id', $second)->where('kind', 'missed')->value('reason'))->toBe('level_limit');
});

it('refuses a bad level maximum and accepts a flat fee plan', function (): void {
    expect(fn () => builderPlan(['split' => ['mode' => 'percentage', 'agent_percent' => '80'],
        'revenue_share' => ['basis' => 'net_profit', 'levels' => ['100'], 'level_caps_cents' => [-5]]]))->toThrow(ValidationException::class);

    builderPlan(['split' => ['mode' => 'flat_fee', 'bands' => [['up_to_price_cents' => null, 'fee_cents' => 50_000]]],
        'revenue_share' => ['basis' => 'net_profit', 'levels' => ['100']]]);
    builderDeal($this->agent, 1_000_000); // the brokerage keeps a $500 fee, all of it shared at level 1
    expect((int) DB::table('rs_entries')->where('recipient_user_id', $this->sponsor->id)->where('kind', 'share')->sum('amount_cents'))->toBe(50_000);
});
