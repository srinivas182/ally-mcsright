<?php

declare(strict_types=1);

namespace Modules\Transactions\Reading;

use Modules\Forms\Guard;

/**
 * Sensitive numbers in a document: Social Security numbers, bank account and routing numbers, dates of birth,
 * and wiring instructions. Used to warn before sharing and to make the safe copy with them blacked out.
 */
final class Sensitive
{
    /**
     * @param  list<string>  $pages
     * @return array{ssn: int, bank: int, dob: int, wire: bool}
     */
    public static function scan(array $pages): array
    {
        $text = implode("\n", $pages);
        // Dashed numbers anywhere, plus undashed 9-digit numbers labelled as a Social Security number.
        $ssn = preg_match_all('/(?<![\d-])(?!000|666|9\d\d)\d{3}-(?!00)\d{2}-(?!0000)\d{4}(?![\d-])/', $text)
            + preg_match_all('/\b(?:SSN|social security(?: number)?|soc\.? sec\.?)\D{0,20}(?<![\d-])\d{9}(?![\d-])/i', $text);
        $bank = preg_match_all('/\b(account|acct|routing|ABA)\b[^\n\d]{0,25}\d[\d -]{5,20}\d/i', $text);
        $dob = preg_match_all('/\b(date of birth|DOB|birth ?date)\b[^\n\d]{0,10}\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4}/i', $text);
        $wire = false;
        try {
            Guard::noWiringInstructions($text, 'document');
        } catch (\Throwable) {
            $wire = true;
        }

        return ['ssn' => (int) $ssn, 'bank' => (int) $bank, 'dob' => (int) $dob, 'wire' => $wire];
    }

    /**
     * Bank accounts named in wiring instructions: routing number and the account's last four digits.
     *
     * @return list<array{routing: ?string, last4: string}>
     */
    public static function accounts(string $text): array
    {
        $out = [];
        preg_match_all('/\b(?:account|acct)\.?\s*(?:number|no\.?|#)?\s*:?\s*(\d[\d -]{4,20}\d)/i', $text, $accounts, PREG_OFFSET_CAPTURE);
        preg_match('/\b(?:routing|ABA)\s*(?:number|no\.?|#)?\s*:?\s*(\d{9})\b/i', $text, $routing);
        foreach ($accounts[1] as [$number]) {
            $digits = (string) preg_replace('/\D/', '', $number);
            if (strlen($digits) >= 6) {
                $out[] = ['routing' => $routing[1] ?? null, 'last4' => substr($digits, -4)];
            }
        }

        return array_values(array_unique($out, SORT_REGULAR));
    }

    /**
     * Which words (in reading order) to black out: the number itself, never the label.
     *
     * @param  list<string>  $words
     * @return list<int> indexes into $words
     */
    public static function wordsToHide(array $words): array
    {
        $hide = [];
        foreach ($words as $i => $w) {
            $clean = trim($w, '.,;:()[]');
            // The label must come just before the number: look back up to 3 words, stopping at another number.
            $labels = [];
            for ($j = $i - 1; $j >= 0 && $j >= $i - 3 && preg_match('/\d/', $words[$j]) !== 1; $j--) {
                $labels[] = $words[$j];
            }
            $before = mb_strtolower(implode(' ', $labels));
            $digits = (string) preg_replace('/\D/', '', $clean);
            $isSsn = preg_match('/^(?!000|666|9\d\d)\d{3}-(?!00)\d{2}-(?!0000)\d{4}$/', $clean) === 1
                || (strlen($digits) === 9 && preg_match('/\b(ssn|social|security)\b/', $before) === 1);
            $isBank = strlen($digits) >= 6 && preg_match('/^[\d -]+$/', $clean) === 1 && preg_match('/\b(account|acct|routing|aba)\b/', $before) === 1;
            $isDob = preg_match('/^\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4}$/', $clean) === 1 && preg_match('/\b(birth|dob)\b/', $before) === 1;
            if ($isSsn || $isBank || $isDob) {
                $hide[] = $i;
            }
        }

        return $hide;
    }
}
