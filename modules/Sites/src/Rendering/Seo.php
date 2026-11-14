<?php

declare(strict_types=1);

namespace Modules\Sites\Rendering;

use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;

/**
 * Head tags and structured data for every page (websites/04, the SEO and AEO standard): title, description,
 * canonical, Open Graph and Twitter cards, and JSON-LD — the brokerage as RealEstateAgent (with sameAs), the
 * WebSite, the WebPage with breadcrumbs, and FAQPage for question sections.
 */
final class Seo
{
    /**
     * @return array{title: string, description: string, canonical: string, image: string|null, robots: string, jsonld: string}
     */
    public static function forPage(Site $site, SitePage $page, string $base, bool $indexable): array
    {
        $name = $site->displayName();
        $suffix = (string) ($site->seo['title_suffix'] ?? $name);
        $title = $page->path === '/' ? trim($name.(($site->info['tagline'] ?? '') !== '' ? ' | '.$site->info['tagline'] : '')) : $page->title.' | '.$suffix;
        $description = (string) ($page->meta_description ?: ($site->seo['description'] ?? '') ?: ($site->info['tagline'] ?? '') ?: "Homes for sale with {$name}.");
        $canonical = rtrim($base, '/').($page->path === '/' ? '/' : $page->path);
        $image = $site->brand['hero_image'] ?? $site->brand['logo_url'] ?? null;

        $org = array_filter([
            '@type' => 'RealEstateAgent', '@id' => rtrim($base, '/').'/#organization', 'name' => $name, 'url' => rtrim($base, '/').'/',
            'logo' => $site->brand['logo_url'] ?? null, 'image' => $image, 'telephone' => ($site->info['phone'] ?? '') ?: null, 'email' => ($site->info['email'] ?? '') ?: null,
            'description' => ($site->info['tagline'] ?? '') ?: null, 'areaServed' => ($site->info['area'] ?? '') ?: null,
            'address' => ($site->info['street'] ?? '') !== '' ? ['@type' => 'PostalAddress', 'streetAddress' => $site->info['street'], 'addressLocality' => $site->info['city'] ?? null,
                'addressRegion' => $site->info['state'] ?? null, 'postalCode' => $site->info['zip'] ?? null, 'addressCountry' => 'US'] : null,
            'sameAs' => array_values((array) ($site->info['social'] ?? [])) ?: null,
        ], fn ($v): bool => $v !== null);
        $graph = [
            $org,
            ['@type' => 'WebSite', '@id' => rtrim($base, '/').'/#website', 'url' => rtrim($base, '/').'/', 'name' => $name, 'publisher' => ['@id' => rtrim($base, '/').'/#organization'], 'inLanguage' => 'en-US'],
            array_filter(['@type' => 'WebPage', '@id' => $canonical.'#webpage', 'url' => $canonical, 'name' => $page->title, 'description' => $description,
                'isPartOf' => ['@id' => rtrim($base, '/').'/#website'], 'dateModified' => $page->updated_at?->toIso8601String(),
                'breadcrumb' => $page->path === '/' ? null : ['@type' => 'BreadcrumbList', 'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => rtrim($base, '/').'/'],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => $page->title, 'item' => $canonical],
                ]]], fn ($v): bool => $v !== null),
        ];
        foreach ($page->sections as $s) {
            if (($s['type'] ?? '') === 'faq' && ($s['props']['items'] ?? []) !== []) {
                $graph[] = ['@type' => 'FAQPage', 'mainEntity' => array_values(array_map(fn (array $i): array => ['@type' => 'Question', 'name' => $i['q'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $i['a']]], array_filter($s['props']['items'], fn (array $i): bool => $i['q'] !== '' && $i['a'] !== '')))];
            }
        }

        return ['title' => mb_substr($title, 0, 70), 'description' => mb_substr($description, 0, 160), 'canonical' => $canonical, 'image' => is_string($image) ? $image : null,
            'robots' => $indexable && ! $page->noindex ? 'index, follow, max-image-preview:large' : 'noindex, follow',
            'jsonld' => (string) json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)];
    }
}
