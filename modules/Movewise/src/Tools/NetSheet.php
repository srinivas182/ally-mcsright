<?php

declare(strict_types=1);

namespace Modules\Movewise\Tools;

/**
 * A seller's net sheet (specs/movewise/02): what you would walk away with. Commissions are whatever you agree with
 * your agent (they are negotiable, and paying the buyer's agent is your choice); closing costs are typical for the
 * state. General information; your title company gives the real figures.
 */
final class NetSheet
{
    /** Typical seller closing costs (title policy, escrow, recording), % of the price. */
    public const CLOSING = ['TX' => 1.5, 'LA' => 1.0, 'MS' => 1.0, 'AL' => 1.0];

    /**
     * @return array{net: int, lines: list<array{label: string, amount: int, note: string}>}|null
     */
    public static function for(int $price, int $payoff, float $listingPct, float $buyerPct, int $repairs, string $state): ?array
    {
        if ($price <= 0) {
            return null;
        }
        $closing = (float) (self::CLOSING[$state] ?? 1.2);
        $lines = [
            ['label' => 'Sale price', 'amount' => $price, 'note' => 'What the buyer pays'],
            ['label' => 'Mortgage payoff', 'amount' => -max(0, $payoff), 'note' => 'From your lender\'s payoff statement'],
            ['label' => 'Your listing agent', 'amount' => -(int) round($price * max(0, min(10, $listingPct)) / 100), 'note' => rtrim(rtrim(number_format($listingPct, 2), '0'), '.').'%, as agreed with your agent (negotiable)'],
            ['label' => 'Buyer\'s agent (if you offer it)', 'amount' => -(int) round($price * max(0, min(10, $buyerPct)) / 100), 'note' => $buyerPct > 0 ? rtrim(rtrim(number_format($buyerPct, 2), '0'), '.').'%: your choice, often agreed in the offer' : 'Not offered'],
            ['label' => 'Closing costs', 'amount' => -(int) round($price * $closing / 100), 'note' => 'About '.$closing.'% in '.$state.': title, escrow and recording'],
            ['label' => 'Repairs and credits to the buyer', 'amount' => -max(0, $repairs), 'note' => 'Your estimate'],
        ];

        return ['net' => array_sum(array_column($lines, 'amount')), 'lines' => $lines];
    }
}
