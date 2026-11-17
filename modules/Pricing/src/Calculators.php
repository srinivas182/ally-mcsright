<?php

declare(strict_types=1);

namespace Modules\Pricing;

/**
 * Seller net sheet and buyer cost estimate (CM1, specs/listings/01). Plain arithmetic on figures the agent can see and
 * change; the defaults come from Defaults (by state, marked "review"). Commissions are always shown as negotiable,
 * and paying the buyer's agent is the seller's choice. Estimates only: the title company and lender give the real
 * figures.
 */
final class Calculators
{
    /**
     * What the seller walks away with at one price.
     *
     * @param  array{payoff_cents?: int, listing_pct?: float, buyer_pct?: float, closing_pct?: float, repairs_cents?: int, concessions_cents?: int}  $in
     * @return array{price_cents: int, net_cents: int, lines: list<array{key: string, label: string, cents: int, note: string}>}
     */
    public static function net(int $price, array $in): array
    {
        $pct = fn (string $k, float $default): float => max(0.0, min(10.0, (float) ($in[$k] ?? $default)));
        $listing = $pct('listing_pct', 3.0);
        $buyer = $pct('buyer_pct', 0.0);
        $closing = $pct('closing_pct', 1.2);
        $lines = [
            ['key' => 'payoff', 'label' => 'Mortgage payoff', 'cents' => -max(0, (int) ($in['payoff_cents'] ?? 0)), 'note' => 'From the seller\'s lender statement.'],
            ['key' => 'listing', 'label' => 'Listing brokerage commission', 'cents' => -(int) round($price * $listing / 100), 'note' => self::pct($listing).' of the price. Negotiable.'],
            ['key' => 'buyer', 'label' => 'Buyer\'s agent compensation', 'cents' => -(int) round($price * $buyer / 100),
                'note' => $buyer > 0 ? self::pct($buyer).' of the price, if the seller chooses to offer it. Negotiable.' : 'Not offered (the seller\'s choice).'],
            ['key' => 'closing', 'label' => 'Closing costs', 'cents' => -(int) round($price * $closing / 100), 'note' => 'About '.self::pct($closing).': title, escrow and recording. Your title company confirms.'],
            ['key' => 'repairs', 'label' => 'Repairs', 'cents' => -max(0, (int) ($in['repairs_cents'] ?? 0)), 'note' => 'Agreed after inspection.'],
            ['key' => 'concessions', 'label' => 'Help with the buyer\'s costs', 'cents' => -max(0, (int) ($in['concessions_cents'] ?? 0)), 'note' => 'Seller concessions, if agreed.'],
        ];

        return ['price_cents' => $price, 'net_cents' => $price + array_sum(array_column($lines, 'cents')), 'lines' => $lines];
    }

    /**
     * Cash to close and the monthly payment for a buyer.
     *
     * @param  array{price_cents: int, down_pct?: float, loan?: string, rate?: float, years?: int, tax_pct?: float, insurance_pct?: float, hoa_cents?: int, closing_pct?: float, seller_credit_cents?: int}  $in
     * @return array{price_cents: int, loan: string, down_cents: int, loan_cents: int, upfront_fee_cents: int, monthly: array<string, int>, monthly_total_cents: int, closing_cents: int, cash_to_close_cents: int, notes: list<string>}
     */
    public static function buyer(array $in): array
    {
        $price = max(0, (int) $in['price_cents']);
        $loanType = in_array($in['loan'] ?? '', ['conventional', 'fha', 'va'], true) ? (string) $in['loan'] : 'conventional';
        $rules = (array) config("pricing.loans.{$loanType}");
        $down = max((float) $rules['min_down'], min(100.0, (float) ($in['down_pct'] ?? 20)));
        $downCents = (int) round($price * $down / 100);
        $base = $price - $downCents;
        $upfront = (int) round($base * (float) $rules['upfront_pct'] / 100); // FHA upfront MIP or VA funding fee, financed
        $loan = $base + $upfront;
        $rate = max(0.0, min(20.0, (float) ($in['rate'] ?? config('pricing.interest_rate')))) / 100 / 12;
        $n = max(1, (int) ($in['years'] ?? config('pricing.loan_years'))) * 12;
        $pi = $loan === 0 ? 0 : (int) round($rate > 0 ? $loan * $rate / (1 - (1 + $rate) ** -$n) : $loan / $n);
        $pmi = $down < (float) $rules['pmi_until_down'] ? (int) round($loan * (float) $rules['pmi_pct'] / 100 / 12) : 0;
        $monthly = ['principal_interest' => $pi, 'taxes' => (int) round($price * max(0, (float) ($in['tax_pct'] ?? 1.1)) / 100 / 12),
            'insurance' => (int) round($price * max(0, (float) ($in['insurance_pct'] ?? 0.5)) / 100 / 12), 'mortgage_insurance' => $pmi, 'hoa' => max(0, (int) ($in['hoa_cents'] ?? 0))];
        $closing = (int) round($price * max(0.0, min(10.0, (float) ($in['closing_pct'] ?? 2.5))) / 100);
        $credit = min($closing, max(0, (int) ($in['seller_credit_cents'] ?? 0)));
        $notes = ['An estimate to plan with, not a loan offer: your lender gives the real figures.',
            'Closing costs include lender fees, title, and prepaid taxes and insurance (about '.self::pct((float) ($in['closing_pct'] ?? 2.5)).').'];
        if ($down > (float) ($in['down_pct'] ?? 20) + 0.001) {
            $notes[] = strtoupper($loanType).' loans need at least '.self::pct((float) $rules['min_down']).' down.';
        }
        if ($upfront > 0) {
            $notes[] = $loanType === 'fha' ? 'Includes the FHA upfront mortgage insurance, added to the loan.' : 'Includes the VA funding fee, added to the loan (some veterans are exempt).';
        }

        return ['price_cents' => $price, 'loan' => $loanType, 'down_cents' => $downCents, 'loan_cents' => $loan, 'upfront_fee_cents' => $upfront, 'monthly' => $monthly,
            'monthly_total_cents' => array_sum($monthly), 'closing_cents' => $closing, 'cash_to_close_cents' => $downCents + $closing - $credit, 'notes' => $notes];
    }

    public static function pct(float $p): string
    {
        return rtrim(rtrim(number_format($p, 2), '0'), '.').'%';
    }
}
