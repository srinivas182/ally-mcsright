<?php

declare(strict_types=1);

namespace Modules\Movewise\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Modules\Listings\Search\ListingSearch;
use Modules\Movewise\Growth\AgentReviews;
use Modules\Movewise\Growth\Places;
use Modules\Movewise\Http\Controllers\ConnectController as Connect;
use Modules\Movewise\I18n;
use Modules\Movewise\Models\Agent;
use Modules\Movewise\Movewise;

/**
 * Movewise's growth pages (specs/movewise/07): agent profiles with verified reviews, and a page for every city, ZIP
 * code and neighbourhood with live market facts, a six-month report and plain-English answers. Server-rendered,
 * schema.org marked up, in English and Spanish, and in the sitemaps.
 */
final class GrowthController
{
    public function __construct(private readonly SiteController $site, private readonly Places $places, private readonly Movewise $movewise) {}

    public function agent(string $agent, AgentReviews $reviews): Response
    {
        $chrome = $this->site->chrome();
        $a = Agent::query()->where('ulid', $agent)->first();
        if ($a === null || $a->status !== 'active' || ! in_array($a->state, array_column($chrome['states'], 'code'), true)) {
            return response()->view('movewise::missing', ['site' => $chrome], 404);
        }
        $name = (string) ($a->profile['display_name'] ?? 'Agent');
        $path = '/agent/'.$a->ulid.'/'.Str::slug($name);
        $summary = $reviews->summary($a);
        $published = $reviews->published($a);
        $node = array_filter(['@type' => 'RealEstateAgent', 'name' => $name, 'url' => $chrome['base'].$path, 'image' => $a->profile['photo'] ?? null,
            'description' => $a->profile['bio'] ?? null, 'knowsLanguage' => $a->profile['languages'] ?? ['en'],
            'parentOrganization' => ['@type' => 'Organization', 'name' => $a->brokerage_name],
            'aggregateRating' => $summary['count'] > 0 ? ['@type' => 'AggregateRating', 'ratingValue' => $summary['average'], 'reviewCount' => $summary['count'], 'bestRating' => 5] : null,
            'review' => array_map(fn (array $r): array => ['@type' => 'Review', 'author' => ['@type' => 'Person', 'name' => $r['author']], 'datePublished' => $r['date'],
                'reviewRating' => ['@type' => 'Rating', 'ratingValue' => $r['rating'], 'bestRating' => 5], 'reviewBody' => $r['text']], array_slice($published, 0, 5)) ?: null],
            fn ($v): bool => $v !== null);

        return response()->view('movewise::agent', ['site' => $chrome, 'agent' => $a, 'name' => $name, 'summary' => $summary, 'reviews' => $published,
            'imported' => $reviews->imported($a), 'lookup' => Connect::licenceLookup($a->state),
            'seo' => $this->site->seo($chrome, $path, $name.', '.$a->brokerage_name.' | Movewise Homes', $name.', real estate agent with '.$a->brokerage_name
                .($summary['count'] > 0 ? ', rated '.$summary['average'].' by '.$summary['count'].' verified Movewise clients' : '').'.', true, [$node], [],
                [[$name, $path]])])->header('Cache-Control', 'public, max-age=300, s-maxage=900');
    }

    public function place(string $state, string $place): Response
    {
        $chrome = $this->site->chrome();
        $s = collect($chrome['states'])->firstWhere('slug', $state);
        if ($s === null) {
            return response()->view('movewise::missing', ['site' => $chrome], 404);
        }
        if (preg_match('/^\d{5}$/', $place) === 1) {
            return $this->render($chrome, $s, ['state' => $s['code'], 'zip' => $place], 'ZIP code '.$place, '/'.$s['slug'].'/'.$place, [[$s['name'], '/'.$s['slug']], ['ZIP '.$place, '/'.$s['slug'].'/'.$place]]);
        }
        $city = $this->places->city($s['code'], $place);
        if ($city === null) {
            return response()->view('movewise::missing', ['site' => $chrome], 404);
        }

        return $this->render($chrome, $s, ['state' => $s['code'], 'city' => $city], $city, '/'.$s['slug'].'/'.Str::slug($city), [[$s['name'], '/'.$s['slug']], [$city, '/'.$s['slug'].'/'.Str::slug($city)]]);
    }

