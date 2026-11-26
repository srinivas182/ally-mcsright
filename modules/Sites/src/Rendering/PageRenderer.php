<?php

declare(strict_types=1);

namespace Modules\Sites\Rendering;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Search\ListingSearch;
use Modules\Sites\AgentSites;
use Modules\Sites\Content\AreaTiles;
use Modules\Sites\Http\Controllers\SiteController;
use Modules\Sites\Http\SiteExtensions;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;
use Modules\Sites\Reviews\Review;
use Modules\Sites\Reviews\Reviews;
use Modules\Sites\Sections;

/**
 * Renders a page of sections for the brokerage's website or an agent's (specs/websites/05, 09): listings sections
 * marked "mine" show the agent's own listings; agent pages add the agent as structured data, linked to the
 * brokerage.
 */
final class PageRenderer
{
    public function __construct(private readonly TenantContext $tenants, private readonly SiteContext $context) {}

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    public function render(Request $request, Site $brokerage, Site $owner, SitePage $page, array $sections, bool $indexable): Response
    {
        $tenantId = $this->tenants->id();
        $agent = $owner->kind === 'agent' ? AgentSites::profile($owner) : null;
        $blocks = [];
        foreach (Sections::clean($sections) as $s) {
            if ($s['hidden']) {
                continue;
            }
            if ($s['type'] === 'neighbourhoods') {
                // UX1: photo tiles for each area, including every state the brokerage has an MLS board in.
                $s['areas'] = AreaTiles::build($tenantId, array_values(array_map('strval', (array) $s['props']['places'])));
            }
            if ($s['type'] === 'listings') {
                $p = $s['props'];
                $mine = $p['mine'] && $agent !== null ? ($agent['mls_ids'][0] ?? '') : null;
                $s['listings'] = $mine === '' ? [] : app(ListingSearch::class)->search($tenantId, array_filter(['q' => $p['q'] ?? '', 'min_price' => $p['min_price'] ?? null,
                    'max_price' => $p['max_price'] ?? null, 'sort' => $p['sort'] ?? 'newest', 'per_page' => $p['limit'] ?? 6, 'agent_mls_id' => $mine]))['results'];
            }
            if ($s['type'] === 'agent') {
                // AG7: only on an agent's own page; numbers that are not there yet are left out, never shown as zero.
                if ($agent === null) {
                    continue;
                }
                $mls = (string) ($agent['mls_ids'][0] ?? '');
                $reviews = app(Reviews::class)->shown($owner->user_id !== null ? (int) $owner->user_id : null, 1);
                $rated = array_values(array_filter(array_column($reviews['sources'], 'average'), fn ($v): bool => $v !== null));
                $s['agent'] = $agent + ['brokerage' => $brokerage->displayName(),
                    'active' => $mls !== '' ? (int) app(ListingSearch::class)->search($tenantId, ['agent_mls_id' => $mls, 'per_page' => 1])['total'] : 0,
                    'reviews' => (int) $reviews['count'], 'rating' => $rated !== [] ? round(array_sum($rated) / count($rated), 1) : null];
            }
            if ($s['type'] === 'recruit') {
                // AG8: the plans new agents can pick, live (Recruiting provides them).
                $s['plans'] = $s['props']['plans'] ? array_slice(app(SiteExtensions::class)->data('join_plans'), 0, 3) : [];
            }
            if ($s['type'] === 'text' && $agent !== null && $s['id'] === 'about' && trim((string) $s['props']['body']) === '') {
                // AG7: "About Maria" uses her bio; with no bio yet the section is left out rather than shown empty.
                $s['props']['body'] = $agent['bio'];
            }
            if ($s['type'] === 'text' && trim((string) $s['props']['body']) === '' && trim((string) $s['props']['title']) !== '' && $agent !== null) {
                continue;
            }
            if ($s['type'] === 'testimonials' && ($s['props']['items'] === [] || $s['props']['from_reviews'])) {
                // C8a: the agent's (or brokerage's) reviews from Google and RealSatisfied, each with its source and date.
                $s['props']['items'] = app(Reviews::class)->shown($agent !== null ? ($owner->user_id !== null ? (int) $owner->user_id : null) : null, 6)['reviews']
                    ->filter(fn (Review $r): bool => trim((string) $r->text) !== '')
                    ->map(fn (Review $r): array => ['quote' => mb_substr((string) $r->text, 0, 600), 'name' => (string) ($r->author ?? 'A client'),
                        'detail' => (Reviews::SOURCES[$r->source] ?? $r->source).' review · '.$r->reviewed_at->format('M Y'), 'rating' => (int) ($r->rating ?? 0)])->values()->all();
            }
            $blocks[] = $s;
        }
        $page->sections = $sections;
        $base = $request->getSchemeAndHttpHost();
        $path = $this->context->url($page->path);

        if ($agent === null) {
            $seo = Seo::forPage($brokerage, $page, $base, $indexable);
        } else {
            $root = rtrim($base, '/');
            $node = array_filter(['@type' => 'RealEstateAgent', '@id' => $root.$this->context->url('/').'#agent', 'name' => $agent['name'], 'url' => $root.$this->context->url('/'),
                'image' => $agent['photo'], 'telephone' => $agent['phone'] ?: null, 'email' => $agent['email'] ?: null, 'description' => $agent['tagline'] ?: null,
                'areaServed' => $agent['areas'] ?: null, 'knowsLanguage' => $agent['languages'] ?: null, 'parentOrganization' => ['@id' => $root.'/#organization'],
                'identifier' => $agent['licence'] !== null ? ['@type' => 'PropertyValue', 'name' => trim(($agent['licence']['state'] ?? '').' real estate licence'), 'value' => $agent['licence']['number']] : null,
            ], fn ($v): bool => $v !== null);
            $title = $page->path === '/' ? $agent['name'].' | Real estate agent at '.$brokerage->displayName() : $page->title.' | '.$agent['name'];
            $description = $page->meta_description ?: ($agent['tagline'] ?: $agent['name'].' helps buyers and sellers'.($agent['areas'] !== [] ? ' in '.implode(', ', $agent['areas']) : '').' with '.$brokerage->displayName().'.');
            $crumbs = $this->context->prefix() !== '' ? [['Agents', '/agents'], [$agent['name'], $this->context->url('/')]] : [];
            if ($page->path !== '/') {
                $crumbs[] = [$page->title, $path];
            }
            $seo = Seo::build($brokerage, $base, $path, $title, $description, $agent['photo'], $indexable && ! $page->noindex, $crumbs, [], [$node], $page->updated_at?->toIso8601String());
        }

        return response()->view('sites::page', [
            'site' => $brokerage, 'page' => $page, 'blocks' => $blocks, 'current' => $path, 'base' => $base, 'seo' => $seo, 'landing' => $page->layout === 'landing',
            'chrome' => Chrome::for($brokerage, $tenantId, collect($blocks)->pluck('listings')->flatten(1)->pluck('disclaimer')->filter()->values()->all()),
            ...SiteController::form($request),
        ]);
    }
}
