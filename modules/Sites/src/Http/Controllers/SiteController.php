<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Consent;
use Modules\Crm\Enums\Source;
use Modules\Crm\Leads\LeadIntake;
use Modules\Crm\Models\LeadSource;
use Modules\Listings\Search\ListingSearch;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;
use Modules\Sites\PoweredBy;
use Modules\Sites\Rendering\Seo;
use Modules\Sites\Sections;

/**
 * Public website pages (specs/websites/05): rendered on the server as complete HTML (readable by every crawler,
 * including AI answer engines), cached at the edge, with structured data, robots.txt, sitemap.xml and llms.txt.
 */
final class SiteController
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function page(Request $request, ?string $path = null): Response
    {
        $site = $this->site();
        if ($site === null) {
            return $this->missing();
        }
        if ($site->status !== 'live') {
            return response()->view('sites::coming-soon', ['site' => $site], 200)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
        }
        $page = SitePage::query()->where('site_id', $site->id)->where('path', '/'.trim((string) $path, '/'))->where('status', 'published')->first();
        if ($page === null) {
            return $this->missing($site);
        }

        return $this->render($request, $site, $page, $page->sections, true)
            ->header('Cache-Control', 'public, max-age=60, s-maxage='.config('sites.cache_seconds').', stale-while-revalidate='.config('sites.stale_seconds'));
    }

    /** A signed, private preview of a page's draft (even before the site is live). */
    public function preview(Request $request, string $page): Response
    {
        abort_unless($request->hasValidSignature(false), 403);
        $row = SitePage::query()->where('ulid', $page)->with('site')->firstOrFail();

        return $this->render($request, $row->site, $row, $row->draft_sections ?? $row->sections, false)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function robots(Request $request): Response
    {
        $site = $this->site();
        $lines = ['User-agent: *'];
        if ($site === null || $site->status !== 'live') {
            $lines[] = 'Disallow: /';
        } else {
            $lines[] = 'Allow: /';
            $lines[] = 'Disallow: /_preview/';
            foreach ((array) config('sites.ai_crawlers') as $bot) {
                $lines[] = '';
                $lines[] = 'User-agent: '.$bot;
                $lines[] = $site->ai_crawlers ? 'Allow: /' : 'Disallow: /';
            }
            $lines[] = '';
            $lines[] = 'Sitemap: '.$request->getSchemeAndHttpHost().'/sitemap.xml';
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function sitemap(Request $request): Response
    {
        $site = $this->site();
        $base = $request->getSchemeAndHttpHost();
        $urls = [];
        if ($site !== null && $site->status === 'live') {
            foreach (SitePage::query()->where('site_id', $site->id)->where('status', 'published')->where('noindex', false)->orderBy('position')->get() as $p) {
                $urls[] = '<url><loc>'.e($base.$p->path).'</loc><lastmod>'.($p->updated_at?->toAtomString() ?? '').'</lastmod></url>';
            }
        }

        return response('<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('', $urls).'</urlset>', 200,
            ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=900']);
    }

    /** llms.txt (llmstxt.org): a short, plain summary of the brokerage and its key pages for AI assistants. */
    public function llms(Request $request): Response
    {
        $site = $this->site();
        if ($site === null || $site->status !== 'live') {
            return response("# Coming soon\n", 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        $base = $request->getSchemeAndHttpHost();
        $i = $site->info;
        $out = ['# '.$site->displayName(), ''];
        if (($i['tagline'] ?? '') !== '') {
            $out[] = '> '.$i['tagline'];
            $out[] = '';
        }
        if (($i['about'] ?? '') !== '') {
            $out[] = $i['about'];
            $out[] = '';
        }
        $facts = array_filter(['Areas served: '.($i['area'] ?? ''), 'Phone: '.($i['phone'] ?? ''), 'Email: '.($i['email'] ?? ''),
            'Office: '.trim(implode(', ', array_filter([$i['street'] ?? '', $i['city'] ?? '', trim(($i['state'] ?? '').' '.($i['zip'] ?? ''))]))), 'Brokerage licence: '.($i['licence'] ?? '')],
            fn (string $l): bool => ! str_ends_with($l, ': '));
        foreach ($facts as $f) {
            $out[] = '- '.$f;
        }
        $out[] = '';
        $out[] = '## Pages';
        foreach (SitePage::query()->where('site_id', $site->id)->where('status', 'published')->where('noindex', false)->orderBy('position')->get() as $p) {
            $out[] = '- ['.$p->title.']('.$base.$p->path.')'.($p->meta_description ? ': '.$p->meta_description : '');
        }
        $out[] = '';
        $out[] = 'Listings come from the MLS and are updated every few minutes. Listing information is deemed reliable but not guaranteed.';

        return response(implode("\n", $out)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    /** Contact and valuation forms on the website: a lead with its channel (AI search, SEO, …) and TCPA consent. */
    public function lead(Request $request, LeadIntake $intake): RedirectResponse
    {
        $site = $this->site();
        // Back to the page the form was on, on this website's own host (never another site).
        $back = $request->getSchemeAndHttpHost().'/'.ltrim(parse_url((string) $request->input('return', '/'), PHP_URL_PATH) ?: '/', '/');
        if ($site === null || trim((string) $request->input('website', '')) !== '') {
            return redirect($back.'?sent=1', 303); // honeypot
        }
        try {
            $shown = (int) Crypt::decryptString((string) $request->input('t', ''));
        } catch (\Throwable) {
            $shown = PHP_INT_MAX; // missing or forged: treat as a bot
        }
        if (time() - $shown < 3 || ! RateLimiter::attempt('site-lead:'.$request->ip(), 10, fn (): bool => true)) {
            return redirect($back.'?sent=1', 303); // too fast for a person, or too many
        }
        $source = LeadSource::query()->firstOrCreate(['kind' => 'form', 'name' => 'Website'],
            ['source' => Source::Website, 'token' => Str::random(40), 'consent_wording' => Consent::DEFAULT_SMS_WORDING, 'heading' => 'Contact us', 'active' => true]);
        $in = ['name' => (string) $request->input('name', ''), 'email' => (string) $request->input('email', ''), 'phone' => (string) $request->input('phone', ''),
            'message' => mb_substr((string) $request->input('message', ''), 0, 4000), 'intent' => (string) $request->input('intent', ''),
            'referrer' => mb_substr((string) $request->input('first_referrer', ''), 0, 500), 'landing_url' => mb_substr((string) $request->input('landing_url', ''), 0, 500),
            'sms_consent' => $request->boolean('sms_consent'), 'consent_text' => (string) $source->consent_wording];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid'] as $k) {
            if (is_string($request->input($k)) && $request->input($k) !== '') {
                $in[$k] = mb_substr((string) $request->input($k), 0, 150);
            }
        }
        try {
            $intake->receive($source, $in, $request);
        } catch (ValidationException $e) {
            return redirect($back.'?error='.urlencode((string) (collect($e->errors())->flatten()->first() ?? 'Please check the form.')).'#contact', 303);
        }

        return redirect($back.'?sent=1#contact', 303);
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function render(Request $request, Site $site, SitePage $page, array $sections, bool $indexable): Response
    {
        $tenantId = $this->tenants->id();
        $blocks = [];
        foreach (Sections::clean($sections) as $s) {
            if ($s['type'] === 'listings') {
                $p = $s['props'];
                $s['listings'] = app(ListingSearch::class)->search($tenantId, array_filter(['q' => $p['q'] ?? '', 'min_price' => $p['min_price'] ?? null,
                    'max_price' => $p['max_price'] ?? null, 'sort' => $p['sort'] ?? 'newest', 'per_page' => $p['limit'] ?? 6]))['results'];
            }
            $blocks[] = $s;
        }
        $menu = SitePage::query()->where('site_id', $site->id)->where('status', 'published')->where('in_menu', true)->orderBy('position')->get(['path', 'title']);
        $page->sections = $sections;

        return response()->view('sites::page', [
            'site' => $site, 'page' => $page, 'blocks' => $blocks, 'menu' => $menu, 'base' => $request->getSchemeAndHttpHost(),
            'seo' => Seo::forPage($site, $page, $request->getSchemeAndHttpHost(), $indexable),
            'powered' => app(PoweredBy::class)->forTenant($tenantId), 'sent' => $request->query('sent') === '1',
            'formError' => is_string($request->query('error')) ? mb_substr((string) $request->query('error'), 0, 200) : null,
            't' => Crypt::encryptString((string) time()), 'year' => CarbonImmutable::now()->year, 'consent' => Consent::DEFAULT_SMS_WORDING,
            'disclaimers' => collect($blocks)->pluck('listings')->flatten(1)->pluck('disclaimer')->filter()->unique()->values()->all(),
        ]);
    }

    private function site(): ?Site
    {
        return Site::query()->where('kind', 'brokerage')->first();
    }

    private function missing(?Site $site = null): Response
    {
        return response()->view('sites::missing', ['site' => $site], 404)->header('Cache-Control', 'public, max-age=60');
    }
}