    public function hood(string $state, string $city, string $hood): Response
    {
        $chrome = $this->site->chrome();
        $s = collect($chrome['states'])->firstWhere('slug', $state);
        $cityName = $s !== null ? $this->places->city($s['code'], $city) : null;
        $hoodName = $cityName !== null ? $this->places->hood($s['code'], $cityName, $hood) : null;
        if ($s === null || $cityName === null || $hoodName === null) {
            return response()->view('movewise::missing', ['site' => $chrome], 404);
        }
        $path = '/'.$s['slug'].'/'.Str::slug($cityName).'/'.Str::slug($hoodName);

        return $this->render($chrome, $s, ['state' => $s['code'], 'city' => $cityName, 'hood' => $hoodName], $hoodName.', '.$cityName, $path,
            [[$s['name'], '/'.$s['slug']], [$cityName, '/'.$s['slug'].'/'.Str::slug($cityName)], [$hoodName, $path]]);
    }

    /**
     * @param  array{base: string, states: list<array{code: string, name: string, slug: string}>, year: int}  $chrome
     * @param  array{code: string, name: string, slug: string}  $s
     * @param  array{state: string, city?: string|null, zip?: string|null, hood?: string|null}  $where
     * @param  list<array{0: string, 1: string}>  $crumbs
     */
    private function render(array $chrome, array $s, array $where, string $label, string $path, array $crumbs): Response
    {
        $facts = $this->places->facts($where);
        if ($facts === null) {
            return response()->view('movewise::missing', ['site' => $chrome], 404);
        }
        $tenant = $this->movewise->feedTenant();
        $filters = ['states' => [$s['code']], 'per_page' => 12, 'sort' => 'newest'] + (isset($where['zip']) ? ['q' => $where['zip']] : ['q' => $where['hood'] ?? $where['city'] ?? '']);
        $homes = $tenant !== null ? app(ListingSearch::class)->search($tenant->id, $filters)['results'] : [];
        $money = fn (?int $v): string => $v === null ? 'not enough data' : '$'.number_format($v);
        $where_ = $label.', '.$s['name'];
        $faqs = [
            [I18n::t('place.q_count', ['place' => $where_]), I18n::t('place.a_count', ['n' => number_format($facts['count']), 'place' => $where_, 'date' => $facts['as_of']])],
            [I18n::t('place.q_price', ['place' => $where_]), I18n::t('place.a_price', ['price' => $money($facts['median_price']), 'ppsf' => $facts['median_ppsf'] !== null ? '$'.$facts['median_ppsf'] : '—'])],
            [I18n::t('place.q_days', ['place' => $where_]), $facts['median_days'] !== null ? I18n::t('place.a_days', ['n' => $facts['median_days']]) : I18n::t('place.a_days_none')],
        ];
        $place = ['@type' => 'Place', 'name' => $where_, 'address' => array_filter(['@type' => 'PostalAddress', 'addressLocality' => $where['city'] ?? null,
            'postalCode' => $where['zip'] ?? null, 'addressRegion' => $s['code'], 'addressCountry' => 'US'])];
        $title = I18n::t('home.state_link', ['state' => $where_]);

        return response()->view('movewise::place', ['site' => $chrome, 'state' => $s, 'where' => $where, 'label' => $label, 'facts' => $facts, 'title' => $title,
            'homes' => array_map(fn (array $h): array => $h + ['label' => ListingSearch::label($h), 'href' => I18n::url('/homes/'.$h['ulid'].'/'.Str::slug(mb_substr(ListingSearch::label($h), 0, 120)))], $homes),
            'base_path' => $path,
            'seo' => $this->site->seo($chrome, $path, $title.' | Movewise Homes', I18n::t('place.description', ['n' => I18n::t($facts['count'] === 1 ? 'search.count_one' : 'search.count', ['n' => number_format($facts['count'])]), 'place' => $where_,
                'price' => $money($facts['median_price'])]), true, [$place], $faqs, $crumbs)])->header('Cache-Control', 'public, max-age=300, s-maxage=900');
    }
}
