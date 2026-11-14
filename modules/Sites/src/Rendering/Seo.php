<?php

declare(strict_types=1);

namespace Modules\Sites\Rendering;

use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;

/**
 * Head tags and structured data for every page (websites/04, the SEO and AEO standard): title, description,
 * canonical, Open Graph and Twitter cards, and JSON-LD — the brokerage as RealEstateAgent (with sameAs), the
 * WebSite, the WebPage with breadcrumbs, FAQPage for question sections, and page-specific items (a listing,
 * a market's figures).
 */
final class Seo
{
    /**
     * @return array{title: string, description: string, canonical: string, image: string|null, robots: string, jsonld: string, og_type: string}
     */
    public static function forPage(Site $site, SitePage $page, string $base, bool $indexable): array
    {
        $name = $site->displayName();
        $suffix = (string) ($site->seo['title_suffix'] ?? $name);
        $title = $page->path === '/' ? trim($name.(($site->info['tagline'] ?? '') !== '' ? ' | '.$site->info['tagline'] : '')) : $page->title.' | '.$suffix;
        $description = (string) ($page->meta_description ?: ($site->seo['description'] ?? '') ?: ($site->info['tagline'] ?? '') ?: "Homes for sale with {$name}.");
        $faqs = [];
        foreach ($page->sections as $s) {
            if (($s['type'] ?? '') === 'faq') {
                foreach ((array) ($s['props']['items'] ?? []) as $i) {
                    $faqs[] = ['q' => (string) ($i['q'] ?? ''), 'a' => (string) ($i['a'] ?? '')];
                }
            }
        }

        return self::build($site, $base, $page->path, $title, $description, null, $indexable && ! $page->noindex,
            $page->path === '/' ? [] : [[$page->title, $page->path]], $faqs, [], $page->updated_at?->toIso8601String());
    }

    /**
     * @param  list<array{0: string, 1: string}>  $crumbs  [name, path] after Home
     * @param  list<array{q: string, a: string}>  $faqs
     * @param  list<array<string, mixed>>  $extra  more JSON-LD nodes
     * @return array{title: string, description: string, canonical: string, image: string|null, robots: string, jsonld: string, og_type: string}
     */
    public static function build(Site $site, string $base, string $path, string $title, string $description, ?string $image, bool $indexable,
        array $crumbs = [], array $faqs = [], array $extra = [], ?string $modified = null, string $ogType = 'website'): array
    {
        $root = rtrim($base, '/');
        $name = $site->displayName();
        $canonical = $root.($path === '/' ? '/' : $path);
        $image ??= $site->brand['hero_image'] ?? $site->brand['logo_url'] ?? null;

        $org = array_filter([
            '@type' => 'RealEstateAgent', '@id' => $root.'/#organization', 'name' => $name, 'url' => $root.'/',
            'logo' => $site->brand['logo_url'] ?? null, 'image' => $site->brand['hero_image'] ?? $site->brand['logo_url'] ?? null,
            'telephone' => ($site->info['phone'] ?? '') ?: null, 'email' => ($site->info['email'] ?? '') ?: null,
            'description' => ($site->info['tagline'] ?? '') ?: null, 'areaServed' => ($site->info['area'] ?? '') ?: null,
            'address' => ($site->info['street'] ?? '') !== '' ? ['@type' => 'PostalAddress', 'streetAddress' => $site->info['street'], 'addressLocality' => $site->info['city'] ?? null,
                'addressRegion' => $site->info['state'] ?? null, 'postalCode' => $site->info['zip'] ?? null, 'addressCountry' => 'US'] : null,
            'sameAs' => array_values((array) ($site->info['social'] ?? [])) ?: null,
        ], fn ($v): bool => $v !== null);
        $items = [['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $root.'/']];
        foreach ($crumbs as $i => [$crumb, $crumbPath]) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 2, 'name' => $crumb, 'item' => $root.$crumbPath];
        }
        $graph = [
            $org,
            ['@type' => 'WebSite', '@id' => $root.'/#website', 'url' => $root.'/', 'name' => $name, 'publisher' => ['@id' => $root.'/#organization'], 'inLanguage' => 'en-US',
                'potentialAction' => ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $root.'/homes?q={search_term_string}'],
                    'query-input' => 'required name=search_term_string']],
            array_filter(['@type' => 'WebPage', '@id' => $canonical.'#webpage', 'url' => $canonical, 'name' => $title, 'description' => $description,
                'isPartOf' => ['@id' => $root.'/#website'], 'dateModified' => $modified,
                'breadcrumb' => $crumbs === [] ? null : ['@type' => 'BreadcrumbList', 'itemListElement' => $items]], fn ($v): bool => $v !== null),
            ...$extra,
        ];
        $faqs = array_values(array_filter($faqs, fn (array $f): bool => $f['q'] !== '' && $f['a'] !== ''));
        if ($faqs !== []) {
            $graph[] = ['@type' => 'FAQPage', 'mainEntity' => array_map(fn (array $f): array => ['@type' => 'Question', 'name' => $f['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]], $faqs)];
        }

        return ['title' => mb_substr($title, 0, 70), 'description' => mb_substr($description, 0, 160), 'canonical' => $canonical, 'image' => is_string($image) ? $image : null,
            'robots' => $indexable ? 'index, follow, max-image-preview:large' : 'noindex, follow', 'og_type' => $ogType,
            'jsonld' => (string) json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)];
    }
}
