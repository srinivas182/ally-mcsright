<?php

declare(strict_types=1);

namespace Modules\Recruiting;

use Carbon\CarbonImmutable;
use Modules\Compensation\Calculator\AgentShare;
use Modules\Compensation\Calculator\CommissionCalculator;
use Modules\Compensation\Calculator\DealSideInput;
use Modules\Compensation\Calculator\ResolvedPlan;
use Modules\Compensation\Enums\DealClass;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Enums\Side;
use Modules\Compensation\FeeCatalogue;
use Modules\Compensation\Models\Plan;
use Modules\Ledger\Money\Money;
use Modules\Listings\Models\Listing;
use Modules\Recruiting\Models\Recruit;

/**
 * "What you'd earn here" (RC1, specs/grow/17): the recruit's own closed sides from the last 12 months, run through
 * the brokerage's real commission plan (the same calculator that pays agents), against their current plan as the
 * recruiter understands it (split, cap and per-deal fee). Commission is estimated from the closed price at the
 * brokerage's chosen rate, and every assumption is listed with the result.
 */
final class EarnHere
{
    /** Assumed current plan when the recruiter hasn't entered one. */
    public const DEFAULT_SPLIT = 70.0;

    /**
     * @param  array{deals?: int|null, price_cents?: int|null}  $manual  for a recruit not matched to the MLS
     * @return array{plan: string|null, plans: list<array{id: int, name: string}>, deals: list<array{date: string, side: string, price_cents: int, gci_cents: int, there_cents: int, here_cents: int}>, there_cents: int, here_cents: int, difference_cents: int, assumptions: list<string>, problem: string|null}
     */
    public function compare(Recruit $recruit, array $manual = []): array
    {
        $settings = app(AgentDatabase::class)->settings();
        $plans = array_values(Plan::query()->where('type', PlanType::Commission)->orderBy('name')->get()->filter(fn (Plan $p): bool => $p->latestPublished() !== null)
            ->map(fn (Plan $p): array => ['id' => $p->id, 'name' => $p->name])->all());
        $planId = $recruit->compare_plan_id ?? $settings['compare_plan_id'];
        $plan = ($planId !== null ? Plan::query()->find($planId) : null) ?? Plan::query()->where('type', PlanType::Commission)->where('is_default', true)->first();
        $version = $plan?->latestPublished();
        $out = ['plan' => $plan?->name, 'plans' => $plans, 'deals' => [], 'there_cents' => 0, 'here_cents' => 0, 'difference_cents' => 0, 'assumptions' => [], 'problem' => null];
        if ($plan === null || $version === null) {
            return ['problem' => 'Publish a commission plan first; the comparison uses it.'] + $out;
        }
        $sides = $this->sides($recruit, $manual, $settings['commission_rate']);
        if ($sides === []) {
            return ['problem' => $recruit->mls_agent_id !== null ? 'No closed sides in the last 12 months.' : 'Enter how many deals they do a year and their average price.'] + $out;
        }

        $rules = $version->commissionRules();
        $catalogue = app(FeeCatalogue::class);
        $calculator = app(CommissionCalculator::class);
        $split = $recruit->current_split !== null ? (float) $recruit->current_split : self::DEFAULT_SPLIT;
        $cap = $recruit->current_cap_cents;
        $fee = $recruit->current_fee_cents ?? 0;
        $paidThere = 0;
        $capPaid = 0;
        foreach ($sides as $s) {
            // There: their split until the company has its cap, then 100%; less the per-deal fee.
            $company = (int) round($s['gci_cents'] * (100 - $split) / 100);
            if ($cap !== null) {
                $company = max(0, min($company, $cap - $paidThere));
            }
            $paidThere += $company;
            $there = max(0, $s['gci_cents'] - $company - $fee);
            // Here: the real plan, cap carried from deal to deal.
            [$pre, $post] = $catalogue->resolve($rules->feeCodes, $s['date']);
            $resolved = new ResolvedPlan($rules->withCatalogueFees($pre, $post), $plan->name);
            $result = $calculator->calculate(new DealSideInput(Money::of($s['gci_cents']), $s['price_cents'], $s['date'], [new AgentShare(0, '100', 0, 0, $capPaid, 0)],
                $s['side'], DealClass::Sale), fn (): ResolvedPlan => $resolved);
            $agent = $result->agents[0];
            $capPaid = $agent->capAfterCents > 0 ? $agent->capAfterCents : $capPaid;
            $out['deals'][] = ['date' => $s['date']->toDateString(), 'side' => $s['side'] === Side::Listing ? 'Listing' : 'Buyer', 'price_cents' => $s['price_cents'],
                'gci_cents' => $s['gci_cents'], 'there_cents' => $there, 'here_cents' => $agent->agentNet->cents];
            $out['there_cents'] += $there;
            $out['here_cents'] += $agent->agentNet->cents;
        }
        $out['difference_cents'] = $out['here_cents'] - $out['there_cents'];
        $there = array_filter([
            $recruit->current_split !== null ? "they keep {$split}%" : 'a guess of '.self::DEFAULT_SPLIT.'% to them (add what they told you)',
            $cap !== null ? 'their brokerage takes at most $'.number_format(intdiv($cap, 100)).' a year' : 'no cap',
            $fee > 0 ? '$'.number_format(intdiv($fee, 100)).' a deal in fees' : null,
        ]);
        $out['assumptions'] = [
            $recruit->mls_agent_id !== null ? 'Their '.count($sides).' closed sides in the MLS in the last 12 months.' : 'The deals and average price you entered, spread over the year.',
            'Commission of '.rtrim(rtrim(number_format($settings['commission_rate'] * 100, 2), '0'), '.').'% of the price on each side.',
            'Today: '.implode(', ', $there).'.',
            "Here: \"{$plan->name}\", as it pays agents today, with its fees and cap.",
        ];

        return $out;
    }

