<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\Core\Tenancy\TenantContext;
use Modules\Core\Usage\WebsiteMaps;
use Modules\Listings\Search\ListingSearch;
use Modules\Listings\Search\MarketStats;
use Modules\Listings\Search\PlainSearch;
use Modules\Sites\Maps;
use Modules\Sites\Models\Site;
use Modules\Sites\Rendering\Chrome;
use Modules\Sites\Rendering\Seo;
use Modules\Sites\Rendering\Urls;

/**
 * The website's IDX pages (specs/websites/06): map search, listing pages and market pages. Everything is complete
 * server-rendered HTML; the map is a small script on top. Every listing passes the MLS display rules
 * (ListingPresenter, via ListingSearch) and carries the MLS attribution and disclaimer.
 */
final class IdxController
{
    private const PER_PAGE = 24;

    /** Query keys a search page keeps in its web address. */
    private const KEYS = ['heard', 'q', 'min_price', 'max_price', 'beds', 'baths', 'min_sqft', 'types', 'statuses', 'bounds', 'poly', 'sort', 'page'];

    public function __construct(private readonly TenantContext $tenants, private readonly ListingSearch $search) {}

    public function search(Request $request): Response|RedirectResponse
    {
        $site = $this->site();
        if (! $site instanceof Site) {
            return $site;
        }
        $query = self::query($request);
        // Plain words ("3 bed homes under 500k in Austin", or in Spanish) become the normal filters in the address,
        // so the result is the same crawlable page the form makes (specs/websites/17).
        if (isset($query['q']) && PlainSearch::looksPlain($query['q'])) {
            $parsed = PlainSearch::parse($query['q']);
            if ($parsed['understood'] !== [] || $parsed['not_filtered'] !== []) {
                return redirect($request->getSchemeAndHttpHost().'/homes?'.http_build_query(['heard' => $query['q']] + $parsed['filters'] + Arr::except($query, ['q', 'page', 'heard'])), 302);
            }
        }
        $heard = isset($query['heard']) ? PlainSearch::parse($query['heard']) : null;
        $found = $this->search->search($this->tenants->id(), $query + ['per_page' => self::PER_PAGE]);
        $base = $request->getSchemeAndHttpHost();
        $where = $found['filters']['q'] !== '' ? ' in '.$found['filters']['q'] : '';
        $plain = Arr::except($query, ['page']) === [];
        $title = 'Homes for sale'.$where.' | '.self::suffix($site);
        $description = number_format($found['total']).' homes for sale'.$where.' from the MLS, updated every few minutes. Search by map, price, beds and more with '.$site->displayName().'.';

        return response()->view('sites::idx.search', [
            'site' => $site, 'current' => '/homes', 'found' => $found, 'query' => $query, 'heading' => self::heading($found['total'], $found['filters']['q']),
            'stale' => in_array(true, array_column($found['results'], 'stale'), true),
            'heard' => $heard === null ? null : ['words' => $query['heard'], 'understood' => PlainSearch::say($heard['understood'], $heard['language']),
                'not_filtered' => PlainSearch::say($heard['not_filtered'], $heard['language']), 'language' => $heard['language']],
            'pages' => self::pagination($found['total'], $found['page'], $query),
            'seo' => Seo::build($site, $base, '/homes'.($plain && $found['page'] > 1 ? '?page='.$found['page'] : ''), $title, $description, null,
                $plain && $found['total'] > 0, [['Homes for sale', '/homes']]),
            'chrome' => Chrome::for($site, $this->tenants->id(), array_column($found['results'], 'disclaimer')),
        ])->header('Cache-Control', 'public, max-age=60, s-maxage=120, stale-while-revalidate=600');
    }

    /** The search results and map pins as JSON for the map script (the cards come pre-rendered, escaped by Blade). */
    /**
     * A visitor asked for the interactive map: counted as one map view against the brokerage's monthly allowance
     * (specs/billing/03). Returns the public map token when the map may open.
     */
    public function openMap(Request $request, Maps $maps, WebsiteMaps $allowance): JsonResponse
    {
        $token = $maps->visitorToken();
        $ok = $token !== null && RateLimiter::attempt('site-map:'.$request->ip(), 30, fn (): bool => true) && $allowance->open();

        return response()->json(['ok' => $ok, 'token' => $ok ? $token : null])->header('Cache-Control', 'no-store');
    }

