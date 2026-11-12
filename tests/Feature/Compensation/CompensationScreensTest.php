<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Calculator\AgentShare;
use Modules\Compensation\Calculator\DealSideInput;
use Modules\Compensation\CommissionService;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Calculation;
use Modules\Compensation\Models\Plan;
use Modules\Ledger\Money\Money;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'created_at' => '2024-03-15']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20 capped', [
        'split' => ['mode' => 'percentage', 'agent_percent' => '80'], 'cap' => ['amount_cents' => 1_600_000],
    ]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $this->service = app(CommissionService::class);
});

function closed(int $gci, string $date, int $agentId): Calculation
{
    $svc = app(CommissionService::class);

    return $svc->finalise($svc->calculate(new DealSideInput(Money::of($gci), 40_000_000, CarbonImmutable::parse($date), [new AgentShare($agentId)]), 'D'.uniqid()));
}

it('builds a plan end to end: draft, publish, default, and moving an agent onto it', function (): void {
    $this->actingAs($this->owner)->post('/compensation/plans', ['name' => 'Bad', 'type' => 'commission', 'rules' => ['split' => ['mode' => 'percentage', 'agent_percent' => '180']]])
        ->assertSessionHasErrors('split.agent_percent');

    $this->actingAs($this->owner)->post('/compensation/plans', ['name' => '90/10 no cap', 'type' => 'commission', 'rules' => ['split' => ['mode' => 'percentage', 'agent_percent' => '90']]])->assertSessionHasNoErrors();
    $plan = Plan::query()->where('name', '90/10 no cap')->sole();
    $this->actingAs($this->owner)->put("/compensation/plans/{$plan->ulid}/draft", ['rules' => ['split' => ['mode' => 'percentage', 'agent_percent' => '85']]])->assertSessionHasNoErrors();
    $draft = $plan->versions()->where('status', 'draft')->sole();
    $this->actingAs($this->owner)->post("/compensation/versions/{$draft->ulid}/publish", ['effective_from' => '2026-06-01'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/compensation/assignments', ['agent' => $this->agent->ulid, 'plan' => $plan->ulid, 'from' => '2026-06-01'])->assertSessionHasNoErrors();

    $this->actingAs($this->owner)->get('/compensation/plans')->assertInertia(fn (Assert $page) => $page
        ->component('compensation/plans')->where('canManage', true)
        ->where('agents', fn ($agents) => collect($agents)->firstWhere('name', $this->agent->name)['plan'] === '90/10 no cap'));
    $this->actingAs($this->agent)->get('/compensation/plans')->assertForbidden();
    $this->actingAs($this->agent)->post('/compensation/plans', ['name' => 'x', 'type' => 'commission', 'rules' => ['split' => ['mode' => 'percentage', 'agent_percent' => '99']]])->assertForbidden();
});

it('publishes fee versions and gives credits from the screens', function (): void {
    $this->actingAs($this->owner)->post('/compensation/fees', ['code' => 'tech_fee', 'name' => 'Technology fee', 'effective_from' => '2026-01-01', 'stage' => 'post',
        'calc' => 'flat', 'amount' => '395', 'charged_to' => 'agent', 'payable_to' => 'brokerage', 'counts_toward_cap' => true, 'revenue_share_eligible' => true])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->get('/compensation/fees')->assertInertia(fn (Assert $page) => $page
        ->where('fees.0.code', 'tech_fee')->where('fees.0.versions.0.amount', '39500'));

    $this->actingAs($this->owner)->post('/compensation/credits', ['agent' => $this->agent->ulid, 'kind' => 'marketing', 'name' => 'Welcome credit', 'amount' => '250'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->get('/compensation/credits')->assertInertia(fn (Assert $page) => $page->where('credits.0.remaining_cents', 25_000));
    $this->actingAs($this->agent)->get('/compensation/credits')->assertForbidden();
});

it('simulates a deal with the agent\'s real cap progress without saving anything', function (): void {
    closed(7_500_000, '2026-04-01', $this->agent->id); // $15,000 company dollar of a $16,000 cap
    $before = Calculation::query()->count();

    $this->actingAs($this->owner)->postJson('/compensation/simulate', [
        'gci' => '10000', 'sale_price' => '400000', 'closing_date' => '2026-06-10', 'side' => 'listing', 'deal_class' => 'sale',
        'agents' => [['ulid' => $this->agent->ulid, 'share' => '100']],
    ])->assertOk()
        ->assertJsonPath('agents.0.company_cents', 100_000)   // only $1,000 left before the cap
        ->assertJsonPath('agents.0.agent_net_cents', 900_000)
        ->assertJsonPath('agents.0.capped', true);
    expect(Calculation::query()->count())->toBe($before);
});

it('shows each agent their plan: cap progress, forecast and pending take-home', function (): void {
    closed(2_500_000, '2026-04-15', $this->agent->id); // $5,000 company dollar since 15 March
    $this->service->calculate(new DealSideInput(Money::of(1_000_000), 40_000_000, CarbonImmutable::parse('2026-06-20'), [new AgentShare($this->agent->id)]), 'P1');

    $this->actingAs($this->agent)->get('/my-plan')->assertInertia(fn (Assert $page) => $page
        ->component('compensation/my-plan')->where('me.plan', '80/20 capped')->where('me.paid_cents', 500_000)->where('me.to_cap_cents', 1_100_000)
        ->where('me.pending_deals', 1)->where('me.pending_take_home_cents', 800_000)
        ->where('me.forecast', fn ($f) => str_starts_with((string) $f, "At your current pace you'll cap in")));

    closed(10_000_000, '2026-05-01', $this->agent->id);
    $this->actingAs($this->agent)->get('/my-plan')->assertInertia(fn (Assert $page) => $page->where('me.capped', true));
});

it('reports company dollar by plan and agent, and replays the last year through a proposed plan', function (): void {
    closed(5_000_000, '2026-02-01', $this->agent->id);
    closed(5_000_000, '2026-03-01', $this->agent->id);

    $this->actingAs($this->owner)->get('/compensation/profitability?year=2026')->assertInertia(fn (Assert $page) => $page
        ->where('report.totals.deals', 2)->where('report.totals.company_cents', 1_600_000)->where('report.plans.0.key', '80/20 capped'));

    $this->actingAs($this->owner)->postJson('/compensation/what-if', ['rules' => ['split' => ['mode' => 'percentage', 'agent_percent' => '70'], 'cap' => ['amount_cents' => 2_000_000]]])
        ->assertOk()->assertJsonPath('deals', 2)->assertJsonPath('actual_company_cents', 1_600_000)->assertJsonPath('proposed_company_cents', 2_000_000);

    $staff = User::factory()->staff()->create();
    $this->actingAs($staff)->get('/compensation/profitability')->assertForbidden();
});

it('shows agents My plan and owners the Compensation menu', function (): void {
    $menu = fn (User $u) => collect($this->actingAs($u)->get('/contacts')->viewData('page')['props']['navigation'])->flatMap(fn ($g) => collect($g['items'])->pluck('title'))->all();

    expect($menu($this->agent))->toContain('My plan')->not->toContain('Simulator')
        ->and($menu($this->owner))->toContain('Plans', 'Fees', 'Credits', 'Simulator', 'Profitability')->not->toContain('My plan');
});
