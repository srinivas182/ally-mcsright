<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Calculator\AgentShare;
use Modules\Compensation\Calculator\DealSideInput;
use Modules\Compensation\CommissionService;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Calculation;
use Modules\Compensation\RevenueShare\Sponsorships;
use Modules\Ledger\Money\Money;
use Modules\Payouts\RunBreakdown;
use Modules\Transactions\Deals;
use Modules\Transactions\Models\Deal;

/*
| CM2: "Who gets paid" on each transaction (tier by tier), the revenue share report, and payouts that show the deal
| and the level behind each payment.
*/

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olivia']);
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Lee']);
    $this->sponsor = User::factory()->create(['first_name' => 'Sam', 'last_name' => 'Sponsor']);
    $this->top = User::factory()->create(['first_name' => 'Tia', 'last_name' => 'Top']);
    $this->stranger = User::factory()->create(['first_name' => 'Otto']);
    app(Sponsorships::class)->set($this->agent, $this->sponsor, CarbonImmutable::parse('2026-01-01'));
    app(Sponsorships::class)->set($this->sponsor, $this->top, CarbonImmutable::parse('2026-01-01'));
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20 with revenue share', ['split' => ['mode' => 'percentage', 'agent_percent' => '80'],
        'revenue_share' => ['basis' => 'net_profit', 'levels' => ['60', '40']]]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $this->deal = app(Deals::class)->create(['type' => 'buyer', 'street' => '12 Elm St', 'city' => 'Austin', 'state_code' => 'TX', 'price_cents' => 50_000_000],
        $this->agent, $this->agent);
});

function wgpCalc(Deal $deal, User $agent, bool $final): Calculation
{
    $svc = app(CommissionService::class);
    $pending = $svc->calculate(new DealSideInput(Money::of(1_000_000), 50_000_000, CarbonImmutable::parse('2026-06-01'), [new AgentShare($agent->id)]), $deal->ulid);

    return $final ? $svc->finalise($pending) : $pending;
}

it('shows the owner who gets paid on a closed deal, level by level', function (): void {
    wgpCalc($this->deal, $this->agent, true);

    // GCI $10,000: Pat keeps $8,000; the brokerage's $2,000 goes 60% to Sam (level 1), 40% to Tia (level 2).
    $this->actingAs($this->owner)->get("/deals/{$this->deal->ulid}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('whoGetsPaid.status', 'final')
        ->where('whoGetsPaid.gci_cents', 1_000_000)
        ->where('whoGetsPaid.agents.0.name', $this->agent->name)->where('whoGetsPaid.agents.0.net_cents', 800_000)
        ->where('whoGetsPaid.company_cents', 200_000)->where('whoGetsPaid.revenue_share_cents', 200_000)->where('whoGetsPaid.brokerage_keeps_cents', 0)
        ->where('whoGetsPaid.levels.0.level', 1)->where('whoGetsPaid.levels.0.who', $this->sponsor->name)->where('whoGetsPaid.levels.0.amount_cents', 120_000)
        ->where('whoGetsPaid.levels.1.level', 2)->where('whoGetsPaid.levels.1.who', $this->top->name)->where('whoGetsPaid.levels.1.amount_cents', 80_000));
});

it('shows an estimate on an open deal, without writing anything', function (): void {
    wgpCalc($this->deal, $this->agent, false);

    $this->actingAs($this->owner)->get("/deals/{$this->deal->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('whoGetsPaid.status', 'estimate')
        ->where('whoGetsPaid.levels.0.who', $this->sponsor->name)->where('whoGetsPaid.levels.1.who', $this->top->name));
    expect(DB::table('rs_entries')->count())->toBe(0);
});

it('lets the agent see their own money but never what each upline earns', function (): void {
    wgpCalc($this->deal, $this->agent, true);

    $this->actingAs($this->agent)->get("/deals/{$this->deal->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('whoGetsPaid.agents.0.net_cents', 800_000)
        ->where('whoGetsPaid.levels', [])->where('whoGetsPaid.company_cents', null)->where('whoGetsPaid.revenue_share_cents', 200_000));
});

it('reports revenue share by person and level, and downloads it safely', function (): void {
    wgpCalc($this->deal, $this->agent, true);
    $this->top->forceFill(['first_name' => '=HYPERLINK("x")'])->save();

    $this->actingAs($this->owner)->get('/compensation/revenue-share?from=2026-01-01&to=2026-12-31')->assertInertia(fn (Assert $page) => $page
        ->where('report.total_cents', 200_000)->where('report.deals', 1)->where('report.levels', 2)
        ->where('report.people.0.name', $this->sponsor->name)->where('report.people.0.by_level.1', 120_000));

    $csv = $this->actingAs($this->owner)->get('/compensation/revenue-share/report.csv?from=2026-01-01&to=2026-12-31')->assertOk()->streamedContent();
    expect($csv)->toContain('Person,"Total received","Level 1","Level 2",Deals,Missed')
        ->toContain('"Sam Sponsor",1200.00,1200.00,0.00,1,0.00')
        ->toContain("'=HYPERLINK")->toContain('Total,2000.00,1200.00,800.00,1,0.00');

    $this->actingAs($this->agent)->get('/compensation/revenue-share/report.csv')->assertForbidden();
});

it('explains a payout by deal and level', function (): void {
    wgpCalc($this->deal, $this->agent, true);
    $this->travel(1)->minutes();
    $run = DB::table('pay_runs')->insertGetId(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'status' => 'draft', 'pay_on' => '2026-06-05',
        'same_day' => false, 'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);

    expect(app(RunBreakdown::class)->forLine($this->agent->id, $run))->toBe([['kind' => 'commission', 'deal' => '12 Elm St', 'level' => null, 'from' => null, 'cents' => 800_000]])
        ->and(app(RunBreakdown::class)->forLine($this->top->id, $run))->toBe([['kind' => 'revenue_share', 'deal' => '12 Elm St', 'level' => 2, 'from' => $this->agent->name, 'cents' => 80_000]]);
});
