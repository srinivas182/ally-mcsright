<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Compensation\Calculator\AgentShare;
use Modules\Compensation\Calculator\CommissionCalculator;
use Modules\Compensation\Calculator\DealSideInput;
use Modules\Compensation\Calculator\ResolvedPlan;
use Modules\Compensation\Enums\DealClass;
use Modules\Compensation\Exceptions\InvalidRules;
use Modules\Compensation\Rules\CommissionRules;
use Modules\Ledger\Money\Money;

function plan(array $rules, string $name = 'Standard'): Closure
{
    $resolved = new ResolvedPlan(CommissionRules::fromArray($rules), $name);

    return fn (int $userId, CarbonImmutable $date): ResolvedPlan => $resolved;
}

function side(int $gciCents, array $agents = [], int $price = 30_000_000, array $extra = []): DealSideInput
{
    return new DealSideInput(...array_merge([
        'gci' => Money::of($gciCents),
        'salePriceCents' => $price,
        'closingDate' => CarbonImmutable::parse('2026-11-20'),
        'agents' => $agents === [] ? [new AgentShare(7)] : $agents,
    ], $extra));
}

const EIGHTY_TWENTY = ['split' => ['mode' => 'percentage', 'agent_percent' => '80']];

it('splits 80/20 with the company percent derived (legacy rules 3 and 4)', function (): void {
    $result = (new CommissionCalculator)->calculate(side(900_000), plan(EIGHTY_TWENTY));
    $agent = $result->agents[0];

    expect($agent->companyDollar->cents)->toBe(180_000)
        ->and($agent->agentNet->cents)->toBe(720_000)
        ->and(implode(' ', $result->explanation()))->toContain('Split 80/20: company 20% of $9,000.00 = $1,800.00');
});

it('never accepts a separately entered company percent (legacy rule 4)', function (): void {
    CommissionRules::fromArray(['split' => ['mode' => 'percentage', 'agent_percent' => '80', 'company_percent' => '25']]);
})->throws(InvalidRules::class, 'company percent is never entered');

it('takes a flat fee by price band: small, large and mega (legacy rule 3)', function (int $price, int $fee): void {
    $rules = ['split' => ['mode' => 'flat_fee', 'bands' => [
        ['up_to_price_cents' => 25_000_000, 'fee_cents' => 50_000],
        ['up_to_price_cents' => 100_000_000, 'fee_cents' => 100_000],
        ['up_to_price_cents' => null, 'fee_cents' => 250_000],
    ]]];

    $agent = (new CommissionCalculator)->calculate(side(2_000_000, price: $price), plan($rules))->agents[0];

    expect($agent->companyDollar->cents)->toBe($fee)->and($agent->agentNet->cents)->toBe(2_000_000 - $fee);
})->with([
    'small' => [20_000_000, 50_000],
    'large' => [60_000_000, 100_000],
    'mega' => [250_000_000, 250_000],
]);

it('never takes a flat fee larger than the GCI', function (): void {
    $rules = ['split' => ['mode' => 'flat_fee', 'bands' => [['up_to_price_cents' => null, 'fee_cents' => 100_000]]]];

    $result = (new CommissionCalculator)->calculate(side(60_000), plan($rules));

    expect($result->agents[0]->companyDollar->cents)->toBe(60_000)
        ->and($result->agents[0]->agentNet->cents)->toBe(0)
        ->and(implode(' ', $result->explanation()))->toContain('limited to what was left');
});

it('chooses the tier from cap-year progress', function (int $ytd, int $company): void {
    $rules = ['split' => ['mode' => 'tiered', 'measure' => 'company_dollar', 'tiers' => [
        ['from' => 0, 'agent_percent' => '70'],
        ['from' => 1_000_000, 'agent_percent' => '80'],
        ['from' => 2_000_000, 'agent_percent' => '90'],
    ]]];

    $agent = (new CommissionCalculator)->calculate(side(1_000_000, [new AgentShare(7, '100', $ytd)]), plan($rules))->agents[0];

    expect($agent->companyDollar->cents)->toBe($company);
})->with([
    'first tier' => [0, 300_000],
    'second tier' => [1_500_000, 200_000],
    'third tier' => [2_000_000, 100_000],
]);

