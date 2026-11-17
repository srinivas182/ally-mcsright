<?php

declare(strict_types=1);

namespace Modules\Sites\Tools;

use Modules\Sites\Models\Site;

/**
 * The website's home calculators (AW1, specs/websites/18): monthly payment, how much home you can afford, and rent
 * versus buy. Plain arithmetic in whole dollars, no AI; every number has a "how we worked it out" line. Defaults come
 * from Settings → Website (the brokerage's own rate and tax figures) or config('sites.mortgage') (marked "review").
 */
final class HomeCalculators
{
    public const KINDS = [
        'mortgage' => ['title' => 'Mortgage payment calculator', 'short' => 'Monthly payment',
            'description' => 'Work out the monthly payment on a home, with principal and interest, property tax, insurance, HOA dues and mortgage insurance.'],
        'affordability' => ['title' => 'How much house can I afford?', 'short' => 'What you can afford',
            'description' => 'See the home price that fits your income, debts and down payment, using the 28/36 guideline lenders start from.'],
        'rent-vs-buy' => ['title' => 'Rent or buy calculator', 'short' => 'Rent or buy',
            'description' => 'Compare what renting and buying cost you over the years you plan to stay, including equity, appreciation and selling costs.'],
    ];

    /**
     * The brokerage's defaults (Settings → Website), falling back to config.
     *
     * @return array{rate: float, down_pct: float, years: int, tax_rate: float, insurance: int, pmi_rate: float, appreciation: float, rent_growth: float}
     */
    public static function defaults(?Site $site): array
    {
        $c = (array) config('sites.mortgage');
        $saved = $site !== null ? ($site->settings['calculators'] ?? []) : [];
        $num = static fn (string $k, float $fallback): float => is_numeric($saved[$k] ?? null) ? (float) $saved[$k] : $fallback;

        return ['rate' => $num('rate', (float) ($c['rate'] ?? 6.5)), 'down_pct' => $num('down_pct', (float) ($c['down_pct'] ?? 20)), 'years' => (int) ($c['years'] ?? 30),
            'tax_rate' => $num('tax_rate', (float) ($c['tax_rate'] ?? 1.2)), 'insurance' => (int) $num('insurance', (float) ($c['insurance'] ?? 150)),
            'pmi_rate' => (float) ($c['pmi_rate'] ?? 0.5), 'appreciation' => (float) ($c['appreciation'] ?? 3.0), 'rent_growth' => (float) ($c['rent_growth'] ?? 3.0)];
    }

    /** Monthly principal and interest on a loan. */
    public static function payment(float $loan, float $ratePct, int $years): float
    {
        $n = max(1, $years * 12);
        $r = $ratePct / 100 / 12;

        return $loan <= 0 ? 0.0 : ($r <= 0 ? $loan / $n : $loan * $r / (1 - (1 + $r) ** -$n));
    }

    /**
     * @return array{price: int, down: int, loan: int, principal_interest: int, tax: int, insurance: int, hoa: int, pmi: int, total: int, notes: list<string>}
     */
    public static function mortgage(int $price, float $downPct, float $ratePct, int $years, float $taxRatePct, int $insurance, int $hoa, float $pmiRatePct): array
    {
        $down = (int) round($price * $downPct / 100);
        $loan = max(0, $price - $down);
        $pi = self::payment($loan, $ratePct, $years);
        $tax = $price * $taxRatePct / 100 / 12;
        $pmi = $downPct < 20 ? $loan * $pmiRatePct / 100 / 12 : 0.0;
        $total = $pi + $tax + $insurance + $hoa + $pmi;
        $notes = ['Loan of $'.number_format($loan).' over '.$years.' years at '.self::pct($ratePct).' interest.', 'Property tax at '.self::pct($taxRatePct).' of the price a year.'];
        if ($pmi > 0) {
            $notes[] = 'Mortgage insurance at '.self::pct($pmiRatePct).' of the loan a year, because the down payment is under 20%. It usually stops once you owe less than 80%.';
        }

        return ['price' => $price, 'down' => $down, 'loan' => $loan, 'principal_interest' => (int) round($pi), 'tax' => (int) round($tax), 'insurance' => $insurance, 'hoa' => $hoa,
            'pmi' => (int) round($pmi), 'total' => (int) round($total), 'notes' => $notes];
    }

