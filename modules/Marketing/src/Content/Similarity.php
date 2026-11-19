<?php

declare(strict_types=1);

namespace Modules\Marketing\Content;

/**
 * MK3/MK4: how alike two texts are — the share of five-word runs they have in common (Jaccard similarity of word
 * shingles, after lower-casing and dropping punctuation). 0 = nothing in common, 1 = the same text. Drafts above
 * MAX against anything already written in the same state (by HQ or any tenant) are rewritten, then dropped.
 */
final class Similarity
{
    public const MAX = 0.20;

    /** @return array<string, true> */
    public static function shingles(string $text, int $size = 5): array
    {
        $words = preg_split('/\s+/', trim((string) preg_replace('/[^a-z0-9\s]+/', ' ', mb_strtolower($text)))) ?: [];
        $out = [];
        for ($i = 0, $n = count($words) - $size; $i <= $n; $i++) {
            $out[implode(' ', array_slice($words, $i, $size))] = true;
        }

        return $out;
    }

    public static function score(string $a, string $b): float
    {
        $x = self::shingles($a);
        $y = self::shingles($b);
        if ($x === [] || $y === []) {
            return 0.0;
        }
        $common = count(array_intersect_key($x, $y));

        return round($common / (count($x) + count($y) - $common), 3);
    }

    /** @param iterable<string> $others */
    public static function highest(string $text, iterable $others): float
    {
        $max = 0.0;
        foreach ($others as $other) {
            $max = max($max, self::score($text, $other));
        }

        return $max;
    }
}
