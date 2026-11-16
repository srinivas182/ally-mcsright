<?php

declare(strict_types=1);

namespace Modules\Movewise\Tools;

use Modules\Movewise\I18n;

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
            ['label' => I18n::t('net.price'), 'amount' => $price, 'note' => I18n::t('net.price_note')],
            ['label' => I18n::t('net.payoff'), 'amount' => -max(0, $payoff), 'note' => I18n::t('net.payoff_note')],
            ['label' => I18n::t('net.listing'), 'amount' => -(int) round($price * max(0, min(10, $listingPct)) / 100), 'note' => I18n::t('net.listing_note', ['pct' => rtrim(rtrim(number_format($listingPct, 2), '0'), '.')])],
            ['label' => I18n::t('net.buyer'), 'amount' => -(int) round($price * max(0, min(10, $buyerPct)) / 100), 'note' => $buyerPct > 0 ? I18n::t('net.buyer_note', ['pct' => rtrim(rtrim(number_format($buyerPct, 2), '0'), '.')]) : I18n::t('net.not_offered')],
            ['label' => I18n::t('net.closing'), 'amount' => -(int) round($price * $closing / 100), 'note' => I18n::t('net.closing_note', ['pct' => (string) $closing, 'state' => $state])],
            ['label' => I18n::t('net.repairs'), 'amount' => -max(0, $repairs), 'note' => I18n::t('net.repairs_note')],
        ];

        return ['net' => array_sum(array_column($lines, 'amount')), 'lines' => $lines];
    }
}
