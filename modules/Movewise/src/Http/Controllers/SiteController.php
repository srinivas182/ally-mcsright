<?php

declare(strict_types=1);

namespace Modules\Movewise\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Connections;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Models\Listing;
use Modules\Listings\Search\ListingSearch;
use Modules\Movewise\Models\State;
use Modules\Movewise\MonthlyCost;
use Modules\Movewise\Movewise;

/**
 * Movewise's public pages (specs/movewise/01, MW1): home, search, each home, state pages, the legal pages, robots and
 * sitemaps. Server-rendered for search engines and AI answers (rightally-seo-aeo-standard): one canonical address
 * per page, schema.org markup, and plain-English answers. No sign-in, no cookies, nothing asked of the visitor.
 */
final class SiteController
{
    private const PER_PAGE = 24;

    public function __construct(private readonly Movewise $movewise, private readonly ListingSearch $search) {}

    /**
     * What every page needs: the states that are on, each with its broker of record, and the notices they require.
     *
     * @return array{base: string, states: list<array{code: string, name: string, slug: string, partner: array{name: string, broker: string, licence: string, phone: string|null, email: string|null, iabs: string|null}|null}>, year: int}
     */
    public function chrome(): array
    {
        $states = [];
        foreach (State::query()->with('partner')->where('on', true)->orderBy('name')->get() as $s) {
            $p = $s->partner;
            $states[] = ['code' => $s->code, 'name' => $s->name, 'slug' => Str::slug($s->name), 'partner' => $p === null ? null : ['name' => $p->name, 'broker' => $p->broker_name,
                'licence' => $p->licence_number, 'phone' => $p->phone, 'email' => $p->email, 'iabs' => $p->iabs_url]];
        }

        return ['base' => 'https://'.config('movewise.host'), 'states' => $states, 'year' => (int) CarbonImmutable::now()->format('Y')];
    }

