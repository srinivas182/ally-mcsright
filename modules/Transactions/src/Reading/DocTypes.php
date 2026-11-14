<?php

declare(strict_types=1);

namespace Modules\Transactions\Reading;

/**
 * Kinds of transaction documents, recognised from their titles, and how each matches a checklist item.
 * Rule-based so it is predictable and testable; the AI only adds facts on top.
 */
final class DocTypes
{
    /**
     * key => [label, title pattern (matched against the first lines of a page), checklist words].
     * More specific types come first (an "amendment to the purchase agreement" is an amendment).
     *
     * @var array<string, array{0: string, 1: string, 2: list<string>}>
     */
    public const TYPES = [
        'counter_offer' => ['Counter-offer', '/\bcounter[\s-]*offer\b/i', ['counter']],
        'amendment' => ['Amendment or addendum to the contract', '/\b(amendment|addendum) to (the )?(purchase|sales?|contract)|\bamendment\b(?!.*\bdisclosure)/i', ['amendment', 'addendum']],
        'hoa_addendum' => ['HOA addendum', '/\b(homeowners?\'?|property owners?\'?) association\b.*\b(addendum|disclosure)|\bHOA addendum\b/i', ['hoa', 'association']],
        'lead_paint' => ['Lead-based paint disclosure', '/\blead[\s-]*based paint\b/i', ['lead']],
        'seller_disclosure' => ["Seller's disclosure", '/\bseller\'?s? (property )?disclosure\b|\bproperty condition disclosure\b/i', ['seller property disclosure', 'seller disclosure', 'property disclosure', 'seller']],
        'inspection_report' => ['Inspection report', '/\b(home |property )?inspection report\b/i', ['inspection']],
        'repair_request' => ['Repair request', '/\brepair (request|amendment|addendum)\b|\brequest for repairs?\b/i', ['repair']],
        'earnest_money' => ['Earnest money receipt', '/\bearnest money (receipt|deposit receipt)\b|\breceipt (for|of) earnest money\b/i', ['earnest']],
        'closing_disclosure' => ['Closing disclosure', '/\bclosing disclosure\b/i', ['closing disclosure', 'settlement']],
        'settlement_statement' => ['Settlement statement', '/\b(alta )?settlement statement\b|\bHUD-1\b/i', ['settlement', 'closing statement']],
        'preapproval' => ['Pre-approval letter', '/\bpre[\s-]*(approval|qualification)\b/i', ['pre-approval', 'preapproval', 'pre approval']],
        'buyer_agreement' => ['Buyer representation agreement', '/\bbuyer (representation|broker(age)?|agency) agreement\b|\bexclusive (right to )?represent\b.*\bbuyer/i', ['buyer agreement', 'buyer representation', 'buyer rep']],
        'listing_agreement' => ['Listing agreement', '/\blisting agreement\b|\bexclusive right to sell\b/i', ['listing agreement']],
        'purchase_agreement' => ['Purchase agreement', '/\b(residential |real estate )?(purchase|sales?) (agreement|contract)\b|\bcontract (to buy|for sale|of sale)\b|\bone to four family\b/i', ['purchase agreement', 'contract', 'purchase']],
        'wire_instructions' => ['Wiring instructions', '/\bwir(e|ing) instructions\b/i', ['wire']],
    ];

    public static function label(?string $type): string
    {
        return $type !== null && isset(self::TYPES[$type]) ? self::TYPES[$type][0] : 'Other document';
    }

    /** The type whose title appears in the first lines of a page, or null. */
    public static function classify(string $pageText): ?string
    {
        $head = implode(' ', array_slice(array_values(array_filter(array_map('trim', explode("\n", $pageText)), fn (string $l): bool => $l !== '')), 0, 6));
        foreach (self::TYPES as $key => [, $pattern]) {
            if (preg_match($pattern, $head) === 1) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Splits pages into documents: a new document starts at a page whose title is a different document type
     * (or the same type again starting at "Page 1 of"). Unrecognised pages belong to the document before them.
     *
     * @param  list<string>  $pages
     * @return list<array{type: ?string, from: int, to: int}>
     */
    public static function segment(array $pages): array
    {
        $segments = [];
        $current = null;
        foreach ($pages as $i => $text) {
            $type = self::classify($text);
            $restart = $type !== null && preg_match('/\bpage 1 of \d+\b/i', $text) === 1;
            if ($current === null) {
                $current = ['type' => $type, 'from' => $i + 1, 'to' => $i + 1];
            } elseif ($type !== null && $current['type'] === null) {
                // Unrecognised leading pages (a cover sheet) belong to the first recognised document.
                $current = ['type' => $type, 'from' => $current['from'], 'to' => $i + 1];
            } elseif ($type !== null && ($type !== $current['type'] || $restart)) {
                $segments[] = $current;
                $current = ['type' => $type, 'from' => $i + 1, 'to' => $i + 1];
            } else {
                $current['to'] = $i + 1;
            }
        }
        if ($current !== null) {
            $segments[] = $current;
        }

        return $segments;
    }

    /**
     * The checklist item that best fits a document type, by the words in the item's label.
     *
     * @param  iterable<object{id: int, label: string}>  $items
     */
    public static function matchItem(?string $type, iterable $items): ?object
    {
        if ($type === null) {
            return null;
        }
        $best = null;
        $bestScore = 0;
        foreach ($items as $item) {
            $label = mb_strtolower((string) $item->label);
            $score = 0;
            foreach (self::TYPES[$type][2] as $word) {
                if (str_contains($label, $word)) {
                    $score += mb_strlen($word);
                }
            }
            if ($score > $bestScore) {
                $best = $item;
                $bestScore = $score;
            }
        }

        return $best;
    }
}
