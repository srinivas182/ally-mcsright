<?php

declare(strict_types=1);

namespace Modules\Marketing;

use Throwable;

/**
 * SITE2: the header and footer of RightAlly's public pages, shared by the website (rightally.app) and the pages on the
 * app host that people reach from it (sign-up, agent sign-up, Health Check, partner pages), so the logo, menu,
 * language, log in, start now and footer are the same everywhere.
 */
final class SiteChrome
{
    /** The website menu's short words for RightAlly's own pages (BR3). */
    public const MENU = [
        'en' => ['for-brokers' => 'Brokerages', 'for-agents' => 'Agents', 'pricing' => 'Pricing', 'compare' => 'Compare', 'security' => 'Security', 'features' => 'Features'],
        'es' => ['para-corredurias' => 'Corredurías', 'para-agentes' => 'Agentes', 'pricing' => 'Precios', 'precios' => 'Precios', 'security' => 'Seguridad', 'seguridad' => 'Seguridad', 'funciones' => 'Funciones'],
    ];

    /**
     * Everything the header and footer partials need, for a page outside the website.
     *
     * @param  array<string, string>  $alternates  language => this page's address in that language, when it has one
     * @return array{L: string, home: string, nav: list<array{label: string, href: string}>, canonical: string, other: string, start: string, healthCheck: string, legal: array{privacy: string, terms: string}, login: string}
     */
    public static function for(string $locale, string $here, array $alternates = []): array
    {
        $L = $locale === 'es' ? 'es' : 'en';
        $twin = $L === 'es' ? 'en' : 'es';

        return ['L' => $L, 'home' => self::url($L, ''), 'nav' => self::nav($L), 'canonical' => $alternates[$L] ?? $here, 'other' => $alternates[$twin] ?? self::url($twin, ''),
            'start' => 'https://'.config('core.hq_host').'/start', 'healthCheck' => 'https://'.config('core.hq_host').($L === 'es' ? '/health-check/es' : '/health-check'),
            'legal' => ['privacy' => self::url($L, 'privacy'), 'terms' => self::url($L, 'terms')], 'login' => self::url('en', 'login')];
    }

    /**
     * The website's menu: its live pages in this language (not the legal ones), in a fixed order, at most six.
     *
     * @return list<array{label: string, href: string}>
     */
    public static function nav(string $locale): array
    {
        $out = [];
        try {
            $pages = app(Website::class)->pages();
        } catch (Throwable) {
            return [];
        }
        foreach ($pages as $p) {
            if ($p->status === 'live' && $p->locale === $locale && $p->slug !== '' && ! str_contains((string) $p->slug, '/') && ! in_array((string) $p->slug, LegalDefaults::SLUGS, true)) {
                $label = self::MENU[$locale][(string) $p->slug] ?? trim((string) strtok((string) $p->title, ':'));
                $out[] = ['label' => mb_strimwidth($label, 0, 24, '…'), 'href' => self::url($locale, (string) $p->slug), 'slug' => (string) $p->slug];
            }
        }
        $order = ['for-brokers', 'for-agents', 'features', 'pricing', 'compare', 'security', 'funciones', 'precios'];
        usort($out, fn (array $a, array $b): int => [array_search($a['slug'], $order, true) === false ? 99 : array_search($a['slug'], $order, true), $a['label']]
            <=> [array_search($b['slug'], $order, true) === false ? 99 : array_search($b['slug'], $order, true), $b['label']]);

        return array_map(fn (array $i): array => ['label' => $i['label'], 'href' => $i['href']], array_slice($out, 0, 6));
    }

    public static function url(string $locale, string $slug): string
    {
        $path = trim(($locale === 'es' ? 'es/' : '').$slug, '/');

        return 'https://'.((array) config('marketing.hosts'))[0].'/'.$path;
    }
}
