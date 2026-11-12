<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Calculator\AgentShare;
use Modules\Compensation\Calculator\DealSideInput;
use Modules\Compensation\CommissionService;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Calculation;
use Modules\Compensation\RevenueShare\BudgetWarning;
use Modules\Compensation\RevenueShare\Sponsorships;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\Money\Money;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01'));
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($this->tenant);
    $this->root = User::factory()->owner()->create(['first_name' => 'Root']);
    foreach (['a', 'b', 'c', 'd', 'e'] as $n) {
        $this->{$n} = User::factory()->create(['first_name' => strtoupper($n)]);
    }
    $this->sponsors = app(Sponsorships::class);
    $from = CarbonImmutable::parse('2026-01-01');
    $this->sponsors->set($this->a, $this->b, $from);
    $this->sponsors->set($this->b, $this->c, $from);
    $this->sponsors->set($this->c, $this->d, $from);
    $this->sponsors->set($this->d, $this->e, $from);
});

function unlockPlan(array $revenueShare): void
{
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, 'RS '.uniqid(), ['split' => ['mode' => 'percentage', 'agent_percent' => '80'], 'revenue_share' => $revenueShare]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
}

function closeRsDeal(User $agent, int $gci, string $date = '2026-06-10'): Calculation
{
    $svc = app(CommissionService::class);

    return $svc->finalise($svc->calculate(new DealSideInput(Money::of($gci), 40_000_000, CarbonImmutable::parse($date), [new AgentShare($agent->id)]), 'D'.uniqid()));
}

function paidTo(Calculation $c): array
{
    return DB::table('rs_entries')->where('calculation_id', $c->id)->where('kind', 'share')->orderBy('level')->pluck('amount_cents', 'recipient_user_id')->map(fn ($v) => (int) $v)->all();
}

function missedFor(Calculation $c): array
{
    return DB::table('rs_entries')->where('calculation_id', $c->id)->where('kind', 'missed')->orderBy('level')->get(['level', 'reason', 'amount_cents'])
        ->map(fn ($r) => [(int) $r->level, $r->reason, (int) $r->amount_cents])->all();
}

it('unlocks deeper levels with qualifying front-line agents', function (): void {
    unlockPlan(['basis' => 'net_profit', 'levels' => ['40', '30', '20', '10'], 'unlock' => ['open_levels' => 2, 'thresholds' => ['3' => 1, '4' => 2],
        'qualifying' => ['min_closings' => 2, 'min_gci_cents' => 2_000_000, 'window_months' => 6]]]);

    $before = closeRsDeal($this->a, 1_000_000); // company $2,000: levels 3 (D) and 4 (E) locked
    expect(missedFor($before))->toBe([[3, 'locked_level', 40_000], [4, 'locked_level', 20_000]]);

    closeRsDeal($this->c, 100_000, '2026-05-01'); // C is D's front-line agent: two closings → qualifies
    closeRsDeal($this->c, 100_000, '2026-05-20');
    $after = closeRsDeal($this->a, 1_000_000);
    expect(paidTo($after))->toHaveKey($this->d->id)->and(missedFor($after))->toBe([[4, 'locked_level', 20_000]]);
});

it('opens levels for a sponsor who capped recently', function (): void {
    unlockPlan(['basis' => 'net_profit', 'levels' => ['40', '30', '20', '10'], 'unlock' => ['open_levels' => 2, 'production' => ['through_level' => 4, 'months' => 13]]]);
    DB::table('comp_cap_periods')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'agent_user_id' => $this->e->id, 'starts_on' => '2025-03-01', 'ends_on' => '2026-02-28',
        'cap_amount_cents' => 1_600_000, 'company_dollar_cents' => 1_600_000, 'post_cap_fees_cents' => 0, 'status' => 'capped', 'created_at' => now(), 'updated_at' => now()]);

    $deal = closeRsDeal($this->a, 1_000_000);
    expect(paidTo($deal))->toHaveKey($this->e->id)->not->toHaveKey($this->d->id)
        ->and(missedFor($deal))->toBe([[3, 'locked_level', 40_000]]);
});

it('keeps revenue share within the per-deal and per-recipient yearly maximums', function (): void {
    unlockPlan(['basis' => 'net_profit', 'levels' => ['100'], 'funding' => ['per_deal_max_cents' => 150_000, 'per_recipient_yearly_max_cents' => 200_000]]);

    expect(paidTo(closeRsDeal($this->a, 1_000_000)))->toBe([$this->b->id => 150_000]); // $2,000 capped at $1,500 per deal
    $second = closeRsDeal($this->a, 1_000_000);
    expect(paidTo($second))->toBe([$this->b->id => 50_000])->and(missedFor($second))->toBe([[1, 'recipient_limit', 100_000]]);
    expect(paidTo(closeRsDeal($this->a, 1_000_000, '2027-01-05')))->toBe([$this->b->id => 150_000]); // new year
});

it('stops at the brokerage budget and emails owners once at 80%', function (): void {
    Notification::fake();
    unlockPlan(['basis' => 'net_profit', 'levels' => ['100']]);
    DB::table('rs_settings')->insert(['tenant_id' => $this->tenant->id, 'budget_percent' => 50, 'created_at' => now(), 'updated_at' => now()]);

    $deal = closeRsDeal($this->a, 1_000_000); // company $2,000 → budget $1,000
    expect(paidTo($deal))->toBe([$this->b->id => 100_000])->and(missedFor($deal))->toBe([[1, 'budget', 100_000]]);
    closeRsDeal($this->a, 1_000_000);
    Notification::assertSentToTimes($this->root, BudgetWarning::class, 1);
});

it('shares level 1 with a co-sponsor while deeper levels follow the primary sponsor', function (): void {
    unlockPlan(['basis' => 'net_profit', 'levels' => ['50', '30', '20']]);
    $x = User::factory()->create(['first_name' => 'X']);
    $this->sponsors->set($this->a, $this->b, CarbonImmutable::parse('2026-02-01'), $this->root, 'Recruited together', $x, '40');

    expect(paidTo(closeRsDeal($this->a, 1_000_000)))->toBe([$this->b->id => 60_000, $x->id => 40_000, $this->c->id => 60_000, $this->d->id => 40_000])
        ->and(fn () => $this->sponsors->set($this->a, $this->b, CarbonImmutable::parse('2026-03-01'), null, null, $this->b, '50'))->toThrow(ValidationException::class)
        ->and(fn () => $this->sponsors->set($this->a, $this->b, CarbonImmutable::parse('2026-03-01'), null, null, $x, '100'))->toThrow(ValidationException::class)
        ->and(fn () => $this->sponsors->set($this->d, $this->e, CarbonImmutable::parse('2026-03-01'), null, null, $this->a, '50'))->toThrow(ValidationException::class, 'own network');
});
