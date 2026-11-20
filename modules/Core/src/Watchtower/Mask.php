<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower;

/**
 * Removes personal data before anything is stored, emailed or sent to Sentry (OB1): email addresses, phone numbers,
 * Social Security numbers, card and account numbers, ids, tokens and quoted values (SQL bindings, input echoed in
 * messages). What is left says what went wrong, never who it happened to.
 */
final class Mask
{
    public static function text(?string $text, int $limit = 2000): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        $text = str_replace(rtrim(base_path(), '/').'/', '', mb_substr($text, 0, $limit * 2));
        $rules = [
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i' => '[email]',
            '/\b\d{3}-\d{2}-\d{4}\b/' => '[ssn]',
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '[id]',
            '/\b[0-9A-HJKMNP-TV-Z]{26}\b/i' => '[id]',                       // ULIDs
            '/\b(?:sk|pk|rk|whsec|ghp|xox[abp])_[A-Za-z0-9_]{8,}\b/' => '[token]', // provider keys
            '/\b[a-z0-9]{32,}\b/i' => '[token]',
            '/\+?\d[\d \-().]{7,}\d/' => '[number]',                         // phone, card and account numbers
            "/'(?:[^'\\\\]|\\\\.){1,500}'/" => "'?'",                       // quoted values (SQL bindings, input)
            // Double quotes: PHP names keys this way ("Undefined array key "name"") — keep plain identifiers, mask
            // anything that looks like a value (spaces, digits, long text).
            '/"(?=[^"]*[\s\d])[^"]{1,500}"|"[^"]{41,500}"/' => '"?"',
        ];

        return mb_substr((string) preg_replace(array_keys($rules), array_values($rules), $text), 0, $limit);
    }

    /** For grouping: masked, then every remaining number made the same ("Deal 12 not found" is "Deal 13 not found"). */
    public static function normalise(?string $text): string
    {
        return (string) preg_replace(['/\b[A-Za-z_\-]*\d[\w\-]*\b/', '/\d+/'], ['0', '0'], self::text($text, 500));
    }

    /** A path relative to the app, so traces never reveal the server's layout. */
    public static function path(?string $file): string
    {
        if ($file === null || $file === '') {
            return '';
        }
        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : basename($file);
    }
}
