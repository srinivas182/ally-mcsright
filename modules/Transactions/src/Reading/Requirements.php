<?php

declare(strict_types=1);

namespace Modules\Transactions\Reading;

/**
 * Documents a file needs because of what its documents say (an HOA mentioned → HOA documents; a home built
 * before 1978 → lead-based paint disclosure). Each requirement cites the page that triggered it. Deterministic
 * rules, so the same file always gets the same list.
 */
final class Requirements
{
    /**
     * label => [trigger pattern, checklist words that mean it is already covered, reason].
     *
     * @var array<string, array{0: string, 1: list<string>, 2: string}>
     */
    private const RULES = [
        'HOA documents' => ['/\b(homeowners?\'?|property owners?\'?|condominium owners?\'?) association\b|\bHOA\b/i', ['hoa', 'association'], 'mentions a homeowners association'],
        'Condominium documents' => ['/\bcondominium\b/i', ['condo'], 'mentions a condominium'],
        'Well and septic inspection' => ['/\b(private|water) well\b|\bseptic (system|tank)\b/i', ['well', 'septic'], 'mentions a well or septic system'],
        'Short-sale lender approval' => ['/\bshort sale\b/i', ['short sale', 'lender approval'], 'mentions a short sale'],
        'FHA/VA amendatory clause' => ['/\b(FHA|VA) (loan|financing|appraised value)\b|\bVeterans Affairs\b/i', ['fha', 'va ', 'amendatory'], 'mentions FHA or VA financing'],
        'Repair invoices or receipts' => ['/\b(seller|buyer) (shall|will|agrees to) (complete|make|repair)\b|\brepairs? (to be )?(completed|made) by\b/i', ['repair', 'invoice', 'receipt'], 'commits to repairs'],
    ];

    /**
     * @param  list<string>  $pages
     * @param  list<string>  $covered  labels of checklist items and types of documents already in the file
     * @return list<array{label: string, page: int, because: string}>
     */
    public static function find(array $pages, array $covered): array
    {
        $have = mb_strtolower(implode(' | ', $covered));
        $out = [];
        foreach (self::RULES as $label => [$pattern, $words, $reason]) {
            if (self::covered($have, $words)) {
                continue;
            }
            foreach ($pages as $i => $text) {
                if (preg_match($pattern, $text) === 1) {
                    $out[] = ['label' => $label, 'page' => $i + 1, 'because' => $reason];

                    break;
                }
            }
        }
        // Homes built before 1978 need the federal lead-based paint disclosure.
        if (! self::covered($have, ['lead'])) {
            foreach ($pages as $i => $text) {
                if (preg_match('/\b(year built|built in)\D{0,12}(1[89]\d\d)\b/i', $text, $m) === 1 && (int) $m[2] < 1978) {
                    $out[] = ['label' => 'Lead-based paint disclosure', 'page' => $i + 1, 'because' => "says the home was built in {$m[2]}"];

                    break;
                }
                if (preg_match('/\bbuilt (before|prior to) 1978\b/i', $text) === 1 && preg_match('/\b(is|was) (not )?built (before|prior to) 1978\b/i', $text, $m2) === 1 && ! str_contains(mb_strtolower($m2[0]), 'not')) {
                    $out[] = ['label' => 'Lead-based paint disclosure', 'page' => $i + 1, 'because' => 'says the home was built before 1978'];

                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $words
     */
    private static function covered(string $have, array $words): bool
    {
        foreach ($words as $w) {
            if (str_contains($have, $w)) {
                return true;
            }
        }

        return false;
    }
}
