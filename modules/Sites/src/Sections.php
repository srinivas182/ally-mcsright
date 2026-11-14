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
    public const TYPES = ['hero', 'listings', 'features', 'text', 'faq', 'cta', 'contact'];

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    public static function clean(array $sections): array
    {
        $out = [];
        foreach (array_slice($sections, 0, 40) as $i => $s) {
            $type = (string) ($s['type'] ?? '');
            if (! in_array($type, self::TYPES, true)) {
                continue;
            }
            $p = is_array($s['props'] ?? null) ? $s['props'] : [];
            $t = static fn (string $k, int $max = 200): string => mb_substr(trim(strip_tags((string) (is_scalar($p[$k] ?? null) ? $p[$k] : ''))), 0, $max);
            $props = match ($type) {
                'hero' => ['headline' => $t('headline', 120), 'subhead' => $t('subhead', 240), 'image' => self::url($p['image'] ?? null), 'search' => (bool) ($p['search'] ?? true)],
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
            };
            $out[] = ['type' => $type, 'id' => preg_match('/^[a-z0-9-]{1,40}$/', (string) ($s['id'] ?? '')) === 1 ? (string) $s['id'] : $type.'-'.$i, 'props' => $props];
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
