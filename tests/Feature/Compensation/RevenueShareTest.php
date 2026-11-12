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
use Modules\Compensation\Exceptions\InvalidRules;
use Modules\Compensation\Models\Calculation;
use Modules\Compensation\RevenueShare\RevenueShareEngine;
use Modules\Compensation\RevenueShare\Sponsorships;
use Modules\Compensation\Rules\CommissionRules;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\Money\Money;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01'));
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($this->tenant);
    $this->root = User::factory()->owner()->create(['first_name' => 'Root']);
    [$this->a, $this->b, $this->c, $this->d] = [User::factory()->create(['first_name' => 'A']), User::factory()->create(['first_name' => 'B']),
        User::factory()->create(['first_name' => 'C']), User::factory()->create(['first_name' => 'D'])];
    $this->sponsors = app(Sponsorships::class);
    $from = CarbonImmutable::parse('2026-01-01');
    $this->sponsors->set($this->a, $this->b, $from);
    $this->sponsors->set($this->b, $this->c, $from);
    $this->sponsors->set($this->c, $this->d, $from);
});

function rsPlan(array $revenueShare, string $agentPercent = '80'): void
{
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, 'RS '.uniqid(), ['split' => ['mode' => 'percentage', 'agent_percent' => $agentPercent], 'revenue_share' => $revenueShare]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
}

function closeDeal(User $agent, int $gci, string $date = '2026-06-10'): Calculation
{
    $svc = app(CommissionService::class);

    return $svc->finalise($svc->calculate(new DealSideInput(Money::of($gci), 40_000_000, CarbonImmutable::parse($date), [new AgentShare($agent->id)]), 'D'.uniqid()));
}

function rsRows(Calculation $c, ?string $kind = null): array
{
    return DB::table('rs_entries')->where('calculation_id', $c->id)->whereNull('reversal_of')->when($kind, fn ($q) => $q->where('kind', $kind))
        ->orderBy('level')->get(['level', 'kind', 'recipient_user_id', 'missed_user_id', 'amount_cents', 'reason'])->map(fn ($r) => (array) $r)->all();
}

it('validates level totals exactly like legacy rules 5–7', function (): void {
    $parse = fn (array $rs, string $agent = '90') => CommissionRules::fromArray(['split' => ['mode' => 'percentage', 'agent_percent' => $agent], 'revenue_share' => $rs]);

    expect(fn () => $parse(['basis' => 'net_profit', 'levels' => ['50', '30']]))->toThrow(InvalidRules::class)
        ->and(fn () => $parse(['basis' => 'gci', 'levels' => ['50', '30', '20']]))->toThrow(InvalidRules::class) // legacy rule 7
        ->and($parse(['basis' => 'gci', 'levels' => ['5', '3', '2']])->revenueShare?->levels)->toBe(['5', '3', '2'])
        ->and($parse(['basis' => 'net_profit', 'levels' => ['33.33', '33.33', '33.34']])->revenueShare?->basis)->toBe('net_profit')
        ->and(fn () => $parse(['basis' => 'net_profit', 'levels' => array_fill(0, 16, '6.25')]))->toThrow(InvalidRules::class)
        ->and(fn () => CommissionRules::fromArray(['split' => ['mode' => 'flat_fee', 'fee' => ['calc' => 'flat', 'amount' => 50_000]], 'revenue_share' => ['basis' => 'gci', 'levels' => ['10']]]))->toThrow(InvalidRules::class);
});

it('pays each sponsor level from net profit into their wallet with a balanced journal', function (): void {
    rsPlan(['basis' => 'net_profit', 'levels' => ['50', '30', '20']]);
    $deal = closeDeal($this->a, 1_000_000); // company $2,000

    expect(rsRows($deal))->toBe([
        ['level' => 1, 'kind' => 'share', 'recipient_user_id' => $this->b->id, 'missed_user_id' => null, 'amount_cents' => 100_000, 'reason' => null],
        ['level' => 2, 'kind' => 'share', 'recipient_user_id' => $this->c->id, 'missed_user_id' => null, 'amount_cents' => 60_000, 'reason' => null],
        ['level' => 3, 'kind' => 'share', 'recipient_user_id' => $this->d->id, 'missed_user_id' => null, 'amount_cents' => 40_000, 'reason' => null],
    ]);
    $engine = app(RevenueShareEngine::class);
    expect($engine->wallet($this->b)->cents)->toBe(100_000)->and($engine->wallet($this->d)->cents)->toBe(40_000)
        ->and((int) DB::table('ledger_lines')->sum('debit_cents'))->toBe((int) DB::table('ledger_lines')->sum('credit_cents'));
});

it('distributes the company revenue on a GCI basis', function (): void {
    rsPlan(['basis' => 'gci', 'levels' => ['5', '3', '2']], '90');
    $deal = closeDeal($this->a, 1_000_000); // GCI $10,000 → 10% = $1,000

    expect(array_column(rsRows($deal), 'amount_cents'))->toBe([50_000, 30_000, 20_000]);
});