    public function results(Request $request): JsonResponse
    {
        $site = $this->site();
        if (! $site instanceof Site) {
            return response()->json(['error' => 'unavailable'], 404);
        }
        $query = self::query($request);
        $found = $this->search->search($this->tenants->id(), $query + ['per_page' => self::PER_PAGE]);
        $pins = $request->boolean('pins') ? $this->search->pins($this->tenants->id(), $query) : null;

        return response()->json([
            'total' => $found['total'], 'heading' => self::heading($found['total'], $found['filters']['q']),
            'cards' => view('sites::idx.cards', ['listings' => $found['results']])->render(),
            'pagination' => view('sites::idx.pagination', ['pages' => self::pagination($found['total'], $found['page'], $query)])->render(),
            'pins' => $pins['pins'] ?? null, 'truncated' => $pins['truncated'] ?? false,
            'url' => '/homes'.($query !== [] ? '?'.http_build_query($query) : ''),
        ])->header('Cache-Control', 'public, max-age=30, s-maxage=60')->header('X-Robots-Tag', 'noindex');
    }

    public function listing(Request $request, string $listing, ?string $slug = null): Response|RedirectResponse
    {
        $site = $this->site();
        if (! $site instanceof Site) {
            return $site;
        }
        $home = $this->search->find($this->tenants->id(), $listing);
        if ($home === null) {
            return response()->view('sites::missing', ['site' => $site], 404)->header('Cache-Control', 'public, max-age=300');
        }
        $path = Urls::listing($home['ulid'], ListingSearch::label($home));
        if ('/'.$request->path() !== $path) {
            return redirect($request->getSchemeAndHttpHost().$path, 301);
        }
        $base = $request->getSchemeAndHttpHost();
        $baths = self::baths($home);
        $place = trim(implode(', ', array_filter([$home['city'] ?? null, trim(($home['state'] ?? '').' '.($home['postal_code'] ?? ''))])));
        $name = ($home['address'] ?? null) !== null ? $home['address'].', '.$place : 'Home in '.$place;
        $facts = trim(implode(', ', array_filter([$home['beds'] !== null ? $home['beds'].' bd' : null, $baths !== null ? $baths.' ba' : null,
            $home['sqft'] !== null ? number_format((int) $home['sqft']).' sq ft' : null])));
        $price = $home['price'] !== null ? '$'.number_format((int) $home['price']) : null;
        $title = $name.($price !== null ? ' | '.$price : '').($facts !== '' ? ' | '.$facts : '');
        $description = trim(($facts !== '' ? $facts.' ' : '').strtolower((string) ($home['property_sub_type'] ?? 'home')).' for sale'
            .($price !== null ? ' at '.$price : '').' in '.($home['city'] ?? $place).'. '.Str::limit(strip_tags((string) ($home['remarks'] ?? '')), 110));
        $crumbs = [['Homes for sale', '/homes']];
        if (($home['city'] ?? null) && ($home['state'] ?? null)) {
            $crumbs[] = [$home['city'], Urls::market(MarketStats::citySlug($home['city'], $home['state']))];
        }
        $crumbs[] = [$home['address'] ?? 'This home', $path];
        $similar = array_values(array_filter($this->search->search($this->tenants->id(), array_filter(['q' => $home['postal_code'] ?? $home['city'] ?? '',
            'min_price' => $home['price'] !== null ? (int) ($home['price'] * 0.75) : null, 'max_price' => $home['price'] !== null ? (int) ($home['price'] * 1.25) : null,
            'per_page' => 5]))['results'], fn (array $l): bool => $l['ulid'] !== $home['ulid']));

        return response()->view('sites::idx.listing', [
            'site' => $site, 'current' => '/homes', 'home' => $home, 'name' => $name, 'place' => $place, 'baths' => $baths, 'path' => $path,
            'similar' => array_slice($similar, 0, 4), 'mortgage' => self::mortgage($home['price']), 'map' => self::staticMap($home),
            'seo' => Seo::build($site, $base, $path, $title, $description, $home['photos'][0]['hero'] ?? $home['photo'] ?? null, (bool) $home['indexable'],
                $crumbs, [], [self::listingJsonLd($home, $base.$path, $name, $base)], $home['updated_at'] ?? null),
            'chrome' => Chrome::for($site, $this->tenants->id(), array_merge([$home['disclaimer']], array_column($similar, 'disclaimer'))),
            ...SiteController::form($request),
        ])->header('Cache-Control', 'public, max-age=60, s-maxage='.config('sites.cache_seconds').', stale-while-revalidate='.config('sites.stale_seconds'));
    }

