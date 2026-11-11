<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Calculator\AgentShare;
use Modules\Compensation\Calculator\DealSideInput;
use Modules\Compensation\CommissionService;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Events\AgentCapped;
use Modules\Compensation\Models\Calculation;
use Modules\Compensation\Models\CapPeriod;
use Modules\Compensation\Models\Plan;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\Money\Money;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01'));
    app(TenantContext::class)->set(Tenant::factory()->create());
    $this->agent = User::factory()->create(['created_at' => '2024-03-15']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, 'Capped 80/20', [
        'split' => ['mode' => 'percentage', 'agent_percent' => '80'],
        'cap' => ['amount_cents' => 1_600_000],
        'post_cap' => ['fee' => ['calc' => 'flat', 'amount' => 25_000]],
    ]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $this->service = app(CommissionService::class);
});

function deal(int $gciCents, string $closing = '2026-06-10', ?int $agentId = null): DealSideInput
{
    return new DealSideInput(Money::of($gciCents), 40_000_000, CarbonImmutable::parse($closing), [new AgentShare($agentId ?? test()->agent->id)]);
}

function agentRow(Calculation $c): array
{
    return $c->result['agents'][0];
}

it('tracks company dollar against the cap year from the agent\'s anniversary (legacy rule 10)', function (): void {
    $final = $this->service->finalise($this->service->calculate(deal(5_000_000), 'D1'));
    $period = CapPeriod::query()->sole();

    expect($period->starts_on->toDateString())->toBe('2026-03-15')->and($period->ends_on->toDateString())->toBe('2027-03-14')
        ->and($period->company_dollar_cents)->toBe(1_000_000)->and(agentRow($final)['company_cents'])->toBe(1_000_000);
});

it('caps the agent, charges the post-cap fee after it, and announces it (legacy rules 11-12)', function (): void {
    Event::fake([AgentCapped::class]);
    $this->service->finalise($this->service->calculate(deal(5_000_000), 'D1'));
    $crossing = $this->service->finalise($this->service->calculate(deal(5_000_000), 'D2'));
    $after = $this->service->finalise($this->service->calculate(deal(1_000_000), 'D3'));

    expect(agentRow($crossing)['company_cents'])->toBe(600_000)->and(agentRow($crossing)['capped'])->toBeTrue()
        ->and(agentRow($after)['company_cents'])->toBe(0)->and(agentRow($after)['post_cap_fee_cents'])->toBe(25_000)
        ->and(agentRow($after)['agent_net_cents'])->toBe(975_000)
        ->and(CapPeriod::query()->sole()->status)->toBe('capped');
    Event::assertDispatchedTimes(AgentCapped::class, 1);
});

it('starts a fresh cap each cap year (legacy rule 13)', function (): void {
    $this->service->finalise($this->service->calculate(deal(8_000_000), 'D1'));
    $nextYear = $this->service->finalise($this->service->calculate(deal(1_000_000, '2027-04-01'), 'D2'));

    expect(CapPeriod::query()->count())->toBe(2)->and(agentRow($nextYear)['company_cents'])->toBe(200_000);
});

it('recalculates pending deals automatically when a deal closes, saying what changed', function (): void {
    $pending = $this->service->calculate(deal(1_000_000), 'PENDING');
    expect(agentRow($pending)['company_cents'])->toBe(200_000);

    $this->service->finalise($this->service->calculate(deal(8_000_000), 'BIG'));

    $latest = Calculation::query()->where('deal_ref', 'PENDING')->orderByDesc('version')->first();
    expect($latest->status)->toBe('pending')->and($latest->version)->toBe(2)
        ->and(agentRow($latest)['company_cents'])->toBe(0)
        ->and($latest->change_note)->toContain('company dollar $2,000.00 → $0.00')
        ->and(Calculation::query()->where('deal_ref', 'PENDING')->where('version', 1)->value('status'))->toBe('superseded');
});

it('reversing a closed deal restores cap progress and recalculates pending deals again (legacy rule 13)', function (): void {
    $big = $this->service->finalise($this->service->calculate(deal(8_000_000), 'BIG'));
    $this->service->calculate(deal(1_000_000), 'PENDING');

    $this->service->reverse($big, 'Deal fell through after closing');

    $period = CapPeriod::query()->sole();
    $latest = Calculation::query()->where('deal_ref', 'PENDING')->orderByDesc('version')->first();
    expect($period->company_dollar_cents)->toBe(0)->and($period->status)->toBe('open')
        ->and(agentRow($latest)['company_cents'])->toBe(200_000);
});

it('recalculates pending deals when a new plan version is published', function (): void {
    $this->service->calculate(deal(1_000_000), 'PENDING');
    $plans = app(ManagePlans::class);
    $plan = Plan::query()->sole();

    $plans->publish($plans->saveDraft($plan, ['split' => ['mode' => 'percentage', 'agent_percent' => '90'], 'cap' => ['amount_cents' => 1_600_000]]), CarbonImmutable::parse('2026-06-01'));

    $latest = Calculation::query()->where('deal_ref', 'PENDING')->orderByDesc('version')->first();
    expect(agentRow($latest)['company_cents'])->toBe(100_000)->and($latest->change_note)->toContain('version 2 published');
});

it('never changes a final calculation and never finalises twice', function (): void {
    $final = $this->service->finalise($this->service->calculate(deal(1_000_000), 'D1'));

    expect(fn () => $this->service->calculate(deal(2_000_000), 'D1'))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->finalise($final))->toThrow(ValidationException::class);
});

it('reconciles cap periods with their entries every night', function (): void {
    $this->service->finalise($this->service->calculate(deal(5_000_000), 'D1'));
    $this->artisan('comp:check-caps')->assertSuccessful();

    CapPeriod::query()->update(['company_dollar_cents' => 1]);
    $this->artisan('comp:check-caps')->assertFailed();
});
