<?php

declare(strict_types=1);

namespace Modules\Compensation\Calculator;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Closure;
use InvalidArgumentException;
use LogicException;
use Modules\Compensation\Enums\SplitMode;
use Modules\Compensation\Rules\Deduction;
use Modules\Compensation\Rules\Percent;
use Modules\Compensation\Rules\Split;
use Modules\Ledger\Money\Money;

/**
 * Pure, deterministic commission calculation for one deal side (specs/compensation/01).
 *
 * Order: outside referral off the top → shares between co-agents → for each agent, on their own
 * plan: deal-class / lead-source split → pre-split deductions → split → post-split deductions.
 * Company dollar is rounded half-even and the agent always receives the remainder, so every part
 * adds up exactly to the GCI; the calculator verifies this before returning.
 * Caps and post-cap fees arrive in Sprint 4, team overrides in Sprint 5.
 */
final class CommissionCalculator
{
    /**
     * @param  Closure(int $userId, CarbonImmutable $closingDate): ResolvedPlan  $planFor
     */
    public function calculate(DealSideInput $input, Closure $planFor): SideCalculation
    {
        $this->validate($input);
        $gci = $input->gci;
        $steps = ["GCI for this side: {$gci->format()}."];

        $referral = Money::zero($gci->currency);
        if ($input->outsideReferralPercent !== null && BigDecimal::of($input->outsideReferralPercent)->isPositive()) {
            $referral = $gci->percentage($input->outsideReferralPercent);
            $steps[] = "Outside referral fee {$input->outsideReferralPercent}% = {$referral->format()}, paid to the referring brokerage.";
        }

        $remaining = $gci->subtract($referral);
        $ratios = [];
        foreach ($input->agents as $i => $agent) {
            $ratios[$i] = $agent->sharePercent;
        }
        $shares = count($input->agents) === 1 ? [0 => $remaining] : $remaining->allocate($ratios);

        $agents = [];
        foreach ($input->agents as $i => $agent) {
            $agents[] = $this->forAgent($input, $agent, $shares[$i], $planFor($agent->userId, $input->closingDate), count($input->agents) > 1);
        }

        $result = new SideCalculation($gci, $referral, $agents, $steps);

        if (! $result->accountedFor()->equals($gci)) {
            throw new LogicException('Commission parts do not add up to the GCI.');
        }

        return $result;
    }

    private function forAgent(DealSideInput $input, AgentShare $agent, Money $share, ResolvedPlan $plan, bool $coAgents): AgentCalculation
    {
        $steps = [];
        $currency = $share->currency;
        $steps[] = $coAgents
            ? "Agent {$agent->userId} ({$plan->planName}): share {$agent->sharePercent}% = {$share->format()}."
            : "Agent {$agent->userId} ({$plan->planName}): full side {$share->format()}.";

        [$split, $source] = $this->chooseSplit($input, $plan);
        if ($source !== 'plan') {
            $steps[] = "Split from the {$source} rule.";
        }

        // Pre-split deductions (franchise, royalty, brokerage referral) come off before the split.
        $base = $share;
        $pre = [];
        foreach ($plan->rules->preSplit as $deduction) {
            $amount = $this->deductionAmount($deduction, $base, $agent->sharePercent, $currency);
            $base = $base->subtract($amount);
            $pre[] = new DeductionLine($deduction->code, $deduction->name, $amount, $deduction->payableTo);
            $steps[] = "Before the split: {$deduction->name} ".$this->describe($deduction)." = {$amount->format()}.";
        }

        [$company, $splitStep] = $this->companyDollar($split, $base, $input, $agent);
        $agentGross = $base->subtract($company);
        $steps[] = $splitStep;
        $steps[] = "Agent side before fees: {$agentGross->format()}.";

        // Post-split deductions (transaction, E&O, technology) come off the agent's side.
        $net = $agentGross;
        $post = [];
        foreach ($plan->rules->postSplit as $deduction) {
            $amount = $this->deductionAmount($deduction, $net, $agent->sharePercent, $currency, percentOf: $agentGross);
            $net = $net->subtract($amount);
            $post[] = new DeductionLine($deduction->code, $deduction->name, $amount, $deduction->payableTo);
            $steps[] = "After the split: {$deduction->name} ".$this->describe($deduction)." = {$amount->format()}.";
        }

        $steps[] = "Agent receives {$net->format()}.";

        return new AgentCalculation($agent->userId, $plan->planName, $plan->planVersionId, $share, $pre, $base, $source,
            $company, $agentGross, $post, $net, $steps);
    }

