<?php

declare(strict_types=1);

namespace Modules\Sites;

/**
 * The section types a page is built from (specs/websites/05). Each section is {type, id, props}; props are cleaned
 * here so a page can never carry markup or scripts — text is escaped when rendered and links must be site paths or
 * https.
 */
final class Sections
{
    public const TYPES = ['hero', 'listings', 'features', 'text', 'faq', 'cta', 'contact', 'testimonials', 'team', 'stats', 'gallery', 'video', 'neighbourhoods'];

    /** What the page builder shows for each type. */
    public const LABELS = ['hero' => 'Hero with search', 'listings' => 'Homes for sale', 'features' => 'Features', 'text' => 'Text', 'faq' => 'Questions and answers',
        'cta' => 'Call to action', 'contact' => 'Contact form', 'testimonials' => 'Testimonials', 'team' => 'Team', 'stats' => 'Numbers', 'gallery' => 'Photo gallery',
        'video' => 'Video', 'neighbourhoods' => 'Neighborhoods'];

    /**
     * Cleans untrusted section data (from the builder, a stored version or AI): unknown types and malformed values
     * are dropped.
     *
     * @param  array<mixed>  $sections
     * @return list<array<string, mixed>>
     */
    public static function clean(array $sections): array
    {
        $out = [];
        foreach (array_slice(array_values($sections), 0, 40) as $i => $s) {
            if (! is_array($s)) {
                continue;
            }
            $type = (string) ($s['type'] ?? '');
            if (! in_array($type, self::TYPES, true)) {
                continue;
            }
            $p = is_array($s['props'] ?? null) ? $s['props'] : [];
            $t = static fn (string $k, int $max = 200): string => mb_substr(trim(strip_tags((string) (is_scalar($p[$k] ?? null) ? $p[$k] : ''))), 0, $max);
            $items = static fn (int $max): array => array_slice(array_values(array_filter((array) ($p['items'] ?? []), 'is_array')), 0, $max);
            $s2 = static fn (array $it, string $k, int $max): string => mb_substr(trim(strip_tags((string) (is_scalar($it[$k] ?? null) ? $it[$k] : ''))), 0, $max);
            $props = match ($type) {
                'hero' => ['headline' => $t('headline', 120), 'subhead' => $t('subhead', 240), 'image' => self::image($p['image'] ?? null), 'search' => (bool) ($p['search'] ?? true)],
                'listings' => ['title' => $t('title', 120), 'q' => $t('q', 80), 'max_price' => is_numeric($p['max_price'] ?? null) ? (int) $p['max_price'] : null,
                    'min_price' => is_numeric($p['min_price'] ?? null) ? (int) $p['min_price'] : null, 'sort' => in_array($p['sort'] ?? '', ['newest', 'price_desc', 'reduced'], true) ? (string) $p['sort'] : 'newest',
                    'limit' => max(3, min(12, (int) ($p['limit'] ?? 6)))],
                'features' => ['title' => $t('title', 120), 'items' => array_values(array_map(fn ($it): array => ['title' => mb_substr(trim(strip_tags((string) ($it['title'] ?? ''))), 0, 80),
                    'body' => mb_substr(trim(strip_tags((string) ($it['body'] ?? ''))), 0, 300)], array_slice(array_filter((array) ($p['items'] ?? []), 'is_array'), 0, 6)))],
                'text' => ['title' => $t('title', 120), 'body' => $t('body', 6000)],
                'faq' => ['title' => $t('title', 120), 'items' => array_values(array_map(fn ($it): array => ['q' => mb_substr(trim(strip_tags((string) ($it['q'] ?? ''))), 0, 200),
                    'a' => mb_substr(trim(strip_tags((string) ($it['a'] ?? ''))), 0, 1200)], array_slice(array_filter((array) ($p['items'] ?? []), 'is_array'), 0, 20)))],
                'cta' => ['title' => $t('title', 120), 'body' => $t('body', 300), 'button' => $t('button', 40), 'link' => self::link($p['link'] ?? '/contact')],
                'contact' => ['title' => $t('title', 120), 'body' => $t('body', 300)],
                'testimonials' => ['title' => $t('title', 120), 'items' => array_map(fn (array $it): array => ['quote' => $s2($it, 'quote', 600), 'name' => $s2($it, 'name', 80),
                    'detail' => $s2($it, 'detail', 80), 'rating' => max(0, min(5, (int) ($it['rating'] ?? 5)))], $items(12))],
                'team' => ['title' => $t('title', 120), 'items' => array_map(fn (array $it): array => ['name' => $s2($it, 'name', 80), 'role' => $s2($it, 'role', 80),
                    'photo' => self::image($it['photo'] ?? null), 'phone' => $s2($it, 'phone', 30), 'email' => filter_var($s2($it, 'email', 150), FILTER_VALIDATE_EMAIL) !== false ? $s2($it, 'email', 150) : ''], $items(24))],
                'stats' => ['title' => $t('title', 120), 'items' => array_map(fn (array $it): array => ['value' => $s2($it, 'value', 20), 'label' => $s2($it, 'label', 60)], $items(6))],
                'gallery' => ['title' => $t('title', 120), 'images' => array_values(array_filter(array_map(fn ($v): ?string => self::image($v), array_slice((array) ($p['images'] ?? []), 0, 24))))],
                'video' => ['title' => $t('title', 120), 'url' => self::video($p['url'] ?? null), 'caption' => $t('caption', 200)],
                'neighbourhoods' => ['title' => $t('title', 120), 'places' => array_values(array_filter(array_map(fn ($v): ?string => is_string($v) && preg_match('/^[a-z0-9-]{2,80}$/', $v) === 1 ? $v : null,
                    array_slice((array) ($p['places'] ?? []), 0, 12))))],
            };
            $out[] = ['type' => $type, 'id' => preg_match('/^[a-z0-9-]{1,40}$/', (string) ($s['id'] ?? '')) === 1 ? (string) $s['id'] : $type.'-'.$i, 'props' => $props,
                'hidden' => (bool) ($s['hidden'] ?? false)];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $info
     * @return array<string, list<array<string, mixed>>>
     */
    public static function starterPages(array $info): array
    {
        $name = (string) ($info['name'] ?? 'Our brokerage');
        $area = trim((string) ($info['area'] ?? '')) ?: 'your area';

        return [
            '/' => self::clean([
                ['type' => 'hero', 'id' => 'hero', 'props' => ['headline' => "Find your place in {$area}", 'subhead' => (string) ($info['tagline'] ?? "Every home for sale, updated every few minutes, with local experts from {$name}."), 'search' => true]],
                ['type' => 'listings', 'id' => 'new', 'props' => ['title' => 'New on the market', 'sort' => 'newest', 'limit' => 6]],
                ['type' => 'features', 'id' => 'why', 'props' => ['title' => "Why {$name}", 'items' => [
                    ['title' => 'Local experts', 'body' => "Agents who know {$area} street by street."],
                    ['title' => 'Always up to date', 'body' => 'Listings refresh from the MLS every few minutes, with price changes the moment they happen.'],
                    ['title' => 'Answers any time', 'body' => 'Ask our assistant about any home, day or night, and book a call when you are ready.'],
                ]]],
                ['type' => 'faq', 'id' => 'faq', 'props' => ['title' => 'Questions buyers and sellers ask', 'items' => [
                    ['q' => 'How do I book a showing?', 'a' => 'Open any home and send us a message, or call us. An agent confirms a time that suits you, usually within the hour.'],
                    ['q' => "Which areas does {$name} serve?", 'a' => "We help buyers and sellers across {$area}. Tell us where you are looking and we will match you with an agent who knows it well."],
                    ['q' => 'What is my home worth?', 'a' => 'Request a free valuation and one of our agents will prepare a price range from recent nearby sales, usually within one business day.'],
                ]]],
                ['type' => 'cta', 'id' => 'sell', 'props' => ['title' => 'Thinking of selling?', 'body' => 'Get a free, no-pressure valuation from a local agent.', 'button' => 'Talk to an agent', 'link' => '/contact']],
            ]),
            '/about' => self::clean([
                ['type' => 'text', 'id' => 'about', 'props' => ['title' => "About {$name}", 'body' => (string) ($info['about'] ?? "{$name} helps people buy and sell homes in {$area}.")]],
                ['type' => 'cta', 'id' => 'talk', 'props' => ['title' => 'Talk to us', 'body' => 'Questions about buying or selling? We are here to help.', 'button' => 'Contact us', 'link' => '/contact']],
            ]),
            '/contact' => self::clean([
                ['type' => 'contact', 'id' => 'contact', 'props' => ['title' => 'Contact us', 'body' => 'Send a message and an agent will get back to you shortly.']],
            ]),
        ];
    }

    /**
     * Blank props for a new section of a type (the builder's "Add section").
     *
     * @return array<string, mixed>
     */
    public static function blank(string $type): array
    {
        return self::clean([['type' => $type, 'id' => $type.'-'.substr(bin2hex(random_bytes(3)), 0, 6), 'props' => match ($type) {
            'hero' => ['headline' => 'A headline', 'subhead' => '', 'search' => true],
            'listings' => ['title' => 'New on the market', 'limit' => 6],
            'features' => ['title' => 'Why choose us', 'items' => [['title' => 'A reason', 'body' => 'Say why it matters.']]],
            'text' => ['title' => 'A heading', 'body' => 'Write here.'],
            'faq' => ['title' => 'Questions and answers', 'items' => [['q' => 'A question people ask', 'a' => 'A short, direct answer.']]],
            'cta' => ['title' => 'Ready to talk?', 'body' => '', 'button' => 'Contact us', 'link' => '/contact'],
            'contact' => ['title' => 'Contact us', 'body' => ''],
            'testimonials' => ['title' => 'What clients say', 'items' => [['quote' => 'Their words here.', 'name' => 'Client name', 'detail' => 'Bought in 2026', 'rating' => 5]]],
            'team' => ['title' => 'Our team', 'items' => [['name' => 'Name', 'role' => 'Agent']]],
            'stats' => ['title' => '', 'items' => [['value' => '25+', 'label' => 'Years in the area']]],
            'gallery' => ['title' => '', 'images' => []],
            'video' => ['title' => '', 'url' => null, 'caption' => ''],
            'neighbourhoods' => ['title' => 'Neighborhoods we know', 'places' => []],
            default => [],
        }]])[0] ?? [];
    }

    /** An image: a media-library id (26 characters) or an https link. */
    private static function image(mixed $v): ?string
    {
        if (is_string($v) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $v) === 1) {
            return $v;
        }

        return self::url($v);
    }

    /** YouTube and Vimeo only, stored as the video id link (played with privacy-enhanced embeds). */
    private static function video(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        if (preg_match('#^https://(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/shorts/)([A-Za-z0-9_-]{11})#', $v, $m) === 1) {
            return 'https://www.youtube.com/watch?v='.$m[1];
        }
        if (preg_match('#^https://(?:www\.)?vimeo\.com/(\d{5,12})#', $v, $m) === 1) {
            return 'https://vimeo.com/'.$m[1];
        }

        return null;
    }

    private static function url(mixed $v): ?string
    {
        return is_string($v) && preg_match('#^https://[^\s"<>]{1,490}$#', $v) === 1 ? $v : null;
    }

    private static function link(mixed $v): string
    {
        $v = is_string($v) ? trim($v) : '';

        return preg_match('#^/[A-Za-z0-9/_\-?=&%.]{0,190}$#', $v) === 1 || preg_match('#^https://[^\s"<>]{1,190}$#', $v) === 1 ? $v : '/contact';
    }
}
