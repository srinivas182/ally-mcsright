<?php

declare(strict_types=1);

namespace Modules\Marketing\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Modules\Billing\Models\AgentPlan;
use Modules\Billing\Models\Package;
use Modules\Marketing\LegalDefaults;
use Modules\Marketing\PlanIncludes;
use Modules\Marketing\Showcase;
use Modules\Marketing\SiteChrome;
use Modules\Marketing\Website;
use Modules\Sites\Content\Posts;
use stdClass;

/**
 * RightAlly's public website (SG1, specs/marketing/01), server-rendered, cookie-free and cacheable at the edge. Every
 * page has its canonical address, English and Spanish alternates, Open Graph tags and JSON-LD (Organization,
 * SoftwareApplication with live prices, FAQPage, BreadcrumbList, BlogPosting), and AI answer engines are welcome.
 */
final class SiteController
{
    public function __construct(private readonly Website $site) {}

    public function page(Request $request): Response|RedirectResponse
    {
        [$locale, $slug] = $this->where($request);
        if (($redirect = $this->canonicalHost($request)) !== null) {
            return $redirect;
        }
        $page = $this->site->page($locale, trim($slug, '/'));
        if ($page === null || $page->status !== 'live' || $page->blocks === null) {
            // SITE1: the default privacy policy and terms until HQ publishes its own.
            $legal = LegalDefaults::page($locale, trim($slug, '/'));

            return $legal !== null ? $this->render($locale, trim($slug, '/'), $legal['title'], $legal['description'], $legal['blocks'], false) : $this->missing($locale);
        }

        return $this->render($page->locale, $page->slug, (string) $page->title, (string) $page->description, $this->blocks($page->blocks), false);
    }

    /** A signed, private preview of a draft for HQ (never indexed). */
    public function preview(Request $request, string $ulid): Response
    {
        abort_unless(URL::hasValidSignature($request), 403);
        $page = collect($this->site->pages())->firstWhere('ulid', $ulid);
        abort_if(! $page instanceof stdClass, 404);
        $blocks = $page->draft_blocks ?? $page->blocks;

        return $this->render((string) $page->locale, (string) $page->slug, (string) ($page->draft_title ?? $page->title),
            (string) ($page->draft_description ?? $page->description), $this->blocks($blocks), true);
    }

    public function blog(Request $request): Response|RedirectResponse
    {
        [$locale] = $this->where($request);
        if (($redirect = $this->canonicalHost($request)) !== null) {
            return $redirect;
        }
        $posts = $this->site->livePosts($locale);
        $title = $locale === 'es' ? 'Blog de RightAlly: guías para corredurías' : 'RightAlly blog: guides for brokerages';
        $description = $locale === 'es' ? 'Guías prácticas para dueños de corredurías y agentes: compensación, cumplimiento, prospectos y tecnología.'
            : 'Practical guides for brokerage owners and agents: compensation, compliance, leads and technology, in plain words.';

        return $this->view('marketing::blog', $locale, 'blog', $title, $description, false, ['posts' => $posts], [
            ['@type' => 'Blog', 'name' => $title, 'url' => $this->url($locale, 'blog'), 'inLanguage' => $locale],
        ]);
    }

    public function post(Request $request): Response|RedirectResponse
    {
        [$locale, $slug] = $this->where($request);
        if (($redirect = $this->canonicalHost($request)) !== null) {
            return $redirect;
        }
        $post = $this->site->livePost($locale, $slug);
        if ($post === null) {
            return $this->missing($locale);
        }
        $rendered = Posts::render((string) $post->body);
        $url = $this->url($locale, 'blog/'.$post->slug);

        return $this->view('marketing::post', $locale, 'blog/'.$post->slug, (string) $post->title, (string) $post->description, false, ['post' => $post, 'rendered' => $rendered], [
            ['@type' => 'BlogPosting', 'headline' => $post->title, 'description' => $post->description, 'author' => ['@type' => 'Person', 'name' => $post->author],
                'datePublished' => (string) $post->published_at, 'dateModified' => (string) $post->updated_at, 'inLanguage' => $locale, 'mainEntityOfPage' => $url,
                'publisher' => ['@type' => 'Organization', 'name' => 'RightAlly']],
        ]);
    }