it('records a missed earning for an inactive sponsor, or pays the root account when the plan says so', function (): void {
    $this->c->forceFill(['status' => UserStatus::Suspended])->save();
    rsPlan(['basis' => 'net_profit', 'levels' => ['50', '30', '20'], 'ineligible_upline' => 'forfeit']);
    $forfeit = closeDeal($this->a, 1_000_000);
    expect(rsRows($forfeit, 'missed'))->toBe([['level' => 2, 'kind' => 'missed', 'recipient_user_id' => null, 'missed_user_id' => $this->c->id, 'amount_cents' => 60_000, 'reason' => 'ineligible_upline']]);

    rsPlan(['basis' => 'net_profit', 'levels' => ['50', '30', '20'], 'ineligible_upline' => 'root']);
    $root = closeDeal($this->a, 1_000_000);
    expect(rsRows($root, 'root'))->toBe([['level' => 2, 'kind' => 'root', 'recipient_user_id' => $this->root->id, 'missed_user_id' => $this->c->id, 'amount_cents' => 60_000, 'reason' => 'ineligible_upline']]);
});

it('sends missing uplines to the root account within its per-deal cap', function (): void {
    rsPlan(['basis' => 'net_profit', 'levels' => ['40', '30', '20', '10'], 'missing_upline' => 'root', 'fallback_cap_cents' => 10_000]);
    $deal = closeDeal($this->a, 1_000_000); // level 4 has no sponsor: $200 → root $100 (cap), $100 missed

    expect(rsRows($deal, 'root'))->toBe([['level' => 4, 'kind' => 'root', 'recipient_user_id' => $this->root->id, 'missed_user_id' => null, 'amount_cents' => 10_000, 'reason' => 'missing_upline']])
        ->and(rsRows($deal, 'missed'))->toBe([['level' => 4, 'kind' => 'missed', 'recipient_user_id' => null, 'missed_user_id' => null, 'amount_cents' => 10_000, 'reason' => 'fallback_cap']]);
});

it('applies sponsor changes only from their date, and refuses self-sponsorship and cycles', function (): void {
    rsPlan(['basis' => 'net_profit', 'levels' => ['100']]);
    $e = User::factory()->create(['first_name' => 'E']);
    $this->sponsors->set($this->a, $e, CarbonImmutable::parse('2026-07-01'), $this->root, 'Moved to E after a sponsorship review');

    expect(rsRows(closeDeal($this->a, 1_000_000, '2026-06-20'))[0]['recipient_user_id'])->toBe($this->b->id)
        ->and(rsRows(closeDeal($this->a, 1_000_000, '2026-07-02'))[0]['recipient_user_id'])->toBe($e->id)
        ->and(fn () => $this->sponsors->set($this->a, $this->a, CarbonImmutable::parse('2026-08-01')))->toThrow(ValidationException::class)
        ->and(fn () => $this->sponsors->set($this->d, $this->c, CarbonImmutable::parse('2026-08-01')))->toThrow(ValidationException::class, 'own network');
});

it('reverses revenue share with exact negatives and empties the wallets', function (): void {
    rsPlan(['basis' => 'net_profit', 'levels' => ['50', '30', '20']]);
    $deal = closeDeal($this->a, 1_000_000);
    app(CommissionService::class)->reverse($deal, 'Deal unwound after closing');

    expect((int) DB::table('rs_entries')->where('calculation_id', $deal->id)->sum('amount_cents'))->toBe(0)
        ->and(DB::table('rs_entries')->whereNotNull('reversal_of')->count())->toBe(3)
        ->and(app(RevenueShareEngine::class)->wallet($this->b)->cents)->toBe(0)
        ->and(app(RevenueShareEngine::class)->wallet($this->d)->cents)->toBe(0);
});

it('accounts for every cent across 1,000 deals with random networks and policies', function (): void {
    mt_srand(7);
    $people = [$this->a, $this->b, $this->c, $this->d];
    for ($i = 0; $i < 6; $i++) {
        $people[] = User::factory()->create();
    }
    rsPlan(['basis' => 'net_profit', 'levels' => ['30', '25', '20', '15', '10'], 'missing_upline' => 'root', 'ineligible_upline' => 'forfeit', 'fallback_cap_cents' => 5_000]);
    $engine = app(RevenueShareEngine::class);
    $rules = CommissionRules::fromArray(['split' => ['mode' => 'percentage', 'agent_percent' => '80'], 'revenue_share' => ['basis' => 'net_profit', 'levels' => ['30', '25', '20', '15', '10']]])->revenueShare;

    for ($n = 0; $n < 1_000; $n++) {
        if ($n % 50 === 0) {
            $people[mt_rand(1, 9)]->forceFill(['status' => mt_rand(0, 3) === 0 ? UserStatus::Suspended : UserStatus::Active])->save();
        }
        $agent = $people[mt_rand(0, 9)];
        $calc = app(CommissionService::class)->calculate(new DealSideInput(Money::of(mt_rand(1, 3_000_000)), 1, CarbonImmutable::parse('2026-06-10'), [new AgentShare($agent->id)]), 'P'.$n);
        $final = app(CommissionService::class)->finalise($calc);
        $company = (int) $final->result['agents'][0]['company_cents'];
        expect((int) DB::table('rs_entries')->where('calculation_id', $final->id)->sum('amount_cents'))->toBe($engine->distributable($company, $rules));
    }
});
