<?php

declare(strict_types=1);

namespace Modules\Movewise;

/**
 * The true monthly cost of a home (specs/movewise/00 §4.1): principal and interest, taxes, insurance, HOA and
 * typical utilities, each line saying what it assumes. They are estimates the visitor can change on the page.
 */
final class MonthlyCost
{
    /**
     * @return array{total: int, lines: list<array{key: string, label: string, amount: int|null, note: string}>, assumptions: array{rate: float, down: int, tax: float, insurance: float, utilities: int}}
     */
    public static function for(int $price, string $state, ?int $downPayment = null): array
    {
        $rate = (float) config('movewise.mortgage_rate', 6.5);
        // A down payment in dollars (from the visitor) or the usual 10%.
        $down = $downPayment !== null && $price > 0 ? (int) max(0, min(100, round($downPayment * 100 / $price))) : (int) config('movewise.down_payment', 10);
        $tax = (float) (config('movewise.tax_rate')[$state] ?? 1.0);
        $insurance = (float) (config('movewise.insurance_rate')[$state] ?? 0.9);
        $utilities = (int) config('movewise.utilities', 280);
        $loan = $downPayment !== null ? max(0, $price - $downPayment) : $price * (1 - $down / 100);
        $r = $rate / 100 / 12;
        $n = 360;
        $pi = $r > 0 ? $loan * $r / (1 - (1 + $r) ** -$n) : $loan / $n;
        $lines = [
            ['key' => 'principal', 'label' => 'Loan payment', 'amount' => (int) round($pi), 'note' => sprintf('%d%% down, 30-year fixed at %s%%', $down, rtrim(rtrim(number_format($rate, 2), '0'), '.'))],
            ['key' => 'tax', 'label' => 'Property tax', 'amount' => (int) round($price * $tax / 100 / 12), 'note' => sprintf('About %s%% of the value a year, typical in %s; the county sets the real bill', rtrim(rtrim(number_format($tax, 2), '0'), '.'), $state)],
            ['key' => 'insurance', 'label' => 'Home insurance', 'amount' => (int) round($price * $insurance / 100 / 12), 'note' => 'A typical policy; flood cover is extra where needed'],
            ['key' => 'hoa', 'label' => 'HOA dues', 'amount' => null, 'note' => 'Not in the MLS data; ask the listing agent'],
            ['key' => 'utilities', 'label' => 'Utilities', 'amount' => $utilities, 'note' => 'Typical electricity, water and gas for a family home'],
        ];
        if ($down < 20) {
            array_splice($lines, 3, 0, [['key' => 'pmi', 'label' => 'Mortgage insurance', 'amount' => (int) round($loan * 0.005 / 12), 'note' => 'Usually needed with less than 20% down, until you reach 20%']]);
        }

        return ['total' => array_sum(array_map(fn (array $l): int => (int) ($l['amount'] ?? 0), $lines)), 'lines' => $lines,
            'assumptions' => ['rate' => $rate, 'down' => $down, 'tax' => $tax, 'insurance' => $insurance, 'utilities' => $utilities]];
    }
}