    public function home(): Response
    {
        $site = $this->chrome();
        if ($site['states'] === []) {
            return $this->view('movewise::soon', ['site' => $site, 'seo' => $this->seo($site, '/', 'Movewise Homes: home search without the spam', 'A home search and moving site for buyers and sellers. Coming soon.', false)]);
        }
        $tenant = $this->movewise->feedTenant();
        $newest = $tenant !== null ? $this->search->search($tenant->id, ['states' => array_column($site['states'], 'code'), 'per_page' => 8, 'sort' => 'newest'])['results'] : [];

        return $this->view('movewise::home', ['site' => $site, 'newest' => array_map(fn (array $h): array => $this->card($h), $newest),
            'seo' => $this->seo($site, '/', 'Movewise Homes: search homes for sale without the spam', 'Search every home for sale in '.$this->names($site).
                '. See the true monthly cost and the risks in plain English. Nobody contacts you unless you choose an agent.', true,
                [['@type' => 'WebSite', 'url' => $site['base'].'/', 'name' => 'Movewise Homes', 'potentialAction' => ['@type' => 'SearchAction',
                    'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $site['base'].'/homes?q={search_term_string}'], 'query-input' => 'required name=search_term_string']]],
                [['How is Movewise paid?', 'Agents pay Movewise for an introduction, only after you choose them and tap Connect. We never sell your information to a crowd, and we don\'t push our own lender, title company or insurer.'],
                    ['Will agents call me if I search here?', 'No. Nobody contacts you until you choose one agent and tap Connect. You never need a phone number to see homes or get answers.']])]);
    }

    public function search(Request $request): Response
    {
        $site = $this->chrome();
        $tenant = $this->movewise->feedTenant();
        $on = array_column($site['states'], 'code');
        $state = strtoupper((string) $request->query('state', ''));
        $states = in_array($state, $on, true) ? [$state] : $on;
        $f = $request->only(['q', 'min_price', 'max_price', 'beds', 'baths', 'sort', 'page']) + ['states' => $states, 'per_page' => self::PER_PAGE];
        $found = $tenant !== null && $on !== [] ? $this->search->search($tenant->id, $f) : ['results' => [], 'total' => 0, 'page' => 1, 'per_page' => self::PER_PAGE, 'filters' => []];
        $filtered = array_filter($request->only(['q', 'min_price', 'max_price', 'beds', 'baths', 'sort', 'page', 'state']), fn ($v): bool => $v !== null && $v !== '') !== [];
        $title = trim(($request->query('q') ? '"'.mb_substr((string) $request->query('q'), 0, 40).'" ' : '').'Homes for sale'.(in_array($state, $on, true) ? ' in '.$this->stateName($state) : ''));

        return $this->view('movewise::search', ['site' => $site, 'results' => array_map(fn (array $h): array => $this->card($h), $found['results']), 'total' => $found['total'],
            'page' => $found['page'], 'pages' => (int) max(1, ceil($found['total'] / self::PER_PAGE)), 'query' => $request->query(), 'state' => $state,
            // Filtered and paged results aren't indexed; the state and city pages are the indexable entry points.
            'seo' => $this->seo($site, '/homes', $title.' | Movewise Homes', 'Every home for sale in '.$this->names($site).', fresh from the MLS, with the true monthly cost of each.', ! $filtered)]);
    }

    public function listing(string $listing): Response
    {
        $site = $this->chrome();
        $tenant = $this->movewise->feedTenant();
        $home = $tenant !== null ? $this->search->find($tenant->id, $listing) : null;
        if ($home === null || ! in_array((string) ($home['state'] ?? ''), array_column($site['states'], 'code'), true)) {
            return $this->view('movewise::missing', ['site' => $site], 404);
        }
        $label = ListingSearch::label($home);
        $path = '/homes/'.$home['ulid'].'/'.Str::slug(mb_substr($label, 0, 120));
        $price = (int) ($home['price'] ?? 0);
        $cost = $price > 0 ? MonthlyCost::for($price, (string) $home['state']) : null;
        $updated = Listing::query()->where('ulid', $home['ulid'])->value('modification_timestamp');
        $node = array_filter(['@type' => 'RealEstateListing', 'name' => $label ?: 'Home for sale', 'url' => $site['base'].$path, 'datePosted' => $home['listed'] ?? null,
            'image' => array_slice(array_column($home['photos'], 'hero'), 0, 6) ?: null, 'description' => isset($home['remarks']) ? mb_substr((string) $home['remarks'], 0, 500) : null,
            'offers' => $price > 0 ? ['@type' => 'Offer', 'price' => $price, 'priceCurrency' => 'USD', 'availability' => 'https://schema.org/InStock'] : null,
            'about' => array_filter(['@type' => 'SingleFamilyResidence', 'numberOfRooms' => $home['beds'] ?? null,
                'floorSize' => isset($home['sqft']) ? ['@type' => 'QuantitativeValue', 'value' => $home['sqft'], 'unitCode' => 'FTK'] : null,
                'address' => ['@type' => 'PostalAddress', 'streetAddress' => $home['address'] ?? null, 'addressLocality' => $home['city'] ?? null, 'addressRegion' => $home['state'] ?? null,
                    'postalCode' => $home['postal_code'] ?? null, 'addressCountry' => 'US']])], fn ($v): bool => $v !== null);

        return $this->view('movewise::listing', ['site' => $site, 'home' => $home, 'label' => $label, 'cost' => $cost,
            'updated' => $updated !== null ? CarbonImmutable::parse((string) $updated) : null,
            'seo' => $this->seo($site, $path, ($label ?: 'Home for sale').($price > 0 ? ' | $'.number_format($price) : '').' | Movewise Homes',
                trim(implode(', ', array_filter([isset($home['beds']) ? $home['beds'].' beds' : null, isset($home['sqft']) ? number_format((int) $home['sqft']).' sq ft' : null,
                    $home['city'] ?? null])).'. See the photos, the true monthly cost and the risks in plain English.'), true, [$node], [], [['Homes for sale', '/homes'],
                        [$this->stateName((string) $home['state']), '/'.Str::slug($this->stateName((string) $home['state']))], [$label, $path]])]);
    }

    public function state(string $state): Response
    {
        $site = $this->chrome();
        $s = collect($site['states'])->firstWhere('slug', $state);
        if ($s === null) {
            return $this->view('movewise::missing', ['site' => $site], 404);
        }
        $tenant = $this->movewise->feedTenant();
        $found = $tenant !== null ? $this->search->search($tenant->id, ['states' => [$s['code']], 'per_page' => 12, 'sort' => 'newest']) : ['results' => [], 'total' => 0];
        $cities = $tenant !== null ? $this->cities($tenant->id, $s['code']) : [];

        return $this->view('movewise::state', ['site' => $site, 'state' => $s, 'results' => array_map(fn (array $h): array => $this->card($h), $found['results']),
            'total' => $found['total'], 'cities' => $cities,
            'seo' => $this->seo($site, '/'.$s['slug'], 'Homes for sale in '.$s['name'].' | Movewise Homes', number_format($found['total']).' homes for sale in '.$s['name'].
                ', fresh from the MLS. See the true monthly cost of each, with no spam and no sign-up.', true, [], [
                    ['How many homes are for sale in '.$s['name'].' on Movewise?', 'There are '.number_format($found['total']).' homes for sale in '.$s['name'].' right now, updated from the MLS through the day.'],
                    ['Who is the broker for Movewise in '.$s['name'].'?', $s['partner'] !== null ? $s['partner']['name'].' (licence '.$s['partner']['licence'].') is the broker of record for Movewise in '.$s['name'].'.' : 'Movewise\'s managing broker in '.$s['name'].' is listed on the Brokers page.'],
                ], [[$s['name'], '/'.$s['slug']]])]);
    }

    public function page(string $page): Response
    {
        $site = $this->chrome();
        $titles = ['how-movewise-is-paid' => 'How Movewise is paid', 'privacy' => 'Privacy notice', 'terms' => 'Terms of use', 'fair-housing' => 'Fair Housing',
            'brokers' => 'Brokers of record and licences', 'accessibility' => 'Accessibility'];

        return $this->view('movewise::pages.'.$page, ['site' => $site, 'title' => $titles[$page],
            'seo' => $this->seo($site, '/'.$page, $titles[$page].' | Movewise Homes', $titles[$page].' for Movewise Homes, in plain English.', true, [], [], [[$titles[$page], '/'.$page]])]);
    }

    public function robots(): Response
    {
        $site = $this->chrome();

        return response("User-agent: *\nAllow: /\nDisallow: /homes?\n\nSitemap: ".$site['base']."/sitemap.xml\n", 200, ['Content-Type' => 'text/plain', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function sitemap(): Response
    {
        $site = $this->chrome();
        $now = CarbonImmutable::now()->toAtomString();
        $xml = '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        $xml .= '<sitemap><loc>'.e($site['base'].'/sitemaps/pages.xml').'</loc><lastmod>'.$now.'</lastmod></sitemap>';
        foreach ($site['states'] as $s) {
            $xml .= '<sitemap><loc>'.e($site['base'].'/sitemaps/'.strtolower($s['code']).'.xml').'</loc><lastmod>'.$now.'</lastmod></sitemap>';
        }

        return response($xml.'</sitemapindex>', 200, ['Content-Type' => 'application/xml', 'Cache-Control' => 'public, max-age=3600']);
    }

    /** One state's homes (or, for "pages", the site's own pages), up to the 50,000 a sitemap may hold. */
    public function stateSitemap(string $state): Response
    {
        $site = $this->chrome();
        $urls = [];
        if ($state === 'pages') {
            foreach (['/', '/homes', '/how-movewise-is-paid', '/brokers', '/fair-housing', '/privacy', '/terms', '/accessibility', ...array_map(fn (array $s): string => '/'.$s['slug'], $site['states'])] as $p) {
                $urls[] = [$site['base'].$p, null];
            }
        } else {
            $code = strtoupper($state);
            $tenant = $this->movewise->feedTenant();
            if (! in_array($code, array_column($site['states'], 'code'), true) || $tenant === null) {
                return $this->view('movewise::missing', ['site' => $site], 404);
            }
            $sources = app(MlsAccess::class)->licensedSources($tenant->id);
            foreach (Listing::query()->whereIn('mls_source_id', $sources)->where('state_or_province', $code)->where('internet_display_allowed', true)->whereNull('off_market_at')
                ->whereIn('standard_status', ['Active', 'Coming Soon'])->orderByDesc('id')->limit(50_000)->get(['ulid', 'street_number', 'street_name', 'unit_number', 'city', 'state_or_province',
                    'postal_code', 'address_display_allowed', 'modification_timestamp']) as $l) {
                $label = trim(($l->address_display_allowed ? $l->address().' ' : '').$l->city.' '.$l->state_or_province.' '.$l->postal_code);
                $urls[] = [$site['base'].'/homes/'.$l->ulid.'/'.Str::slug(mb_substr($label, 0, 120)), $l->modification_timestamp !== null ? CarbonImmutable::parse((string) $l->modification_timestamp)->toAtomString() : null];
            }
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as [$loc, $mod]) {
            $xml .= '<url><loc>'.e($loc).'</loc>'.($mod !== null ? '<lastmod>'.$mod.'</lastmod>' : '').'</url>';
        }

        return response($xml.'</urlset>', 200, ['Content-Type' => 'application/xml', 'Cache-Control' => 'public, max-age=3600']);
    }

    /**
     * @param  array<string, mixed>  $h
     * @return array<string, mixed>
     */
    private function card(array $h): array
    {
        $label = ListingSearch::label($h);

        return $h + ['label' => $label, 'href' => '/homes/'.$h['ulid'].'/'.Str::slug(mb_substr($label, 0, 120))];
    }

    /** @return list<array{city: string, count: int}> */
    private function cities(int $tenantId, string $state): array
    {
        $sources = app(MlsAccess::class)->licensedSources($tenantId);

        return array_values(DB::connection(Connections::listings())->table('listings')->whereIn('mls_source_id', $sources)->where('state_or_province', $state)->where('internet_display_allowed', true)
            ->whereNull('off_market_at')->whereIn('standard_status', ['Active', 'Coming Soon'])->whereNotNull('city')->groupBy('city')->orderByDesc(DB::raw('count(*)'))->limit(24)
            ->get([DB::raw('city'), DB::raw('count(*) as n')])->map(fn ($r): array => ['city' => (string) $r->city, 'count' => (int) $r->n])->all());
    }

    private function stateName(string $code): string
    {
        return (string) (config('movewise.launch_states')[$code] ?? $code);
    }

    /** @param  array{states: list<array{name: string}>}  $site */
    private function names(array $site): string
    {
        $n = array_column($site['states'], 'name');

        return count($n) > 1 ? implode(', ', array_slice($n, 0, -1)).' and '.end($n) : ($n[0] ?? 'the US');
    }

    /**
     * @param  array{base: string}  $site
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array{0: string, 1: string}>  $faqs
     * @param  list<array{0: string, 1: string}>  $crumbs
     * @return array<string, mixed>
     */
    private function seo(array $site, string $path, string $title, string $description, bool $indexable, array $nodes = [], array $faqs = [], array $crumbs = []): array
    {
        $graph = [['@type' => 'Organization', '@id' => $site['base'].'/#organization', 'name' => 'Movewise Homes', 'url' => $site['base'].'/'], ...$nodes];
        if ($crumbs !== []) {
            $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => array_map(fn (array $c, int $i): array => ['@type' => 'ListItem', 'position' => $i + 2, 'name' => $c[0],
                'item' => $site['base'].$c[1]], $crumbs, array_keys($crumbs))];
            array_unshift($graph[count($graph) - 1]['itemListElement'], ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $site['base'].'/']);
        }
        if ($faqs !== []) {
            $graph[] = ['@type' => 'FAQPage', 'mainEntity' => array_map(fn (array $f): array => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]], $faqs)];
        }

        return ['title' => mb_substr($title, 0, 70), 'description' => mb_substr($description, 0, 160), 'canonical' => $site['base'].$path, 'indexable' => $indexable,
            'jsonld' => json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG), 'faqs' => $faqs];
    }

    /** @param  array<string, mixed>  $data */
    private function view(string $view, array $data, int $status = 200): Response
    {
        return response()->view($view, $data, $status)->header('Cache-Control', $status === 200 ? 'public, max-age=60, s-maxage=300' : 'public, max-age=60');
    }
}