    /** Side by side, up to four homes (from the visitor's compare tray; never indexed). */
    public function compare(Request $request): Response
    {
        $site = $this->site();
        if (! $site instanceof Site) {
            return $site;
        }
        $ids = array_slice(array_values(array_unique(array_filter(explode(',', strtoupper((string) $request->query('ids', ''))),
            fn (string $u): bool => preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $u) === 1))), 0, 4);
        $homes = [];
        foreach ($ids as $u) {
            $home = $this->search->find($this->tenants->id(), $u);
            if ($home !== null) {
                $homes[] = $home + ['baths' => self::baths($home), 'path' => Urls::listing($home['ulid'], ListingSearch::label($home)),
                    'ppsf' => $home['price'] !== null && $home['sqft'] ? (int) round($home['price'] / $home['sqft']) : null];
            }
        }

        return response()->view('sites::idx.compare', [
            'site' => $site, 'current' => '/homes', 'homes' => $homes,
            'seo' => Seo::build($site, $request->getSchemeAndHttpHost(), '/homes/compare', 'Compare homes | '.self::suffix($site), 'Compare homes side by side.', null, false, [['Homes for sale', '/homes'], ['Compare', '/homes/compare']]),
            'chrome' => Chrome::for($site, $this->tenants->id(), array_column($homes, 'disclaimer')),
        ])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function markets(Request $request, MarketStats $stats): Response
    {
        $site = $this->site();
        if (! $site instanceof Site) {
            return $site;
        }
        $places = $stats->places($this->tenants->id());

        return response()->view('sites::idx.markets', [
            'site' => $site, 'current' => '/market', 'cities' => array_values(array_filter($places, fn (array $p): bool => $p['kind'] === 'city')),
            'zips' => array_values(array_filter($places, fn (array $p): bool => $p['kind'] === 'zip')),
            'seo' => Seo::build($site, $request->getSchemeAndHttpHost(), '/market', 'Real estate market reports | '.self::suffix($site),
                'Live housing market figures by city and ZIP code: homes for sale, median prices, price per square foot and days on market.', null, $places !== [],
                [['Market reports', '/market']]),
            'chrome' => Chrome::for($site, $this->tenants->id()),
        ])->header('Cache-Control', 'public, max-age=300, s-maxage=900');
    }

    public function market(Request $request, MarketStats $stats, string $place): Response
    {
        $site = $this->site();
        if (! $site instanceof Site) {
            return $site;
        }
        $m = $stats->forPlace($this->tenants->id(), $place);
        if ($m === null) {
            return response()->view('sites::missing', ['site' => $site], 404)->header('Cache-Control', 'public, max-age=300');
        }
        $tenantId = $this->tenants->id();
        $homes = $this->search->search($tenantId, ['q' => $m['zip'] ?? $m['city'] ?? '', 'per_page' => 12])['results'];
        $asOf = Carbon::parse($m['as_of'])->format('F j, Y');
        $faqs = self::marketFaqs($m, $asOf, $site);
        $path = Urls::market($m['slug']);
        $crumbs = [['Market reports', '/market']];
        if ($m['kind'] === 'zip' && $m['city'] !== null && $m['state'] !== null) {
            $crumbs[] = [$m['city'], Urls::market(MarketStats::citySlug($m['city'], $m['state']))];
        }
        $crumbs[] = [$m['name'], $path];

        return response()->view('sites::idx.market', [
            'site' => $site, 'current' => '/market', 'm' => $m, 'homes' => $homes, 'faqs' => $faqs, 'asOf' => $asOf, 'summary' => self::marketSummary($m, $asOf),
            'cityPath' => $m['kind'] === 'zip' && $m['city'] !== null && $m['state'] !== null ? Urls::market(MarketStats::citySlug($m['city'], $m['state'])) : null,
            'seo' => Seo::build($site, $request->getSchemeAndHttpHost(), $path, $m['name'].' real estate market: homes for sale and prices ('.Carbon::parse($m['as_of'])->format('M Y').')',
                self::marketSummary($m, $asOf), $homes[0]['photo'] ?? null, true, $crumbs, $faqs, [], $m['as_of']),
            'chrome' => Chrome::for($site, $tenantId, array_column($homes, 'disclaimer')),
        ])->header('Cache-Control', 'public, max-age=300, s-maxage=900, stale-while-revalidate=3600');
    }

    /**
     * The search's filters from the web address (only known keys, empty ones dropped), so every search is a link.
     *
     * @return array<string, string>
     */
    private static function query(Request $request): array
    {
        $out = [];
        foreach (self::KEYS as $k) {
            $v = $request->query($k);
            if (is_array($v)) {
                $v = implode(',', array_filter($v, 'is_string'));
            }
            if (is_string($v) && trim($v) !== '' && ! ($k === 'page' && $v === '1')) {
                $out[$k] = mb_substr(trim($v), 0, $k === 'poly' ? 2000 : 120);
            }
        }

        return $out;
    }

