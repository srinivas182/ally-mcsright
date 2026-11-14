<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Consent;
use Modules\Listings\Search\ListingSearch;
use Modules\Listings\Search\MarketStats;
use Modules\Sites\AgentSites;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;
use Modules\Sites\Models\SitePost;
use Modules\Sites\Rendering\PageRenderer;
use Modules\Sites\Rendering\Seo;
use Modules\Sites\Rendering\SiteContext;
use Modules\Sites\Rendering\Urls;
use Modules\Sites\Visitors\SiteLeads;

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
        $owner = app(SiteContext::class)->agent() ?? $site;
        $page = SitePage::query()->where('site_id', $owner->id)->where('path', '/'.trim((string) $path, '/'))->where('status', 'published')->first();
        if ($page === null) {
            return $this->missing($site);
        }

        return $this->render($request, $owner, $page, $page->sections, true)
            ->header('Cache-Control', 'public, max-age=60, s-maxage='.config('sites.cache_seconds').', stale-while-revalidate='.config('sites.stale_seconds'));
    }

    /** A signed, private preview of a page's draft (even before the site is live). */
    public function preview(Request $request, string $page): Response
    {
        abort_unless($request->hasValidSignature(false), 403);
        $row = SitePage::query()->where('ulid', $page)->with('site')->firstOrFail();
        if ($row->site->kind === 'agent') {
            app(SiteContext::class)->enterAgent($row->site, '/agents/'.$row->site->slug);
        }

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
            $tenantId = $this->tenants->id();
            $places = app(MarketStats::class)->places($tenantId);
            $urls[] = '<url><loc>'.e($base.'/homes').'</loc></url>';
            $agents = app(AgentSiteController::class)->live();
            if ($agents !== []) {
                $urls[] = '<url><loc>'.e($base.'/agents').'</loc></url>';
            }
            foreach ($agents as $agent) {
                foreach (SitePage::query()->where('site_id', $agent->id)->where('status', 'published')->where('noindex', false)->orderBy('position')->get(['path', 'updated_at']) as $p) {
                    $urls[] = '<url><loc>'.e($base.'/agents/'.$agent->slug.($p->path === '/' ? '' : $p->path)).'</loc></url>';
                }
            }
            $posts = SitePost::query()->where('site_id', $site->id)->live()->where('noindex', false)->orderByDesc('published_at')->limit(5000)->get(['slug', 'updated_at']);
            if ($posts->isNotEmpty()) {
                $urls[] = '<url><loc>'.e($base.'/blog').'</loc></url>';
            }
            foreach ($posts as $post) {
                $urls[] = '<url><loc>'.e($base.'/blog/'.$post->slug).'</loc><lastmod>'.($post->updated_at?->toAtomString() ?? '').'</lastmod></url>';
            }
            if ($places !== []) {
                $urls[] = '<url><loc>'.e($base.'/market').'</loc></url>';
            }
            foreach ($places as $place) {
                $urls[] = '<url><loc>'.e($base.Urls::market($place['slug'])).'</loc></url>';
            }
            // Homes for sale (newest 5,000; a sitemap index takes over beyond that).
            $search = app(ListingSearch::class);
            $listings = $search->query($tenantId, ListingSearch::clean([]))->limit(5000)
                ->get(['ulid', 'street_number', 'street_name', 'unit_number', 'city', 'state_or_province', 'postal_code', 'address_display_allowed', 'modification_timestamp']);
            foreach ($listings as $l) {
                $label = trim(implode(' ', array_filter([$l->address_display_allowed ? $l->address() : null, $l->city, $l->state_or_province, $l->postal_code])));
                $urls[] = '<url><loc>'.e($base.Urls::listing($l->ulid, $label)).'</loc>'.($l->modification_timestamp !== null ? '<lastmod>'.$l->modification_timestamp->toAtomString().'</lastmod>' : '').'</url>';
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
        $out[] = '- [Homes for sale]('.$base.'/homes): every home for sale from the MLS, searchable by map, price and size';
        $agents = app(AgentSiteController::class)->live();
        if ($agents !== []) {
            $out[] = '';
            $out[] = '## Our agents';
            foreach (array_slice($agents, 0, 30) as $agent) {
                $p = AgentSites::profile($agent);
                $out[] = '- ['.$p['name'].']('.$base.'/agents/'.$agent->slug.')'.($p['areas'] !== [] ? ': '.implode(', ', $p['areas']) : '')
                    .($p['languages'] !== [] && $p['languages'] !== ['English'] ? ' (speaks '.implode(', ', $p['languages']).')' : '');
            }
        }
        $posts = SitePost::query()->where('site_id', $site->id)->live()->where('noindex', false)->orderByDesc('published_at')->limit(15)->get(['slug', 'title', 'excerpt']);
        if ($posts->isNotEmpty()) {
            $out[] = '';
            $out[] = '## Articles';
            foreach ($posts as $post) {
                $out[] = '- ['.$post->title.']('.$base.'/blog/'.$post->slug.')'.($post->excerpt ? ': '.$post->excerpt : '');
            }
        }
        $places = array_slice(app(MarketStats::class)->places($this->tenants->id()), 0, 12);
        if ($places !== []) {
            $out[] = '';
            $out[] = '## Market reports';
            foreach ($places as $place) {
                $m = app(MarketStats::class)->forPlace($this->tenants->id(), $place['slug']);
                if ($m !== null) {
                    $out[] = '- ['.$m['name'].']('.$base.Urls::market($m['slug']).'): '.number_format($m['active']).' homes for sale'
                        .($m['median_price'] !== null ? ', median list price $'.number_format($m['median_price']) : '').($m['median_dom'] !== null ? ', typically '.$m['median_dom'].' days on market' : '')
                        .' (as of '.$m['as_of'].')';
                }
            }
        }
        $out[] = '';
        $out[] = 'Listings come from the MLS and are updated every few minutes. Listing information is deemed reliable but not guaranteed.';

        return response(implode("\n", $out)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    /** Contact and valuation forms on the website: a lead with its channel (AI search, SEO, …) and TCPA consent. */
    public function lead(Request $request, SiteLeads $leads): RedirectResponse
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
        // A landing page's leads are reported under that landing page.
        $landing = SitePage::query()->where('site_id', $site->id)->where('layout', 'landing')->where('path', '/'.ltrim((string) parse_url($back, PHP_URL_PATH), '/'))->first();
        $source = $landing !== null ? SiteLeads::source(mb_substr('Landing page: '.$landing->title, 0, 120), $landing->title) : SiteLeads::source('Website', 'Contact us');
        $in = ['name' => (string) $request->input('name', ''), 'email' => (string) $request->input('email', ''), 'phone' => (string) $request->input('phone', ''),
            'message' => mb_substr((string) $request->input('message', ''), 0, 4000), 'intent' => (string) $request->input('intent', ''),
            'referrer' => mb_substr((string) $request->input('first_referrer', ''), 0, 500), 'landing_url' => mb_substr((string) $request->input('landing_url', ''), 0, 500),
            'sms_consent' => $request->boolean('sms_consent'), 'consent_text' => (string) $source->consent_wording];
        // An enquiry from a listing page names the home (looked up here, never trusted from the form).
        $listing = $request->input('listing');
        $home = null;
        if (is_string($listing) && preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $listing) === 1) {
            $home = app(ListingSearch::class)->find($this->tenants->id(), $listing);
            if ($home !== null) {
                $about = 'About '.(ListingSearch::label($home) ?: 'a listing').($home['mls_number'] ? ' (MLS# '.$home['mls_number'].')' : '')
                    .($home['price'] !== null ? ', listed at $'.number_format((int) $home['price']) : '');
                $in['message'] = mb_substr($about.".\n".$in['message'], 0, 4000);
                $in['intent'] = $in['intent'] !== '' ? $in['intent'] : 'buy';
                $in['price'] = (string) ($home['price'] ?? '');
                $in['zip'] = (string) ($home['postal_code'] ?? '');
            }
        }
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid'] as $k) {
            if (is_string($request->input($k)) && $request->input($k) !== '') {
                $in[$k] = mb_substr((string) $request->input($k), 0, 150);
            }
        }
        try {
            $leads->capture($site, $source, $in, $request, 'lead', $home !== null ? (string) $home['ulid'] : null, $home !== null ? ['label' => ListingSearch::label($home)] : []);
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
        $brokerage = $site->kind === 'brokerage' ? $site : ($this->site() ?? $site);

        return app(PageRenderer::class)->render($request, $brokerage, $site, $page, $sections, $indexable);
    }

    /**
     * The lead form's state on any page: sent / error after a submission, the encrypted time trap, consent wording.
     *
     * @return array{sent: bool, formError: string|null, t: string, consent: string}
     */
    public static function form(Request $request): array
    {
        return ['sent' => $request->query('sent') === '1', 'formError' => is_string($request->query('error')) ? mb_substr((string) $request->query('error'), 0, 200) : null,
            't' => Crypt::encryptString((string) time()), 'consent' => Consent::DEFAULT_SMS_WORDING];
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