    /**
     * The 28/36 guideline: housing up to 28% of gross monthly income, and housing plus other debts up to 36%.
     *
     * @return array{price: int, monthly: int, loan: int, limit: string, notes: list<string>}
     */
    public static function affordability(int $incomeYear, int $debtsMonth, int $down, float $ratePct, int $years, float $taxRatePct, int $insurance, int $hoa): array
    {
        $gross = $incomeYear / 12;
        $front = $gross * 0.28;
        $back = $gross * 0.36 - $debtsMonth;
        $housing = max(0.0, min($front, $back));
        $limit = $back < $front ? 'your other monthly debts' : 'the 28% housing guideline';
        // Price where P&I + tax + insurance + HOA equals the housing budget (tax depends on price, so solve for it).
        $perDollarLoan = self::payment(1.0, $ratePct, $years);
        $taxPerDollar = $taxRatePct / 100 / 12;
        $budget = $housing - $insurance - $hoa;
        $price = $budget <= 0 ? 0 : (int) floor(($budget + $perDollarLoan * $down) / ($perDollarLoan + $taxPerDollar));
        $price = max($price, 0);
        if ($price > 0 && $price < $down) {
            $price = $down; // all cash
        }
        $price = (int) (floor($price / 1000) * 1000);

        return ['price' => $price, 'monthly' => (int) round($housing), 'loan' => max(0, $price - $down), 'limit' => $limit,
            'notes' => ['Housing budget of $'.number_format((int) round($housing)).' a month, set by '.$limit.'.', 'At '.self::pct($ratePct).' over '.$years.' years, with property tax at '.self::pct($taxRatePct).' a year, $'.number_format($insurance).' a month insurance'.($hoa > 0 ? ' and $'.number_format($hoa).' HOA' : '').'.',
                'Lenders look at more than this: credit, savings and the loan type. A lender can give you a pre-approval.']];
    }

    /**
     * Total cost of renting against buying over the years you stay. Buying counts the down payment, closing costs,
     * payments, tax, insurance, upkeep (1% a year) and selling costs (6%), minus the equity you walk away with.
     *
     * @return array{rent_total: int, buy_total: int, better: string, difference: int, equity: int, break_even_year: int|null, notes: list<string>}
     */
    public static function rentVsBuy(int $rent, int $price, float $downPct, float $ratePct, int $years, int $stay, float $taxRatePct, int $insurance, float $appreciationPct, float $rentGrowthPct): array
    {
        $stay = max(1, min(30, $stay));
        $down = $price * $downPct / 100;
        $loan = $price - $down;
        $pi = self::payment($loan, $ratePct, $years);
        $r = $ratePct / 100 / 12;
        $rentTotal = 0.0;
        $buyPaid = $down + $price * 0.03; // closing costs about 3%
        $balance = $loan;
        $breakEven = null;
        for ($y = 1; $y <= $stay; $y++) {
            $rentTotal += $rent * 12 * (1 + $rentGrowthPct / 100) ** ($y - 1);
            $value = $price * (1 + $appreciationPct / 100) ** $y;
            for ($m = 0; $m < 12; $m++) {
                $interest = $balance * $r;
                $balance = max(0.0, $balance - ($pi - $interest));
            }
            $buyPaid += $pi * 12 + $value * $taxRatePct / 100 + $insurance * 12 + $value * 0.01;
            $netBuy = $buyPaid - ($value * 0.94 - $balance);
            if ($breakEven === null && $netBuy < $rentTotal) {
                $breakEven = $y;
            }
        }
        $value = $price * (1 + $appreciationPct / 100) ** $stay;
        $equity = $value * 0.94 - $balance;
        $buyTotal = $buyPaid - $equity;

        return ['rent_total' => (int) round($rentTotal), 'buy_total' => (int) round($buyTotal), 'better' => $buyTotal < $rentTotal ? 'buy' : 'rent',
            'difference' => (int) round(abs($rentTotal - $buyTotal)), 'equity' => (int) round($equity), 'break_even_year' => $breakEven,
            'notes' => ['Rent rising '.self::pct($rentGrowthPct).' a year; the home gaining '.self::pct($appreciationPct).' a year (both are guesses, change them to test).',
                'Buying includes about 3% closing costs, 1% a year upkeep and 6% to sell at the end.', 'It leaves out tax deductions and what your down payment could earn elsewhere.']];
    }

    private static function pct(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3), '0'), '.').'%';
    }
}
