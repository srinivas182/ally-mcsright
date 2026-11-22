<?php

declare(strict_types=1);

namespace Modules\Sites\Rendering;

use Carbon\CarbonImmutable;
use Modules\Crm\Consent;
use Modules\Listings\Idx\FooterDisclosures;
use Modules\Sites\AgentSites;
use Modules\Sites\Ailly\AillySettings;
use Modules\Sites\Maps;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;
use Modules\Sites\Models\SitePost;
use Modules\Sites\PoweredBy;

/** What every website page shares: the menu (with "Homes for sale" after Home), the credit, the map token. */
final class Chrome
{
    /**
     * @param  array<int, mixed>  $disclaimers  MLS disclaimers of the listings on the page (empty and repeated ones dropped)
     * @return array{agent: array{name: string, phone: string, email: string, photo: string|null, licence: array{state: string|null, number: string}|null, home: string, ulid: string}|null, template: string, brand: array<string, mixed>, menu: list<array{path: string, title: string}>, footer: list<array{path: string, title: string}>, powered: array{text: string, url: string}|null, year: int, mapbox: string|null, ailly: array{name: string, photo: string|null, nudge: bool}|null, disclaimers: list<string>, mls: array{boards: list<array{name: string, logo: string|null, disclaimer: string}>, sample: bool}, signup_after: int, consent: string}
     */
    public static function for(Site $site, int $tenantId, array $disclaimers = []): array
    {
        $menu = [];
        $context = app(SiteContext::class);
        $agentSite = $context->agent();
        $custom = $site->menus['main'] ?? null;
        if ($agentSite !== null) {
            // An agent's website: their pages, then the brokerage's home search and home value.
            foreach (SitePage::query()->where('site_id', $agentSite->id)->where('status', 'published')->where(fn ($q) => $q->where('in_menu', true)->orWhere('path', '/'))
                ->orderBy('position')->get(['path', 'title']) as $p) {
                $menu[] = ['path' => $context->url($p->path), 'title' => $p->path === '/' ? 'Home' : $p->title];
            }
            $menu[] = ['path' => '/homes', 'title' => 'Homes for sale'];
            $menu[] = ['path' => '/home-value', 'title' => 'Home value'];
        } elseif (is_array($custom) && $custom !== []) {
            foreach ($custom as $m) {
                $menu[] = ['path' => (string) $m['url'], 'title' => (string) $m['label']];
            }
        } else {
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
            if (SitePost::query()->where('site_id', $site->id)->live()->exists()) {
                $menu[] = ['path' => '/blog', 'title' => 'Blog'];
            }
        }
        $footer = [];
        foreach ((array) ($site->menus['footer'] ?? []) as $m) {
            $footer[] = ['path' => (string) $m['url'], 'title' => (string) $m['label']];
        }

        $rules = AgentSites::rules();
        $agent = null;
        if ($agentSite !== null) {
            $p = AgentSites::profile($agentSite);
            $agent = ['name' => $p['name'], 'phone' => $p['phone'], 'email' => $p['email'], 'photo' => $p['photo'], 'licence' => $p['licence'], 'home' => $context->url('/'),
                'ulid' => $agentSite->ulid];
        }

        return ['agent' => $agent, 'template' => $agentSite !== null && $rules['template'] ? $agentSite->template : $site->template,
            'brand' => $agentSite !== null && $rules['colours'] ? $agentSite->brand : $site->brand, 'menu' => $menu, 'footer' => $footer !== [] ? $footer : [...$menu, ['path' => '/market', 'title' => 'Market reports']], 'powered' => app(PoweredBy::class)->forTenant($tenantId), 'year' => CarbonImmutable::now()->year,
            'mapbox' => app(Maps::class)->token(), 'ailly' => self::ailly(), 'disclaimers' => array_values(array_unique(array_filter($disclaimers, fn ($d): bool => is_string($d) && $d !== ''))),
            'mls' => FooterDisclosures::for($tenantId), // SU2: locked MLS disclosure in every design's footer
            'signup_after' => max(0, min(20, (int) ($site->settings['signup_after'] ?? config('sites.signup_after', 3)))), 'consent' => Consent::DEFAULT_SMS_WORDING];
    }

    /**
     * The assistant when the brokerage has switched it on: its name and photo on this website (an agent's own, or the
     * brokerage's; null photo = the built-in illustration).
     *
     * @return array{name: string, photo: string|null, nudge: bool}|null
     */
    private static function ailly(): ?array
    {
        $brokerage = Site::query()->where('kind', 'brokerage')->where('status', 'live')->first();
        $settings = AillySettings::for($brokerage);

        return $settings['enabled'] ? AillySettings::persona(app(SiteContext::class)->agent() ?? $brokerage, $brokerage) + ['nudge' => $settings['nudge']] : null;
    }
}
