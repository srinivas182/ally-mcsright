<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\CalculationPresenter;
use Modules\Compensation\Calculator\AgentShare;
use Modules\Compensation\Calculator\DealSideInput;
use Modules\Compensation\CommissionService;
use Modules\Compensation\Credits;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Exceptions\InvalidRules;
use Modules\Compensation\FeeCatalogue;
use Modules\Compensation\Models\CapPeriod;
use Modules\Core\Access\Permissions;
use Modules\Core\Access\Role;
use Modules\Core\Access\Roles;
use Modules\Core\Access\Team;
use Modules\Core\Audit\FlushAuditOutbox;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\Money\Money;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01'));
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($this->tenant);
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['created_at' => '2024-03-15']);
    $this->catalogue = app(FeeCatalogue::class);
    $this->service = app(CommissionService::class);
});

function feePlan(array $rules): void
{
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, 'Plan '.uniqid(), $rules);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
}

function feeSide(int $gci, string $closing = '2026-06-10', ?int $agentId = null, ?string $excluded = null): DealSideInput
{
    return new DealSideInput(Money::of($gci), 40_000_000, CarbonImmutable::parse($closing), [new AgentShare($agentId ?? test()->agent->id)], excludedReason: $excluded);
}

function feeRow($calc, int $i = 0): array
{
    return $calc->result['agents'][$i];
}

it('applies catalogue fees by the version in force on the closing date (legacy rule 14)', function (): void {
    $this->catalogue->publish('tech_fee', 'Technology fee', CarbonImmutable::parse('2026-01-01'), ['stage' => 'post', 'calc' => 'flat', 'amount' => 50_000]);
    $this->catalogue->publish('tech_fee', 'Technology fee', CarbonImmutable::parse('2026-07-01'), ['stage' => 'post', 'calc' => 'flat', 'amount' => 60_000]);
    feePlan(['split' => ['mode' => 'percentage', 'agent_percent' => '80'], 'fees' => ['tech_fee']]);

    $june = $this->service->calculate(feeSide(1_000_000, '2026-06-20'), 'D1');
    $july = $this->service->calculate(feeSide(1_000_000, '2026-07-20'), 'D2');
    expect(feeRow($june)['post_split_cents'])->toBe(50_000)->and(feeRow($july)['post_split_cents'])->toBe(60_000)
        ->and(feeRow($june)['agent_net_cents'])->toBe(750_000);

    // A plan cannot be published with a fee that is not in the catalogue.
    expect(fn () => feePlan(['split' => ['mode' => 'percentage', 'agent_percent' => '80'], 'fees' => ['missing_fee']]))
        ->toThrow(InvalidRules::class, 'missing_fee');
});

it('stops a fee at its yearly cap and starts again next year', function (): void {
    $this->catalogue->publish('royalty', 'Franchise royalty', CarbonImmutable::parse('2026-01-01'),
        ['stage' => 'pre', 'calc' => 'percent', 'amount' => '6', 'payable_to' => 'franchisor', 'annual_cap_cents' => 300_000]);
    feePlan(['split' => ['mode' => 'percentage', 'agent_percent' => '80'], 'fees' => ['royalty']]);

    $a = $this->service->finalise($this->service->calculate(feeSide(3_000_000), 'R1'));   // 6% = $1,800
    $b = $this->service->finalise($this->service->calculate(feeSide(3_000_000), 'R2'));   // only $1,200 left
    $c = $this->service->calculate(feeSide(3_000_000), 'R3');                               // capped: $0
    $next = $this->service->calculate(feeSide(3_000_000, '2027-02-01'), 'R4');              // new year
    expect(feeRow($a)['pre_split_cents'])->toBe(180_000)->and(feeRow($b)['pre_split_cents'])->toBe(120_000)
        ->and(feeRow($c)['pre_split_cents'])->toBe(0)->and(feeRow($next)['pre_split_cents'])->toBe(180_000);
});

it('handles brokerage-paid fees, fees that count toward the cap, and credits that pay fees', function (): void {
    $this->catalogue->publish('eo_fee', 'E&O insurance', CarbonImmutable::parse('2026-01-01'), ['stage' => 'post', 'calc' => 'flat', 'amount' => 20_000, 'charged_to' => 'brokerage']);
    $this->catalogue->publish('txn_fee', 'Transaction fee', CarbonImmutable::parse('2026-01-01'), ['stage' => 'post', 'calc' => 'flat', 'amount' => 50_000, 'counts_toward_cap' => true]);
    feePlan(['split' => ['mode' => 'percentage', 'agent_percent' => '80'], 'cap' => ['amount_cents' => 1_600_000], 'fees' => ['eo_fee', 'txn_fee']]);
    $credits = app(Credits::class);
    $credits->grant($this->agent->id, 'marketing', 'Welcome marketing credit', 30_000, CarbonImmutable::parse('2026-12-31'), $this->owner);
    $credits->grant($this->agent->id, 'signing', 'Expired', 99_000, CarbonImmutable::parse('2026-01-31'), $this->owner);

    $calc = $this->service->calculate(feeSide(1_000_000), 'C1');
    // 80/20 → company $2,000; agent $8,000 − $500 txn fee + $300 credit; E&O paid by the brokerage.
    expect(feeRow($calc)['agent_net_cents'])->toBe(780_000)->and(feeRow($calc)['credit_used_cents'])->toBe(30_000)
        ->and(feeRow($calc)['brokerage_fees_cents'])->toBe(20_000)->and(feeRow($calc)['counted_fees_cents'])->toBe(50_000);

    $final = $this->service->finalise($calc);
    expect(CapPeriod::query()->sole()->company_dollar_cents)->toBe(250_000)
        ->and($credits->available($this->agent->id, CarbonImmutable::parse('2026-06-10')))->toBe(0);

    $this->service->reverse($final, 'Deal fell through after closing');
    expect($credits->available($this->agent->id, CarbonImmutable::parse('2026-06-10')))->toBe(30_000)
        ->and(CapPeriod::query()->sole()->company_dollar_cents)->toBe(0);
});

