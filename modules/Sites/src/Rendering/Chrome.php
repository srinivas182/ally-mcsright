<?php

declare(strict_types=1);

namespace Modules\Sites\Rendering;

use Carbon\CarbonImmutable;
use Modules\Crm\Consent;
use Modules\Sites\Maps;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;
use Modules\Sites\PoweredBy;

/** What every website page shares: the menu (with "Homes for sale" after Home), the credit, the map token. */
final class Chrome
{
    /**
     * @param  array<int, mixed>  $disclaimers  MLS disclaimers of the listings on the page (empty and repeated ones dropped)
     * @return array{menu: list<array{path: string, title: string}>, powered: array{text: string, url: string}|null, year: int, mapbox: string|null, disclaimers: list<string>, signup_after: int, consent: string}
     */
    public static function for(Site $site, int $tenantId, array $disclaimers = []): array
    {
        $menu = [];
        $pages = SitePage::query()->where('site_id', $site->id)->where('status', 'published')->where('in_menu', true)->orderBy('position')->get(['path', 'title']);
        foreach ($pages as $p) {
            $menu[] = ['path' => $p->path, 'title' => $p->title];
            if ($p->path === '/') {
                $menu[] = ['path' => '/homes', 'title' => 'Homes for sale'];
            }
        }
        if (! in_array('/homes', array_column($menu, 'path'), true)) {
            array_unshift($menu, ['path' => '/homes', 'title' => 'Homes for sale']);
        }
        $at = array_search('/homes', array_column($menu, 'path'), true);
        array_splice($menu, (int) $at + 1, 0, [['path' => '/home-value', 'title' => 'Home value']]);

        return ['menu' => $menu, 'powered' => app(PoweredBy::class)->forTenant($tenantId), 'year' => CarbonImmutable::now()->year,
            'mapbox' => app(Maps::class)->token(), 'disclaimers' => array_values(array_unique(array_filter($disclaimers, fn ($d): bool => is_string($d) && $d !== ''))),
            'signup_after' => max(0, min(20, (int) ($site->settings['signup_after'] ?? config('sites.signup_after', 3)))), 'consent' => Consent::DEFAULT_SMS_WORDING];
    }
}
