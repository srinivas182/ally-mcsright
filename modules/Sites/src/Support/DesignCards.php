<?php

declare(strict_types=1);

namespace Modules\Sites\Support;

/**
 * WB1: the website designs as cards, with what a small preview needs (background, colours, heading font), so the
 * design picker in Settings › Website and on the sign-up page show what each design really looks like.
 */
final class DesignCards
{
    /** The luxury designs, shown first. */
    public const LUXURY = ['aurum', 'estate', 'ember', 'heritage', 'atrium'];

    /**
     * @return list<array{key: string, name: string, description: string, theme: string, primary: string, accent: string, font: string, luxury: bool, preview: string}>
     */
    public static function all(): array
    {
        $cards = [];
        foreach ((array) config('sites.templates') as $key => $t) {
            if (! is_array($t)) {
                continue;
            }
            $brand = (array) ($t['brand'] ?? []);
            $cards[] = ['key' => (string) $key, 'name' => (string) ($t['name'] ?? $key), 'description' => (string) ($t['description'] ?? ''),
                'theme' => (string) ($t['theme'] ?? '#ffffff'), 'primary' => (string) ($brand['primary'] ?? '#1d4ed8'), 'accent' => (string) ($brand['accent'] ?? '#0f172a'),
                'font' => trim((string) strtok((string) ($t['fonts'] ?? 'Inter'), '+')), 'luxury' => in_array($key, self::LUXURY, true),
                'preview' => 'https://'.config('core.hq_host').'/start/designs/'.$key];
        }
        $rank = fn (array $c): int => $c['luxury'] ? (int) array_search($c['key'], self::LUXURY, true) : 99;
        usort($cards, fn (array $a, array $b): int => $rank($a) <=> $rank($b));

        return $cards;
    }
}