it('tiers can be measured in closings', function (): void {
    $rules = ['split' => ['mode' => 'tiered', 'measure' => 'units', 'tiers' => [
        ['from' => 0, 'agent_percent' => '60'], ['from' => 10, 'agent_percent' => '75'],
    ]]];

    $agent = (new CommissionCalculator)->calculate(side(1_000_000, [new AgentShare(7, '100', 0, 12)]), plan($rules))->agents[0];

    expect($agent->companyDollar->cents)->toBe(250_000);
});

it('takes an outside referral fee off the top', function (): void {
    $result = (new CommissionCalculator)->calculate(side(1_000_000, extra: ['outsideReferralPercent' => '25']), plan(EIGHTY_TWENTY));

    expect($result->outsideReferral->cents)->toBe(250_000)
        ->and($result->agents[0]->companyDollar->cents)->toBe(150_000)
        ->and($result->agents[0]->agentNet->cents)->toBe(600_000);
});

it('applies pre-split deductions before the split and post-split deductions after it', function (): void {
    $rules = EIGHTY_TWENTY + [
        'pre_split' => [['code' => 'franchise_fee', 'name' => 'Franchise fee', 'calc' => 'percent', 'amount' => '6', 'payable_to' => 'franchisor']],
        'post_split' => [
            ['code' => 'transaction_fee', 'name' => 'Transaction fee', 'calc' => 'flat', 'amount' => 39_500, 'payable_to' => 'brokerage'],
            ['code' => 'tech_fee', 'name' => 'Technology fee', 'calc' => 'percent', 'amount' => '1', 'payable_to' => 'brokerage'],
        ],
    ];

    $agent = (new CommissionCalculator)->calculate(side(1_000_000), plan($rules))->agents[0];

    // 10,000 − 6% franchise (600) = 9,400; company 20% = 1,880; agent 7,520; − 395 − 75.20 = 7,049.80
    expect($agent->preSplit[0]->amount->cents)->toBe(60_000)
        ->and($agent->preSplit[0]->payableTo)->toBe('franchisor')
        ->and($agent->companyDollar->cents)->toBe(188_000)
        ->and($agent->agentGross->cents)->toBe(752_000)
        ->and($agent->postSplit[1]->amount->cents)->toBe(7_520)
        ->and($agent->agentNet->cents)->toBe(704_980);
});

it('uses each co-agent\'s own plan and keeps every cent', function (): void {
    $plans = [7 => new ResolvedPlan(CommissionRules::fromArray(EIGHTY_TWENTY), 'Standard'),
        8 => new ResolvedPlan(CommissionRules::fromArray(['split' => ['mode' => 'percentage', 'agent_percent' => '90']]), 'Senior')];

    $result = (new CommissionCalculator)->calculate(
        side(1_000_001, [new AgentShare(7, '50'), new AgentShare(8, '50')]),
        fn (int $id) => $plans[$id],
    );

    expect($result->agents[0]->share->cents + $result->agents[1]->share->cents)->toBe(1_000_001)
        ->and($result->agents[1]->planName)->toBe('Senior')
        ->and($result->accountedFor()->cents)->toBe(1_000_001);
});

it('applies deal-class rules, and a company-lead rule wins over them', function (): void {
    $rules = EIGHTY_TWENTY + [
        'deal_classes' => ['lease' => ['split' => ['mode' => 'percentage', 'agent_percent' => '60']]],
        'lead_sources' => ['company_lead' => ['split' => ['mode' => 'percentage', 'agent_percent' => '50']]],
    ];
    $calc = new CommissionCalculator;

    $lease = $calc->calculate(side(100_000, extra: ['dealClass' => DealClass::Lease]), plan($rules))->agents[0];
    $leaseFromCompanyLead = $calc->calculate(side(100_000, extra: ['dealClass' => DealClass::Lease, 'leadSource' => 'company_lead']), plan($rules))->agents[0];

    expect($lease->companyDollar->cents)->toBe(40_000)->and($lease->splitSource)->toBe('lease deal')
        ->and($leaseFromCompanyLead->companyDollar->cents)->toBe(50_000)->and($leaseFromCompanyLead->splitSource)->toBe('company lead lead');
});

