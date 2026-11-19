<?php

declare(strict_types=1);

namespace Modules\Marketing;

/**
 * MK1: what RightAlly's own website (and the AI content desk) never says. Approved by the owner, November 2026:
 *
 * - no other company is named (no comparisons by name, no "switch from X"), so there is nothing to defend or keep
 *   up to date about other companies' prices or features;
 * - RightAlly is never called cheap or the cheapest: it sells on value (everything in one place, one bill, less admin,
 *   data kept safe, we move you).
 *
 * Checked on every save of a website page or post. The list covers the US brokerage software companies people are most
 * likely to name; add to it as needed.
 */
final class ClaimsCheck
{
    /** Other companies' names (lower case, matched as whole words). */
    public const COMPANIES = ['kvcore', 'boldtrail', 'inside real estate', 'lone wolf', 'brokermint', 'skyslope', 'dotloop', 'docusign', 'follow up boss', 'followupboss',
        'boomtown', 'sierra interactive', 'real geeks', 'luxury presence', 'placester', 'lofty', 'chime', 'cinc', 'commissions inc', 'ylopo', 'moxi', 'moxiworks',
        'zillow', 'realtor.com', 'redfin', 'compass', 'exp realty', 'keller williams', 'remax', 're/max', 'paperless pipeline', 'transactiondesk', 'zipform', 'agentimage',
        'wise agent', 'ixact', 'top producer', 'propertybase', 'salesforce', 'hubspot', 'gohighlevel', 'highlevel', 'quickbooks', 'appfolio', 'reesio', 'loft47'];

    /** Price claims we do not make. */
    private const CHEAP = '/\b(cheap|cheaper|cheapest|lowest[- ]price[sd]?|lowest[- ]cost|bargain|rock[- ]bottom|budget option)\b/i';

    /** Why the text cannot be used, or null when it is fine. */
    public static function problem(string $text): ?string
    {
        $lower = mb_strtolower($text);
        foreach (self::COMPANIES as $name) {
            if (preg_match('/(?<![\w])'.preg_quote($name, '/').'(?![\w])/u', $lower) === 1) {
                return 'We don\'t name other companies on RightAlly\'s website ("'.$name.'"). Say what RightAlly does instead.';
            }
        }
        if (preg_match(self::CHEAP, $text, $m) === 1) {
            return 'We don\'t call RightAlly "'.mb_strtolower($m[1]).'". Talk about value instead: everything in one place, one bill, less admin.';
        }

        return null;
    }
}
