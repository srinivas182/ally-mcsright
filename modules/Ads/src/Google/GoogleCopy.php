<?php

declare(strict_types=1);

namespace Modules\Ads\Google;

/**
 * The text of a Google ad (specs/ads/05), made from the person's headline and text and the home's facts, within
 * Google's limits: search headlines up to 30 characters (3–15), descriptions up to 90 (2–4); YouTube headlines up to
 * 40, long headlines up to 90, a business name up to 25. Keywords describe the home and its city, never a ZIP code.
 * Nothing here is about who should live there; all of it passes the Fair Housing check before the ad is made.
 */
final class GoogleCopy
{
    /**
     * @param  array{address?: string|null, city?: string|null, state?: string|null, beds?: int|null, price?: int|null, subdivision?: string|null}  $facts
     * @return array{headlines: list<string>, descriptions: list<string>, long_headlines: list<string>, video_headlines: list<string>, keywords: list<string>, business: string}
     */
    public static function make(array $facts, string $headline, string $body, string $disclosure, string $business, bool $funnel): array
    {
        $city = trim((string) ($facts['city'] ?? ''));
        $state = trim((string) ($facts['state'] ?? ''));
        $street = trim((string) ($facts['address'] ?? ''));
        $beds = (int) ($facts['beds'] ?? 0);
        $price = (int) ($facts['price'] ?? 0);
        $sub = trim((string) ($facts['subdivision'] ?? ''));

        $headlines = self::unique(array_map(fn (string $t): string => self::fit($t, 30), array_filter([
            $headline,
            $street,
            $funnel ? ($city !== '' ? 'Homes for Sale in '.$city : null) : ($city !== '' ? $city.' Home for Sale' : 'Home for Sale'),
            $beds > 0 ? $beds.' Bed Home'.($city !== '' ? ' in '.$city : '') : null,
            $price > 0 ? '$'.number_format($price) : null,
            $sub !== '' ? 'In '.$sub : null,
            'See Photos and Details',
            'Book a Private Showing',
            mb_strlen($business) <= 30 ? $business : null,
        ])), 15);
        $descriptions = self::unique(array_map(fn (string $t): string => self::fit($t, 90), array_filter([
            $body,
            $disclosure.'.',
            'See every photo, the price history and the neighborhood, then book a showing.',
        ])), 4);
        $long = self::unique(array_map(fn (string $t): string => self::fit($t, 90), array_filter([
            $headline.($street !== '' && ! str_contains($headline, $street) ? ': '.$street : ''),
            $body,
        ])), 5);
        $keywords = self::unique(array_map(fn (string $k): string => mb_strtolower(trim((string) preg_replace('/[^\pL\pN\s\-&\']/u', ' ', $k))), array_filter([
            $street !== '' ? $street : null,
            $street !== '' && $city !== '' ? $street.' '.$city : null,
            $city !== '' ? 'homes for sale '.$city : null,
            $city !== '' ? 'houses for sale in '.$city.($state !== '' ? ' '.$state : '') : null,
            $city !== '' && $beds > 0 ? $beds.' bedroom homes '.$city : null,
            $sub !== '' ? $sub.' homes for sale' : null,
        ])), 20);
        // No ZIP codes as keywords (housing ads aren't aimed by ZIP code).
        $keywords = array_values(array_filter(array_map(fn (string $k): string => trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/\b\d{5}\b/', '', $k))), $keywords),
            fn (string $k): bool => mb_strlen($k) >= 3 && mb_strlen($k) <= 80));

        while (count($headlines) < 3) {
            $headlines[] = ['Open the Listing', 'See the Home', 'Homes for Sale'][count($headlines)];
        }

        return ['headlines' => $headlines, 'descriptions' => count($descriptions) >= 2 ? $descriptions : [...$descriptions, 'Book a showing today.'],
            'long_headlines' => $long, 'video_headlines' => array_slice(self::unique(array_map(fn (string $t): string => self::fit($t, 40), [$headline, ...$headlines])), 0, 5),
            'keywords' => self::unique($keywords), 'business' => self::fit($business, 25)];
    }

    /** Shortens at a word where it can, never mid-word with no gap. */
    public static function fit(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > $max / 2 ? mb_substr($cut, 0, $space) : $cut, ' ,.;:-');
    }

    /**
     * @param  array<int, string>  $items
     * @return list<string>
     */
    private static function unique(array $items, int $max = 50): array
    {
        $out = [];
        foreach ($items as $i) {
            if ($i !== '' && ! in_array(mb_strtolower($i), array_map('mb_strtolower', $out), true)) {
                $out[] = $i;
            }
        }

        return array_slice($out, 0, $max);
    }
}
