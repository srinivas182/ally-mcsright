<?php

declare(strict_types=1);

namespace Modules\AI\Security;

/**
 * Removes sensitive personal data from text before it is sent to any AI provider
 * (specs/ai/01): Social Security numbers, payment card numbers (Luhn-checked), labelled bank
 * routing/account numbers and labelled dates of birth. Each value becomes a numbered
 * placeholder such as [REDACTED_SSN_1].
 */
final class Redactor
{
    /** @var array<string, int> */
    private array $counters = [];

    /** @var array<string, string> placeholder => original */
    private array $map = [];

    public function redact(string $text): string
    {
        $text = (string) preg_replace_callback(
            '/\b(?!000|666|9\d\d)\d{3}-(?!00)\d{2}-(?!0000)\d{4}\b/',
            fn (array $m): string => $this->placeholder('SSN', $m[0]),
            $text,
        );

        $text = (string) preg_replace_callback(
            '/\b(?:SSN|social security(?: number)?)\s*[:#]?\s*(\d{9})\b/i',
            fn (array $m): string => str_replace($m[1], $this->placeholder('SSN', $m[1]), $m[0]),
            $text,
        );

        $text = (string) preg_replace_callback(
            '/\b(?:\d[ -]?){12,18}\d\b/',
            function (array $m): string {
                $digits = (string) preg_replace('/\D/', '', $m[0]);

                return self::luhn($digits) ? $this->placeholder('CARD', $m[0]) : $m[0];
            },
            $text,
        );

        $text = (string) preg_replace_callback(
            '/\b(routing|account|acct)(?:\s*(?:number|no\.?|#))?\s*[:#]?\s*(\d{6,17})\b/i',
            fn (array $m): string => str_replace($m[2], $this->placeholder('BANK', $m[2]), $m[0]),
            $text,
        );

        return (string) preg_replace_callback(
            '/\b(date of birth|DOB|birth ?date)\s*[:\-]?\s*(\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4})/i',
            fn (array $m): string => str_replace($m[2], $this->placeholder('DOB', $m[2]), $m[0]),
            $text,
        );
    }

    /**
     * @return array<string, string> placeholder => original value, for this request only
     */
    public function map(): array
    {
        return $this->map;
    }

    private function placeholder(string $kind, string $original): string
    {
        $existing = array_search($original, $this->map, true);
        if ($existing !== false) {
            return $existing;
        }

        $this->counters[$kind] = ($this->counters[$kind] ?? 0) + 1;
        $placeholder = "[REDACTED_{$kind}_{$this->counters[$kind]}]";
        $this->map[$placeholder] = $original;

        return $placeholder;
    }

    private static function luhn(string $digits): bool
    {
        $length = strlen($digits);
        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum = 0;
        $double = false;
        for ($i = $length - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