    /**
     * A lead-source rule wins (company leads), then the deal class (leases, referral-only…), else the plan.
     *
     * @return array{0: Split, 1: string}
     */
    private function chooseSplit(DealSideInput $input, ResolvedPlan $plan): array
    {
        if ($input->leadSource !== null && isset($plan->rules->leadSources[$input->leadSource])) {
            return [$plan->rules->leadSources[$input->leadSource], str_replace('_', ' ', $input->leadSource).' lead'];
        }

        if (isset($plan->rules->dealClasses[$input->dealClass->value])) {
            return [$plan->rules->dealClasses[$input->dealClass->value], str_replace('_', ' ', $input->dealClass->value).' deal'];
        }

        return [$plan->rules->split, 'plan'];
    }

    /**
     * @return array{0: Money, 1: string}
     */
    private function companyDollar(Split $split, Money $base, DealSideInput $input, AgentShare $agent): array
    {
        if ($base->isZero() || $base->isNegative()) {
            return [Money::zero($base->currency), 'Nothing left to split, so no company dollar.'];
        }

        switch ($split->mode) {
            case SplitMode::Percentage:
                $companyPercent = Percent::complement((string) $split->agentPercent);
                $company = $base->percentage($companyPercent);

                return [$company, "Split {$split->agentPercent}/{$companyPercent}: company {$companyPercent}% of {$base->format()} = {$company->format()}."];

            case SplitMode::FlatFee:
                $band = $this->band($split, $input->salePriceCents);
                $fee = Money::of($band, $base->currency)->percentage($agent->sharePercent);
                $company = $fee->greaterThan($base) ? $base : $fee;
                $note = $fee->greaterThan($base) ? ' (limited to what was left)' : '';

                return [$company, 'Flat fee for a sale price of '.Money::of($input->salePriceCents, $base->currency)->format()." = {$company->format()}{$note}."];

            case SplitMode::Tiered:
                $measure = $split->tierMeasure === 'units' ? $agent->ytdUnits : $agent->ytdCompanyDollarCents;
                $tier = $split->tiers[0];
                foreach ($split->tiers as $candidate) {
                    if ($measure >= $candidate['from']) {
                        $tier = $candidate;
                    }
                }
                $companyPercent = Percent::complement($tier['agent_percent']);
                $company = $base->percentage($companyPercent);
                $so = $split->tierMeasure === 'units'
                    ? "{$agent->ytdUnits} closings this cap year"
                    : Money::of($agent->ytdCompanyDollarCents, $base->currency)->format().' company dollar this cap year';

                return [$company, "Tier for {$so}: {$tier['agent_percent']}/{$companyPercent}, company {$companyPercent}% = {$company->format()}."];
        }
    }

    private function band(Split $split, int $salePriceCents): int
    {
        foreach ($split->bands as $band) {
            if ($band['up_to_price_cents'] === null || $salePriceCents <= $band['up_to_price_cents']) {
                return $band['fee_cents'];
            }
        }

        throw new LogicException('Price bands must end with an open band.');
    }

    private function deductionAmount(Deduction $deduction, Money $available, string $sharePercent, string $currency, ?Money $percentOf = null): Money
    {
        $amount = $deduction->calc === 'percent'
            ? ($percentOf ?? $available)->percentage($deduction->amount)
            : Money::of((int) $deduction->amount, $currency)->percentage($sharePercent);

        if ($amount->greaterThan($available)) {
            $amount = $available->isNegative() ? Money::zero($currency) : $available;
        }

        return $amount;
    }

    private function describe(Deduction $deduction): string
    {
        return $deduction->calc === 'percent' ? "{$deduction->amount}%" : '(flat)';
    }

    private function validate(DealSideInput $input): void
    {
        if ($input->agents === []) {
            throw new InvalidArgumentException('A deal side needs at least one agent.');
        }
        if ($input->gci->isNegative()) {
            throw new InvalidArgumentException('GCI cannot be negative.');
        }

        $total = BigDecimal::zero();
        foreach ($input->agents as $agent) {
            if (! Percent::isValid($agent->sharePercent)) {
                throw new InvalidArgumentException('Agent shares are percents from 0 to 100.');
            }
            $total = $total->plus($agent->sharePercent);
        }
        if (! $total->isEqualTo(100)) {
            throw new InvalidArgumentException('Agent shares must add up to 100%.');
        }

        if ($input->outsideReferralPercent !== null && ! Percent::isValid($input->outsideReferralPercent)) {
            throw new InvalidArgumentException('The outside referral fee is a percent from 0 to 100.');
        }
    }
}