    private static function heading(int $total, string $q): string
    {
        return number_format($total).' '.($total === 1 ? 'home' : 'homes').' for sale'.($q !== '' ? ' in '.$q : '');
    }

    /**
     * @param  array<string, string>  $query
     * @return array{prev: string|null, next: string|null, page: int, last: int}
     */
    private static function pagination(int $total, int $page, array $query): array
    {
        $last = max(1, (int) ceil($total / self::PER_PAGE));
        $link = static fn (int $p): string => '/homes'.(($q = http_build_query(array_filter([...$query, 'page' => $p > 1 ? (string) $p : null]))) !== '' ? '?'.$q : '');

        return ['prev' => $page > 1 ? $link($page - 1) : null, 'next' => $page < $last ? $link($page + 1) : null, 'page' => $page, 'last' => $last];
    }

    /**
     * @param  array<string, mixed>  $home
     */
    private static function baths(array $home): int|float|null
    {
        if ($home['baths_full'] === null && $home['baths_half'] === null) {
            return null;
        }
        $b = (int) $home['baths_full'] + ((int) $home['baths_half'] > 0 ? 0.5 * (int) $home['baths_half'] : 0);

        return floor($b) === $b ? (int) $b : $b;
    }

    /**
     * Monthly principal and interest at the default rate, plus estimated tax and insurance (the page lets visitors
     * change every number).
     *
     * @return array{price: int, down_pct: int, rate: float, years: int, tax_rate: float, insurance: int, monthly: int|null}
     */
    private static function mortgage(?int $price): array
    {
        $c = (array) config('sites.mortgage');
        $rate = (float) ($c['rate'] ?? 6.5);
        $down = (int) ($c['down_pct'] ?? 20);
        $years = (int) ($c['years'] ?? 30);
        $tax = (float) ($c['tax_rate'] ?? 1.2);
        $ins = (int) ($c['insurance'] ?? 150);
        $monthly = null;
        if ($price !== null && $price > 0) {
            $loan = $price * (1 - $down / 100);
            $r = $rate / 100 / 12;
            $n = $years * 12;
            $pi = $r > 0 ? $loan * $r / (1 - (1 + $r) ** -$n) : $loan / $n;
            $monthly = (int) round($pi + $price * $tax / 100 / 12 + $ins);
        }

        return ['price' => (int) $price, 'down_pct' => $down, 'rate' => $rate, 'years' => $years, 'tax_rate' => $tax, 'insurance' => $ins, 'monthly' => $monthly];
    }

    /**
     * @param  array<string, mixed>  $home
     */
    private static function staticMap(array $home): ?string
    {
        $token = app(Maps::class)->token();
        if ($token === null || $home['latitude'] === null || $home['longitude'] === null) {
            return null;
        }
        $ll = round((float) $home['longitude'], 5).','.round((float) $home['latitude'], 5);

        return "https://api.mapbox.com/styles/v1/mapbox/streets-v12/static/pin-l+c9a45c({$ll})/{$ll},14.5,0/720x360@2x?access_token=".rawurlencode($token);
    }

    /**
     * schema.org RealEstateListing with the home as a residence and the asking price as an Offer.
     *
     * @param  array<string, mixed>  $home
     * @return array<string, mixed>
     */
    private static function listingJsonLd(array $home, string $url, string $name, string $base): array
    {
        $type = match (true) {
            str_contains(strtolower((string) $home['property_sub_type']), 'condo') => 'Apartment',
            str_contains(strtolower((string) $home['property_sub_type']), 'town') => 'House',
            ($home['property_type'] ?? '') === 'Residential' => 'SingleFamilyResidence',
            default => 'Residence',
        };
        $baths = self::baths($home);
        $residence = array_filter([
            '@type' => $type, 'name' => $name,
            'address' => array_filter(['@type' => 'PostalAddress', 'streetAddress' => $home['address'] ?? null, 'addressLocality' => $home['city'] ?? null,
                'addressRegion' => $home['state'] ?? null, 'postalCode' => $home['postal_code'] ?? null, 'addressCountry' => 'US']),
            'numberOfBedrooms' => $home['beds'], 'numberOfBathroomsTotal' => $baths, 'yearBuilt' => $home['year_built'] ?? null,
            'floorSize' => $home['sqft'] !== null ? ['@type' => 'QuantitativeValue', 'value' => $home['sqft'], 'unitCode' => 'FTK'] : null,
            'geo' => $home['latitude'] !== null ? ['@type' => 'GeoCoordinates', 'latitude' => $home['latitude'], 'longitude' => $home['longitude']] : null,
        ], fn ($v): bool => $v !== null);

        return array_filter([
            '@type' => 'RealEstateListing', '@id' => $url.'#listing', 'url' => $url, 'name' => $name,
            'description' => ($home['remarks'] ?? null) !== null ? Str::limit(strip_tags((string) $home['remarks']), 500) : null,
            'datePosted' => $home['listed'] ?? null,
            'image' => array_values(array_filter(array_map(fn (array $p): ?string => $p['hero'] ?? $p['url'] ?? null, array_slice($home['photos'] ?? [], 0, 10)))) ?: null,
            'offers' => $home['price'] !== null ? ['@type' => 'Offer', 'price' => $home['price'], 'priceCurrency' => 'USD',
                'availability' => in_array($home['status'], ['Active', 'Coming Soon'], true) ? 'https://schema.org/InStock' : 'https://schema.org/LimitedAvailability',
                'offeredBy' => ['@id' => rtrim($base, '/').'/#organization']] : null,
            'about' => $residence,
        ], fn ($v): bool => $v !== null);
    }

