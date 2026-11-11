<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Compensation\Calculator\AgentShare;
use Modules\Compensation\Calculator\CommissionCalculator;
use Modules\Compensation\Calculator\DealSideInput;
use Modules\Compensation\Calculator\ResolvedPlan;
use Modules\Compensation\Exceptions\InvalidRules;
use Modules\Compensation\Rules\CommissionRules;
use Modules\Ledger\Money\Money;

function capPlan(array $extra = []): Closure
{
    $rules = CommissionRules::fromArray(['split' => ['mode' => 'percentage', 'agent_percent' => '80'], 'cap' => ['amount_cents' => 1_600_000]] + $extra);

    return fn () => new ResolvedPlan($rules, 'Capped 80/20');
}

function capSide(int $gci, int $paid = 0, int $feesPaid = 0, string $share = '100'): DealSideInput
{
    return new DealSideInput(Money::of($gci), 30_000_000, CarbonImmutable::parse('2026-11-20'), [new AgentShare(7, $share, $paid, 0, $paid, $feesPaid)]);
}

it('applies the normal split below the cap (legacy rule 11)', function (): void {
    $agent = (new CommissionCalculator)->calculate(capSide(900_000, 500_000), capPlan())->agents[0];

    expect($agent->companyDollar->cents)->toBe(180_000)->and($agent->capped)->toBeFalse()->and($agent->capAfterCents)->toBe(680_000);
});

it('limits company dollar to what is left of the cap on the deal that crosses it', function (): void {
    $result = (new CommissionCalculator)->calculate(capSide(900_000, 1_540_000), capPlan(['post_cap' => ['fee' => ['calc' => 'flat', 'amount' => 25_000]]]));
    $agent = $result->agents[0];

    expect($agent->companyDollar->cents)->toBe(60_000)
        ->and($agent->postCapFee?->cents)->toBe(25_000)
        ->and($agent->agentNet->cents)->toBe(900_000 - 60_000 - 25_000)
        ->and($agent->capped)->toBeTrue()
        ->and($result->accountedFor()->cents)->toBe(900_000)
        ->and(implode(' ', $result->explanation()))->toContain('limited to the remaining $600.00');
});

it('after the cap the agent gets 100% minus the post-cap fee (legacy rule 12)', function (): void {
    $agent = (new CommissionCalculator)->calculate(capSide(1_000_000, 1_600_000), capPlan(['post_cap' => ['fee' => ['calc' => 'percent', 'amount' => '5']]]))->agents[0];

    expect($agent->companyDollar->cents)->toBe(0)
        ->and($agent->postCapFee?->cents)->toBe(50_000)
        ->and($agent->agentNet->cents)->toBe(950_000);
});

it('stops post-cap fees at the yearly post-cap fee cap', function (): void {
    $agent = (new CommissionCalculator)->calculate(
        capSide(1_000_000, 1_600_000, 290_000),
        capPlan(['post_cap' => ['fee' => ['calc' => 'flat', 'amount' => 50_000], 'fee_cap_cents' => 300_000]]),
    )->agents[0];

    expect($agent->postCapFee?->cents)->toBe(10_000)->and($agent->agentNet->cents)->toBe(990_000);
});

it('a percentage post-cap fee applies only to the part of the deal after the cap', function (): void {
    // Company would be 2,000 on 10,000; only 500 of cap room left, so 75% of the deal is after the cap.
    $agent = (new CommissionCalculator)->calculate(capSide(1_000_000, 1_550_000), capPlan(['post_cap' => ['fee' => ['calc' => 'percent', 'amount' => '10']]]))->agents[0];

    expect($agent->companyDollar->cents)->toBe(50_000)->and($agent->postCapFee?->cents)->toBe(75_000);
});

it('validates cap rules', function (array $rules, string $path): void {
    try {
        CommissionRules::fromArray(['split' => ['mode' => 'percentage', 'agent_percent' => '80']] + $rules);
        test()->fail('Expected invalid rules.');
    } catch (InvalidRules $e) {
        expect($e->errors)->toHaveKey($path);
    }
})->with([
    'zero cap' => [['cap' => ['amount_cents' => 0]], 'cap.amount_cents'],
    'post-cap without cap' => [['post_cap' => ['fee' => ['calc' => 'flat', 'amount' => 100]]], 'post_cap'],
    'fixed without date' => [['cap' => ['amount_cents' => 100, 'anchor' => 'fixed']], 'cap.anchor_month_day'],
    'bad post-cap fee' => [['cap' => ['amount_cents' => 100], 'post_cap' => ['fee' => ['calc' => 'flat', 'amount' => '12']]], 'post_cap.fee'],
]);

it('accounts for every cent with caps across 2,000 random deals', function (): void {
    mt_srand(4242);
    $calc = new CommissionCalculator;
    for ($i = 0; $i < 2000; $i++) {
        $rules = ['split' => ['mode' => 'percentage', 'agent_percent' => (string) mt_rand(50, 95)], 'cap' => ['amount_cents' => mt_rand(100_000, 2_000_000)],
            'post_cap' => ['fee' => mt_rand(0, 1) ? ['calc' => 'flat', 'amount' => mt_rand(0, 50_000)] : ['calc' => 'percent', 'amount' => (string) mt_rand(0, 10)], 'fee_cap_cents' => mt_rand(0, 1) ? mt_rand(0, 500_000) : null]];
        $gci = mt_rand(0, 3_000_000);
        $result = $calc->calculate(capSide($gci, mt_rand(0, 2_500_000), mt_rand(0, 400_000)), fn () => new ResolvedPlan(CommissionRules::fromArray($rules), 'P'));

        expect($result->accountedFor()->cents)->toBe($gci)
            ->and($result->agents[0]->agentNet->isNegative())->toBeFalse()
            ->and($result->agents[0]->companyDollar->isNegative())->toBeFalse();
    }
});