    public function sitemap(): Response
    {
        $xml = Cache::remember('marketing:sitemap', 600, function (): string {
            $urls = [];
            foreach ($this->site->pages() as $p) {
                if ($p->status === 'live') {
                    $urls[] = [$this->url((string) $p->locale, (string) $p->slug), (string) ($p->published_at ?? $p->updated_at)];
                }
            }
            foreach (array_keys(Website::LOCALES) as $locale) {
                foreach ($this->site->livePosts($locale, 1000) as $post) {
                    $urls[] = [$this->url($locale, 'blog/'.$post->slug), (string) $post->updated_at];
                }
            }
            $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach ($urls as [$loc, $mod]) {
                $out .= '<url><loc>'.e($loc).'</loc><lastmod>'.e(substr($mod, 0, 10)).'</lastmod></url>';
            }

            return $out.'</urlset>';
        });

        return response($xml, 200, ['Content-Type' => 'application/xml', 'Cache-Control' => 'public, max-age=600']);
    }

    public function robots(): Response
    {
        $lines = ['User-agent: *', 'Allow: /', 'Disallow: /preview/', ''];
        foreach (['GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'PerplexityBot', 'ClaudeBot', 'Claude-SearchBot', 'Google-Extended', 'Applebot-Extended', 'Bingbot'] as $bot) {
            $lines[] = 'User-agent: '.$bot;
            $lines[] = 'Allow: /';
            $lines[] = '';
        }
        $lines[] = 'Sitemap: '.$this->base().'/sitemap.xml';

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain', 'Cache-Control' => 'public, max-age=3600']);
    }

    /** llms.txt: a plain summary of RightAlly and its key pages for AI answer engines. */
    public function llms(): Response
    {
        $lines = ['# RightAlly', '', '> RightAlly is an all-in-one platform for US real estate brokerages: website and IDX, CRM and lead follow-up, transactions and compliance, '
            .'commission and agent payouts, accounting, recruiting and training, with AI assistants built in. Brokerages sign up online with public prices.', ''];
        foreach (Website::LOCALES as $locale => $name) {
            $lines[] = '## Pages ('.$name.')';
            foreach ($this->site->pages() as $p) {
                if ($p->status === 'live' && $p->locale === $locale) {
                    $lines[] = '- ['.$p->title.']('.$this->url($locale, (string) $p->slug).'): '.$p->description;
                }
            }
            $lines[] = '';
        }
        $lines[] = '## Prices';
        foreach (Package::offered('brokerage') as $pkg) {
            $lines[] = '- '.$pkg->name.': $'.number_format(((int) $pkg->monthly_cents) / 100, 2).' a month';
        }
        $lines[] = '';
        $lines[] = '## Start';
        $lines[] = '- [Sign up online](https://'.config('core.hq_host').'/start)';
        $lines[] = '- [Free Brokerage Health Check](https://'.config('core.hq_host').'/health-check)';

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function render(string $locale, string $slug, string $title, string $description, array $blocks, bool $preview): Response
    {
        $graph = [];
        $faq = [];
        foreach ($blocks as $b) {
            if ($b['type'] === 'faq') {
                foreach ($b['items'] as $i) {
                    $faq[] = ['@type' => 'Question', 'name' => $i['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $i['a']]];
                }
            }
            if ($b['type'] === 'answer') {
                $faq[] = ['@type' => 'Question', 'name' => $b['question'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $b['answer']]];
            }
        }
        if ($faq !== []) {
            $graph[] = ['@type' => 'FAQPage', 'mainEntity' => $faq];
        }
        // MK1: prices, included modules and the savings calculator read live from the package builder, per audience.
        $audiences = [];
        foreach ($blocks as $b) {
            if (in_array($b['type'], ['pricing', 'modules', 'savings'], true)) {
                $audiences[(string) ($b['audience'] ?? 'brokerage')] = true;
            }
        }
        $byAudience = [];
        foreach (array_keys($audiences) as $a) {
            $byAudience[$a] = Package::offered($a);
        }
        $packages = in_array('pricing', array_column($blocks, 'type'), true) || $slug === '' ? ($byAudience['brokerage'] ?? Package::offered('brokerage')) : collect();
        $showcase = [];
        foreach ($byAudience as $a => $list) {
            $showcase[$a] = Showcase::for($a, $list);
        }
        if ($packages->isNotEmpty()) {
            $graph[] = ['@type' => 'SoftwareApplication', 'name' => 'RightAlly', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web, iOS, Android',
                'offers' => $packages->map(fn (Package $p): array => ['@type' => 'Offer', 'name' => $p->name, 'price' => number_format(((int) $p->monthly_cents) / 100, 2, '.', ''),
                    'priceCurrency' => 'USD', 'url' => 'https://'.config('core.hq_host').'/start'])->values()->all()];
        }

        // SITE1: the pricing page always shows agent prices too, even before its content is refreshed.
        if ($slug === 'pricing' && ! in_array('seats', array_column($blocks, 'type'), true)) {
            $at = array_key_last(array_filter($blocks, fn (array $b): bool => $b['type'] === 'pricing'));
            $seat = ['type' => 'seats', 'heading' => $locale === 'es' ? 'Para agentes' : 'For agents', 'note' => ''];
            $at === null ? $blocks[] = $seat : array_splice($blocks, $at + 1, 0, [$seat]);
        }
        // BR4: agent seat prices (through a brokerage, and on their own), live from HQ › Packages.
        $seats = in_array('seats', array_column($blocks, 'type'), true)
            ? AgentPlan::query()->where('active', true)->orderBy('sort')->get(['name', 'description', 'floor_cents', 'list_cents', 'features'])->all() : [];

        return $this->view('marketing::page', $locale, $slug, $title, $description, $preview, ['blocks' => $blocks, 'packages' => $packages, 'byAudience' => $byAudience, 'showcase' => $showcase, 'seats' => $seats,
            'includes' => PlanIncludes::forPackages($byAudience, $locale), 'seatIncludes' => PlanIncludes::forSeats($seats, $locale)], $graph);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $graph
     */
    private function view(string $view, string $locale, string $slug, string $title, string $description, bool $preview, array $data, array $graph): Response
    {
        $canonical = $this->url($locale, $slug);
        $twin = $locale === 'en' ? 'es' : 'en';
        $alternates = [$locale => $canonical];
        $hasTwin = str_starts_with($slug, 'blog') ? $slug === 'blog' : ($this->site->page($twin, $slug)?->status === 'live');
        if ($hasTwin) {
            $alternates[$twin] = $this->url($twin, $slug);
        }
        $alternates['x-default'] = $alternates['en'] ?? $canonical;
        $crumbs = [['@type' => 'ListItem', 'position' => 1, 'name' => 'RightAlly', 'item' => $this->url($locale, '')]];
        if ($slug !== '') {
            $crumbs[] = ['@type' => 'ListItem', 'position' => 2, 'name' => $title, 'item' => $canonical];
        }
        $org = ['@type' => 'Organization', '@id' => $this->base().'/#org', 'name' => 'RightAlly', 'url' => $this->base().'/', 'logo' => $this->base().'/icons/icon-512.png'];
        $sameAs = (array) config('marketing.organization.same_as', []);
        if ($sameAs !== []) {
            $org['sameAs'] = $sameAs;
        }
        $jsonld = json_encode(['@context' => 'https://schema.org', '@graph' => array_merge([$org, ['@type' => 'BreadcrumbList', 'itemListElement' => $crumbs]], $graph)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

        return response()->view($view, $data + ['L' => $locale, 'title' => $title, 'description' => $description, 'canonical' => $canonical, 'alternates' => $alternates,
            'jsonld' => $jsonld, 'preview' => $preview, 'home' => $this->url($locale, ''), 'start' => 'https://'.config('core.hq_host').'/start',
            'healthCheck' => 'https://'.config('core.hq_host').($locale === 'es' ? '/health-check/es' : '/health-check'), 'other' => $alternates[$twin] ?? $this->url($twin, ''),
            'nav' => $this->nav($locale), 'slug' => $slug, 'legal' => ['privacy' => $this->url($locale, 'privacy'), 'terms' => $this->url($locale, 'terms')]], 200)->header('Cache-Control', $preview ? 'no-store' : 'public, max-age=300')
            ->header('X-Robots-Tag', $preview ? 'noindex, nofollow' : 'index, follow');
    }

    /** @return list<array{label: string, href: string}> */
    private function nav(string $locale): array
    {
        return SiteChrome::nav($locale); // SITE2: shared with the sign-up and Health Check pages
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function blocks(mixed $json): array
    {
        $data = is_string($json) ? json_decode($json, true) : $json;

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    /**
     * The language and address from the route (route defaults and parameters, by name).
     *
     * @return array{0: string, 1: string}
     */
    private function where(Request $request): array
    {
        $locale = $request->route('locale') === 'es' ? 'es' : 'en';

        return [$locale, trim((string) ($request->route('slug') ?? ''), '/')];
    }

    private function missing(string $locale): Response
    {
        return response()->view('marketing::missing', ['L' => $locale, 'home' => $this->url($locale, '')], 404)->header('Cache-Control', 'public, max-age=60');
    }

    private function canonicalHost(Request $request): ?RedirectResponse
    {
        $hosts = (array) config('marketing.hosts');
        $main = (string) ($hosts[0] ?? '');

        return $main !== '' && $request->getHost() !== $main ? redirect()->away('https://'.$main.$request->getRequestUri(), 301) : null;
    }

    private function base(): string
    {
        return 'https://'.((array) config('marketing.hosts'))[0];
    }

    private function url(string $locale, string $slug): string
    {
        $path = trim(($locale === 'es' ? 'es/' : '').$slug, '/');

        return $this->base().'/'.$path;
    }
}