it('handles a zero GCI', function (): void {
    $agent = (new CommissionCalculator)->calculate(side(0), plan(EIGHTY_TWENTY))->agents[0];

    expect($agent->companyDollar->cents)->toBe(0)->and($agent->agentNet->cents)->toBe(0);
});

it('rejects shares that do not add up to 100% and negative GCI', function (Closure $input): void {
    (new CommissionCalculator)->calculate($input(), plan(EIGHTY_TWENTY));
})->throws(InvalidArgumentException::class)->with([
    'shares' => [fn () => side(100, [new AgentShare(1, '60'), new AgentShare(2, '30')])],
    'negative' => [fn () => side(-100)],
]);

it('rejects invalid rules with clear messages (legacy rule 5 included)', function (array $rules, string $path): void {
    try {
        CommissionRules::fromArray($rules);
        test()->fail('Expected invalid rules.');
    } catch (InvalidRules $e) {
        expect($e->errors)->toHaveKey($path);
    }
})->with([
    'unknown rule' => [EIGHTY_TWENTY + ['bonus' => 1], 'bonus'],
    'percent over 100' => [['split' => ['mode' => 'percentage', 'agent_percent' => '120']], 'split.agent_percent'],
    'float-like percent' => [['split' => ['mode' => 'percentage', 'agent_percent' => '80.123456']], 'split.agent_percent'],
    'bands not rising' => [['split' => ['mode' => 'flat_fee', 'bands' => [['up_to_price_cents' => 500, 'fee_cents' => 1], ['up_to_price_cents' => 400, 'fee_cents' => 1], ['up_to_price_cents' => null, 'fee_cents' => 1]]]], 'split.bands.1.up_to_price_cents'],
    'revenue share basis' => [EIGHTY_TWENTY + ['revenue_share' => ['basis' => 'profit']], 'revenue_share.basis'],
    'unknown deal class' => [EIGHTY_TWENTY + ['deal_classes' => ['timeshare' => ['split' => EIGHTY_TWENTY['split']]]], 'deal_classes.timeshare'],
]);

it('accounts for every cent across 2,000 random deals', function (): void {
    mt_srand(20261114);
    $calc = new CommissionCalculator;

    for ($i = 0; $i < 2000; $i++) {
        $agentPct = (string) mt_rand(0, 100).(mt_rand(0, 1) ? '.'.mt_rand(0, 9999) : '');
        $agentPct = bccomp($agentPct, '100', 4) > 0 ? '100' : $agentPct;
        $rules = ['split' => ['mode' => 'percentage', 'agent_percent' => $agentPct],
            'pre_split' => [['code' => 'royalty', 'name' => 'Royalty', 'calc' => 'percent', 'amount' => (string) mt_rand(0, 8), 'payable_to' => 'franchisor']],
            'post_split' => [['code' => 'tx', 'name' => 'Transaction', 'calc' => 'flat', 'amount' => mt_rand(0, 60_000), 'payable_to' => 'brokerage']]];
        $first = mt_rand(1, 99);
        $agents = mt_rand(0, 1) ? [new AgentShare(1)] : [new AgentShare(1, (string) $first), new AgentShare(2, (string) (100 - $first))];
        $gci = mt_rand(0, 5_000_000);

        $result = $calc->calculate(side($gci, $agents, extra: ['outsideReferralPercent' => (string) mt_rand(0, 35)]), plan($rules));

        expect($result->accountedFor()->cents)->toBe($gci);
        foreach ($result->agents as $agent) {
            expect($agent->agentNet->isNegative())->toBeFalse()->and($agent->companyDollar->isNegative())->toBeFalse();
        }
    }
});
