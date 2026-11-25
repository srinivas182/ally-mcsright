<?php

declare(strict_types=1);

namespace Modules\Transactions;

use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Item;

/**
 * DA1: one brokerage on both sides of a deal.
 *
 * The dual deal is the seller (listing) side; a linked buyer-side deal is made with it, so each side has its own
 * commission, its own agents (each paid on their own plan) and counts as a side in reports. Status changes are made
 * on the dual deal and carried to the buyer side.
 *
 * State rules are set by RightAlly and cannot be changed by a brokerage. A brokerage may be on both sides in every
 * state; what differs is whether ONE agent may represent both. In the states below that is not allowed (or only
 * under another name), so the deal page warns when the same agent is on both sides. Source: HomeLight's summary of
 * state rules (as of 2021); RightAlly HQ keeps this list current.
 */
final class DualAgency
{
    /** The checklist item that must have the signed consent before the deal goes under contract. */
    public const CONSENT_ITEM = 'Dual agency consent';

    /** @var array<string, string> state => what to do instead when one agent would be on both sides */
    public const ONE_AGENT_NOT_ALLOWED = [
        'AK' => 'Alaska does not allow one agent to represent both sides. Use a second agent for the buyer (designated agency), with written consent.',
        'CO' => 'Colorado does not allow one agent to represent both sides. Act as a transaction broker, or use a second agent for the buyer.',
        'FL' => 'Florida does not allow dual agency. Act as a transaction broker, or use a second agent for the buyer.',
        'KS' => 'Kansas does not allow dual agency. Act as a transaction broker, or use a second agent for the buyer.',
        'MD' => 'Maryland does not allow one agent to represent both sides. Use a second agent for the buyer (intra-company agents), with written consent.',
        'TX' => 'Texas does not allow dual agency. The broker acts as an intermediary with the written consent of both parties (and may appoint a second agent for the buyer).',
        'VT' => 'Vermont does not allow one agent to represent both sides. Use a second agent for the buyer (designated agency), with written consent.',
        'WY' => 'Wyoming does not allow one agent to represent both sides. Act as an intermediary or use a second agent for the buyer, with written consent.',
    ];

    /** The other side of a dual deal (buyer side for the dual deal; the dual deal for its buyer side). */
    public static function other(Deal $deal): ?Deal
    {
        return $deal->paired_deal_id !== null ? Deal::query()->find($deal->paired_deal_id) : null;
    }

    /** The buyer side of a dual deal is changed through the dual deal. */
    public static function isBuyerSide(Deal $deal): bool
    {
        return $deal->type === 'buyer' && $deal->paired_deal_id !== null;
    }

    /** A warning when the same agent is on both sides in a state that does not allow it, or null. */
    public static function warning(Deal $dual, Deals $deals): ?string
    {
        $buyer = self::other($dual);
        $rule = self::ONE_AGENT_NOT_ALLOWED[strtoupper((string) $dual->state_code)] ?? null;
        if ($buyer === null || $rule === null) {
            return null;
        }
        $both = array_intersect(array_column($deals->agents($dual), 'user_id'), array_column($deals->agents($buyer), 'user_id'));

        return $both === [] ? null : 'The same agent is on both sides. '.$rule;
    }

    /** The signed consent is in (sent for review, approved or waived by the brokerage). */
    public static function consentIn(Deal $dual): bool
    {
        $status = Item::query()->where('deal_id', $dual->id)->where('label', self::CONSENT_ITEM)->value('status');

        return $status === null || in_array($status, ['submitted', 'approved', 'waived'], true);
    }
}
