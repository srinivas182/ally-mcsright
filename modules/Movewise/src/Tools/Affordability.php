<?php

declare(strict_types=1);

namespace Modules\Movewise\Tools;

use Modules\Movewise\I18n;
use Modules\Movewise\MonthlyCost;

/**
 * "How much home can I afford?" (specs/movewise/02), explained step by step: the usual lender guide (housing at most
 * 28% of income before tax, and all debts at most 36%), then the highest price whose true monthly cost fits. General
 * information, not a loan offer.
 */
final class Affordability
{
    /**
     * @return array{budget: int, price: int, steps: list<string>, cost: array<string, mixed>}|null
     */
    public static function for(int $yearlyIncome, int $monthlyDebts, int $downPayment, string $state): ?array
    {
        if ($yearlyIncome <= 0) {
            return null;
        }
        $monthly = $yearlyIncome / 12;
        $front = $monthly * 0.28;
        $back = $monthly * 0.36 - max(0, $monthlyDebts);
        $budget = (int) floor(max(0, min($front, $back)));
        // The highest price whose monthly cost (without HOA) fits the budget, to the nearest $1,000.
        $lo = 0;
        $hi = 10_000_000;
        while ($hi - $lo > 1000) {
            $mid = intdiv($lo + $hi, 2);
            $cost = MonthlyCost::for($mid, $state, $downPayment);
            ($cost['total'] - (int) config('movewise.utilities', 280)) <= $budget ? $lo = $mid : $hi = $mid;
        }
        $price = (int) (floor($lo / 1000) * 1000);

        return ['budget' => $budget, 'price' => $price, 'cost' => MonthlyCost::for($price, $state, $downPayment), 'steps' => [
            I18n::t('afford.step1', ['income' => number_format($monthly)]),
            I18n::t('afford.step2', ['front' => number_format($front)]),
            I18n::t('afford.step3', ['all' => number_format($monthly * 0.36), 'debts' => number_format(max(0, $monthlyDebts)), 'left' => number_format(max(0, $back))]),
            I18n::t('afford.step4', ['budget' => number_format($budget)]),
            I18n::t('afford.step5', ['down' => number_format($downPayment), 'price' => number_format($price)]),
        ]];
    }
}
