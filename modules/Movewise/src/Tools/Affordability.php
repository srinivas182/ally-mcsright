<?php

declare(strict_types=1);

namespace Modules\Movewise\Tools;

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
            sprintf('Your income is about $%s a month before tax.', number_format($monthly)),
            sprintf('Lenders usually keep the home payment under 28%% of that: $%s.', number_format($front)),
            sprintf('And all debts, the home included, under 36%%: $%s, minus your $%s of other debts, leaves $%s.', number_format($monthly * 0.36), number_format(max(0, $monthlyDebts)), number_format(max(0, $back))),
            sprintf('The smaller of the two, $%s a month, is your comfortable home payment.', number_format($budget)),
            sprintf('With $%s down, a home of about $%s has a payment (loan, tax, insurance) that fits.', number_format($downPayment), number_format($price)),
        ]];
    }
}