    /**
     * The answer-first summary: the one paragraph an AI assistant or a skimming reader needs.
     *
     * @param  array<string, mixed>  $m
     */
    private static function marketSummary(array $m, string $asOf): string
    {
        $parts = ["As of {$asOf}, there are ".number_format($m['active']).' homes for sale in '.$m['name']];
        if ($m['median_price'] !== null) {
            $parts[0] .= ' with a median list price of $'.number_format($m['median_price']).($m['median_ppsf'] !== null ? ' ($'.number_format($m['median_ppsf']).' per square foot)' : '');
        }
        $s = $parts[0].'.';
        if ($m['median_dom'] !== null) {
            $s .= ' The typical home has been on the market '.$m['median_dom'].' '.($m['median_dom'] === 1 ? 'day' : 'days').'.';
        }

        return $s.' '.$m['new_30'].' new '.($m['new_30'] === 1 ? 'listing' : 'listings').' came on in the last 30 days, and '.$m['reduced_share'].'% of homes for sale cut their price.';
    }

    /**
     * Questions people (and AI assistants) ask about an area, answered with this hour's figures.
     *
     * @param  array<string, mixed>  $m
     * @return list<array{q: string, a: string}>
     */
    private static function marketFaqs(array $m, string $asOf, Site $site): array
    {
        $n = $m['name'];
        $faqs = [['q' => "How many homes are for sale in {$n}?", 'a' => "As of {$asOf}, ".number_format($m['active'])." homes are for sale in {$n}, including {$m['new_30']} listed in the last 30 days."]];
        if ($m['median_price'] !== null) {
            $faqs[] = ['q' => "What is the median home price in {$n}?", 'a' => "The median list price of homes for sale in {$n} is $".number_format($m['median_price'])
                .($m['low_price'] !== null && $m['high_price'] !== null ? ', with most homes listed between $'.number_format($m['low_price']).' and $'.number_format($m['high_price']) : '')
                .". Figures are from MLS listings as of {$asOf}."];
        }
        if ($m['median_dom'] !== null) {
            $faqs[] = ['q' => "How long do homes take to sell in {$n}?", 'a' => "Homes for sale in {$n} have typically been on the market for {$m['median_dom']} days as of {$asOf}."];
        }
        $faqs[] = ['q' => "Are home prices dropping in {$n}?", 'a' => "{$m['reduced_30']} of the ".number_format($m['active'])." homes for sale in {$n} ({$m['reduced_share']}%) reduced their asking price in the last 30 days"
            .($m['reduced_share'] >= 25 ? ', which gives buyers room to negotiate.' : ($m['reduced_share'] <= 10 ? ', so most sellers are holding their prices.' : '.'))];
        $faqs[] = ['q' => "Who can help me buy or sell a home in {$n}?", 'a' => $site->displayName().' helps buyers and sellers in '.$n.(($site->info['phone'] ?? '') !== '' ? '. Call '.$site->info['phone'] : '').' or send a message from this page.'];

        return $faqs;
    }

    private static function suffix(Site $site): string
    {
        return (string) ($site->seo['title_suffix'] ?? $site->displayName());
    }

    /** The live brokerage site, or the page to show instead (coming soon or not found). */
    private function site(): Site|Response
    {
        $site = Site::query()->where('kind', 'brokerage')->first();
        if ($site === null) {
            return response()->view('sites::missing', ['site' => null], 404);
        }
        if ($site->status !== 'live') {
            return response()->view('sites::coming-soon', ['site' => $site], 200)->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
        }

        return $site;
    }
}