it('takes the team split from members after the brokerage split, not from the team lead', function (): void {
    feePlan(['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $lead = User::factory()->create();
    $team = Team::query()->create(['name' => 'Smith Team', 'leader_user_id' => $lead->id, 'comp_split_percent' => '30']);
    foreach ([$this->agent, $lead] as $u) {
        DB::table('team_members')->insert(['tenant_id' => $this->tenant->id, 'team_id' => $team->id, 'user_id' => $u->id, 'joined_at' => now()]);
    }

    $member = $this->service->calculate(feeSide(1_000_000), 'T1');
    $leads = $this->service->calculate(feeSide(1_000_000, agentId: $lead->id), 'T2');
    expect(feeRow($member)['team_split_cents'])->toBe(240_000)->and(feeRow($member)['team_leader_user_id'])->toBe($lead->id)
        ->and(feeRow($member)['agent_net_cents'])->toBe(560_000)->and(feeRow($leads)['team_split_cents'])->toBe(0);
});

it('excludes a deal from plan progression and cap, with a recorded reason', function (): void {
    feePlan(['split' => ['mode' => 'percentage', 'agent_percent' => '80'], 'cap' => ['amount_cents' => 1_600_000]]);

    expect(fn () => $this->service->calculate(feeSide(1_000_000, excluded: 'no'), 'E0'))->toThrow(ValidationException::class);
    $final = $this->service->finalise($this->service->calculate(feeSide(1_000_000, excluded: 'Personal purchase by the agent'), 'E1'));
    expect(feeRow($final)['excluded'])->toBeTrue()->and(feeRow($final)['company_cents'])->toBe(200_000)
        ->and(CapPeriod::query()->value('company_dollar_cents') ?? 0)->toBe(0);
    (new FlushAuditOutbox)->handle();
    expect(DB::table('audit_events')->where('action', 'compensation.deal.excluded')->value('metadata'))->toContain('Personal purchase');
});

it('hides commission amounts from anyone without permission, except an agent\'s own line', function (): void {
    feePlan(['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $other = User::factory()->create();
    $staff = User::factory()->staff()->create();
    $calc = $this->service->calculate(new DealSideInput(Money::of(1_000_000), 40_000_000, CarbonImmutable::parse('2026-06-10'),
        [new AgentShare($this->agent->id, '50'), new AgentShare($other->id, '50')]), 'P1');
    $presenter = app(CalculationPresenter::class);

    $asAgent = $presenter->present($calc, $this->agent);
    expect($asAgent['amounts_hidden'])->toBeTrue()->and($asAgent['agents'][0]['agent_net_cents'])->toBe(400_000)
        ->and($asAgent['agents'][1])->not->toHaveKey('agent_net_cents')->and($asAgent['steps'])->toBeNull();
    expect($presenter->present($calc, $this->owner)['agents'][1]['agent_net_cents'])->toBe(400_000)
        ->and($presenter->present($calc, $staff)['amounts_hidden'])->toBeTrue();

    $roles = app(Roles::class);
    $roles->all();
    $roles->assign($staff, Role::query()->where('template_key', 'bookkeeper')->sole(), $this->owner);
    app(Permissions::class)->forget();
    expect($presenter->present($calc, $staff->fresh())['amounts_hidden'])->toBeFalse();
});

it('accounts for every cent across 300 deals mixing fees, caps, credits and team splits', function (): void {
    $this->catalogue->publish('royalty', 'Royalty', CarbonImmutable::parse('2026-01-01'), ['stage' => 'pre', 'calc' => 'percent', 'amount' => '6', 'annual_cap_cents' => 500_000]);
    $this->catalogue->publish('txn', 'Transaction', CarbonImmutable::parse('2026-01-01'), ['stage' => 'post', 'calc' => 'flat', 'amount' => 39_500, 'counts_toward_cap' => true]);
    $this->catalogue->publish('eo', 'E&O', CarbonImmutable::parse('2026-01-01'), ['stage' => 'post', 'calc' => 'percent', 'amount' => '1.5', 'charged_to' => 'brokerage']);
    feePlan(['split' => ['mode' => 'percentage', 'agent_percent' => '75'], 'cap' => ['amount_cents' => 2_000_000], 'fees' => ['royalty', 'txn', 'eo']]);
    $lead = User::factory()->create();
    $team = Team::query()->create(['name' => 'T', 'leader_user_id' => $lead->id, 'comp_split_percent' => '25']);
    DB::table('team_members')->insert(['tenant_id' => $this->tenant->id, 'team_id' => $team->id, 'user_id' => $this->agent->id, 'joined_at' => now()]);
    app(Credits::class)->grant($this->agent->id, 'production', 'Production bonus', 250_000, null, $this->owner);

    mt_srand(42);
    for ($i = 0; $i < 300; $i++) {
        $gci = mt_rand(1, 5_000_000);
        $calc = $this->service->calculate(feeSide($gci, '2026-'.str_pad((string) mt_rand(6, 12), 2, '0', STR_PAD_LEFT).'-15', excluded: $i % 17 === 0 ? 'Agent personal transaction' : null), 'X'.$i);
        $r = feeRow($calc);
        expect($r['pre_split_cents'] + $r['company_cents'] + $r['post_cap_fee_cents'] + $r['team_split_cents'] + $r['post_split_cents'] + $r['agent_net_cents'] - $r['credit_used_cents'])->toBe($gci);
        if ($i % 3 === 0) {
            $this->service->finalise($calc);
        }
    }
});