    /**
     * @param  array{deals?: int|null, price_cents?: int|null}  $manual
     * @return list<array{date: CarbonImmutable, side: Side, price_cents: int, gci_cents: int}>
     */
    private function sides(Recruit $recruit, array $manual, float $rate): array
    {
        $today = CarbonImmutable::today();
        $agent = $recruit->mlsAgent;
        if ($agent === null) {
            $n = min(100, max(0, (int) ($manual['deals'] ?? 0)));
            $price = max(0, (int) ($manual['price_cents'] ?? 0));
            if ($n === 0 || $price === 0) {
                return [];
            }

            return array_map(fn (int $i): array => ['date' => $today->subYear()->addDays((int) floor(365 * ($i + 0.5) / $n)), 'side' => $i % 2 === 0 ? Side::Listing : Side::Buying,
                'price_cents' => $price, 'gci_cents' => (int) round($price * $rate)], range(0, $n - 1));
        }
        $id = $agent->agent_mls_id;
        $rows = Listing::query()->where('mls_source_id', $agent->mls_source_id)->where('standard_status', 'Closed')
            ->where('close_date', '>=', $today->subYear()->toDateString())
            ->where(fn ($w) => $w->where('list_agent_mls_id', $id)->orWhere('co_list_agent_mls_id', $id)->orWhere('buyer_agent_mls_id', $id))
            ->orderBy('close_date')->get(['list_agent_mls_id', 'co_list_agent_mls_id', 'buyer_agent_mls_id', 'close_price_cents', 'list_price_cents', 'close_date']);
        $sides = [];
        foreach ($rows as $l) {
            $price = (int) ($l->close_price_cents ?? $l->list_price_cents ?? 0);
            $date = CarbonImmutable::parse($l->close_date ?? $today);
            foreach ([[$l->list_agent_mls_id === $id || $l->co_list_agent_mls_id === $id, Side::Listing], [$l->buyer_agent_mls_id === $id, Side::Buying]] as [$mine, $side]) {
                if ($mine && $price > 0) {
                    $sides[] = ['date' => $date, 'side' => $side, 'price_cents' => $price, 'gci_cents' => (int) round($price * $rate)];
                }
            }
        }

        return $sides;
    }
}
