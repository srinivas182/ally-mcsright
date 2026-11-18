<?php

declare(strict_types=1);

namespace Modules\Listings\Search;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Display\ListingPresenter;
use Modules\Listings\Media\Photos;
use Modules\Listings\Models\Listing;
use Throwable;

/**
 * Listing search for the back office and the public websites (specs/websites/02): only MLSs the brokerage is
 * licensed for, only what the MLS rules allow (every result passes ListingPresenter), with filters, a map area,
 * sorting and paging. Indexed columns only.
 *
 * SR1: a home on two MLSs is shown once (Sync\Duplicates); "near" finds homes within a distance of a point and
 * shows how far each is; and when HQ connects a fast search server (SearchIndex), it answers in Live mode and is
 * compared with the database in Test mode.
 */
final class ListingSearch
{
    public const SORTS = ['newest', 'price_asc', 'price_desc', 'sqft_desc', 'reduced', 'distance'];

    public const STATUSES = ['Active', 'Coming Soon', 'Active Under Contract', 'Pending'];

    /**
     * @param  array<string, mixed>  $f
     * @return array{results: list<array<string, mixed>>, total: int, page: int, per_page: int, filters: array<string, mixed>}
     */
    public function search(int $tenantId, array $f, string $scope = 'idx'): array
    {
        $filters = self::clean($f);
        $index = app(SearchIndex::class);
        $server = $filters['polygon'] === null ? $index->engine() : null; // a drawn area is answered by the database
        $started = microtime(true);
        $rows = null;
        $total = 0;
        if ($server !== null && $server['mode'] === 'live') {
            try {
                $found = $server['engine']->search($this->serverQuery($tenantId, $filters, $scope));
                // The server chose and ordered them; the MLS licence and display rules are checked again here.
                $byId = Listing::query()->visibleTo($tenantId, $scope)->where('internet_display_allowed', true)->whereNull('off_market_at')
                    ->whereKey($found['ids'] ?: [0])->with('source.rulesProfile')->get()->keyBy('id');
                $rows = collect($found['ids'])->map(fn (int $id) => $byId->get($id))->filter()->values();
                $total = $found['total'];
            } catch (Throwable $e) {
                report($e); // the database answers instead
                $rows = null;
            }
        }
        if ($rows === null) {
            $query = $this->query($tenantId, $filters, $scope);
            if ($filters['polygon'] !== null) {
                $query->whereKey($this->insidePolygon(clone $query, $filters['polygon']));
            }
            if ($filters['near'] !== null) {
                $query->whereKey($this->withinDistance(clone $query, $filters['near']));
            }
            $total = (clone $query)->count();
            $rows = $query->with('source.rulesProfile')->forPage($filters['page'], $filters['per_page'])->get();
            if ($server !== null && $server['mode'] === 'test' && $filters['page'] === 1) {
                $ids = array_values($rows->pluck('id')->map(fn ($v): int => (int) $v)->all());
                $ms = (microtime(true) - $started) * 1000;
                $ask = fn (): array => $server['engine']->search($this->serverQuery($tenantId, $filters, $scope));
                $done = false; // once per search, after the page is sent
                app()->terminating(function () use (&$done, $index, $ids, $total, $ms, $ask): void {
                    if (! $done) {
                        $done = true;
                        $index->compare($ids, $total, $ms, $ask);
                    }
                });
            }
        }

        $results = [];
        foreach ($rows as $l) {
            $shown = ListingPresenter::forDisplay($l);
            if ($shown !== null) {
                $results[] = $shown + ['photo' => Photos::url($l->cover_path), 'reduced' => $l->original_list_price_cents !== null && $l->list_price_cents !== null
                    && $l->list_price_cents < $l->original_list_price_cents, 'listed' => $l->on_market_date?->toDateString(),
                    'added_at' => $l->created_at?->toIso8601String(), 'price_changed_at' => $l->price_changed_at?->toIso8601String(),
                    'url' => self::publicUrl($l->ulid, self::label($shown)),
                    'miles' => $filters['near'] !== null && $l->latitude !== null && $l->longitude !== null
                        ? round(self::miles($filters['near'][0], $filters['near'][1], (float) $l->latitude, (float) $l->longitude), 1) : null];
            }
        }

        return ['results' => $results, 'total' => $total, 'page' => $filters['page'], 'per_page' => $filters['per_page'], 'filters' => $filters];
    }

    /**
     * The same question for the search server: the licensed MLSs, prices in cents, and the change date as a time.
     *
     * @param  array<string, mixed>  $filters  already cleaned
     * @return array<string, mixed>
     */
    private function serverQuery(int $tenantId, array $filters, string $scope): array
    {
        return ['q' => $filters['q'], 'sources' => app(MlsAccess::class)->licensedSources($tenantId, $scope) ?: [0], 'statuses' => $filters['statuses'], 'types' => $filters['types'],
            'min_price' => $filters['min_price'] !== null ? $filters['min_price'] * 100 : null, 'max_price' => $filters['max_price'] !== null ? $filters['max_price'] * 100 : null,
            'beds' => $filters['beds'], 'baths' => $filters['baths'], 'min_sqft' => $filters['min_sqft'], 'bounds' => $filters['bounds'], 'near' => $filters['near'],
            'agent' => $filters['agent_mls_id'], 'states' => $filters['states'], 'since' => $filters['since'] !== null ? CarbonImmutable::parse($filters['since'])->getTimestamp() : null,
            'sort' => $filters['sort'], 'page' => $filters['page'], 'per_page' => $filters['per_page']];
    }

    /**
     * Ids of listings within a distance of a point: a box narrows the query, then each is measured. Capped at 5,000.
     *
     * @param  Builder<Listing>  $query
     * @param  array{0: float, 1: float, 2: float}  $near
     * @return list<int>
     */
    private function withinDistance(Builder $query, array $near): array
    {
        [$lat, $lng, $miles] = $near;
        $dLat = $miles / 69.0;
        $dLng = $miles / max(1.0, 69.0 * cos(deg2rad($lat)));
        $rows = $query->reorder()->whereBetween('latitude', [$lat - $dLat, $lat + $dLat])->whereBetween('longitude', [$lng - $dLng, $lng + $dLng])
            ->where('address_display_allowed', true)->limit(5000)->get(['id', 'latitude', 'longitude']);
        $ids = [];
        foreach ($rows as $r) {
            if (self::miles($lat, $lng, (float) $r->latitude, (float) $r->longitude) <= $miles) {
                $ids[] = (int) $r->id;
            }
        }

        return $ids;
    }

    /** Straight-line distance in miles (haversine). */
    public static function miles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 3958.8 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Map pins for the same filters: listings whose address may be shown, newest first, capped so the map stays fast
     * (the map clusters them). Each pin is [ulid, latitude, longitude, price in dollars, beds].
     *
     * @param  array<string, mixed>  $f
     * @return array{pins: list<array{0: string, 1: float, 2: float, 3: int|null, 4: int|null}>, truncated: bool}
     */
    public function pins(int $tenantId, array $f, int $limit = 500, string $scope = 'idx'): array
    {
        $filters = self::clean($f);
        $query = $this->query($tenantId, $filters, $scope)->where('address_display_allowed', true)->whereNotNull('latitude')->whereNotNull('longitude');
        if ($filters['polygon'] !== null) {
            $query->whereKey($this->insidePolygon(clone $query, $filters['polygon']));
        }
        if ($filters['near'] !== null) {
            $query->whereKey($this->withinDistance(clone $query, $filters['near']));
        }
        $rows = $query->limit($limit + 1)->get(['id', 'ulid', 'latitude', 'longitude', 'list_price_cents', 'beds_total']);
        $pins = [];
        foreach ($rows->take($limit) as $l) {
            $pins[] = [$l->ulid, round((float) $l->latitude, 6), round((float) $l->longitude, 6), $l->list_price_cents !== null ? intdiv($l->list_price_cents, 100) : null, $l->beds_total];
        }

        return ['pins' => $pins, 'truncated' => $rows->count() > $limit];
    }

    /**
     * Ids of listings inside a drawn area: the area's bounding box narrows the query, then each point is tested
     * against the polygon (ray casting). Capped at 5,000 candidates.
     *
     * @param  Builder<Listing>  $query
     * @param  list<array{0: float, 1: float}>  $polygon  [latitude, longitude] points
     * @return list<int>
     */
    private function insidePolygon(Builder $query, array $polygon): array
    {
        $lats = array_column($polygon, 0);
        $lngs = array_column($polygon, 1);
        if ($lats === [] || $lngs === []) {
            return [];
        }
        $rows = $query->reorder()->whereBetween('latitude', [min($lats), max($lats)])->whereBetween('longitude', [min($lngs), max($lngs)])
            ->where('address_display_allowed', true)->limit(5000)->get(['id', 'latitude', 'longitude']);
        $ids = [];
        foreach ($rows as $r) {
            if (self::pointInPolygon((float) $r->latitude, (float) $r->longitude, $polygon)) {
                $ids[] = $r->id;
            }
        }

        return $ids;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $polygon
     */
    public static function pointInPolygon(float $lat, float $lng, array $polygon): bool
    {
        $inside = false;
        $n = count($polygon);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$yi, $xi] = $polygon[$i];
            [$yj, $xj] = $polygon[$j];
            if (($yi > $lat) !== ($yj > $lat) && $lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * Words for a listing's web address (street, city, state, ZIP; the street only when it may be shown).
     *
     * @param  array<string, mixed>  $shown  a presented listing
     */
    public static function label(array $shown): string
    {
        return trim(implode(' ', array_filter([$shown['address'] ?? null, $shown['city'] ?? null, $shown['state'] ?? null, $shown['postal_code'] ?? null],
            fn ($v): bool => is_string($v) && $v !== '')));
    }

    /**
     * One listing for a detail page, with photos and price history (null: not licensed or not allowed).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $tenantId, string $ulid, string $scope = 'idx'): ?array
    {
        $l = Listing::query()->visibleTo($tenantId, $scope)->where('ulid', $ulid)->with('source.rulesProfile')->first();
        $shown = $l !== null ? ListingPresenter::forDisplay($l) : null;
        if ($l === null || $shown === null) {
            return null;
        }
        $history = DB::connection(Connections::listings())->table('listing_price_changes')->where('listing_id', $l->id)->orderBy('changed_at')->get()
            ->map(fn ($r): array => ['date' => substr((string) $r->changed_at, 0, 10), 'from' => $r->old_cents !== null ? intdiv((int) $r->old_cents, 100) : null, 'to' => intdiv((int) $r->new_cents, 100)])->all();

        // The presenter's "photos" is a count; the detail page needs the photos themselves.
        return [...$shown, 'photo_count' => $shown['photos'], 'photos' => Photos::forListing($l), 'price_history' => $history, 'listed' => $l->on_market_date?->toDateString(),
            'photo' => Photos::url($l->cover_path), 'updated_at' => $l->modification_timestamp?->toIso8601String()];
    }

    /**
     * @param  array<string, mixed>  $filters  already cleaned
     * @return Builder<Listing>
     */
    public function query(int $tenantId, array $filters, string $scope = 'idx'): Builder
    {
        $licensed = app(MlsAccess::class)->licensedSources($tenantId, $scope);
        $q = Listing::query()->visibleTo($tenantId, $scope)->where('internet_display_allowed', true)->whereNull('off_market_at')
            ->whereIn('standard_status', $filters['statuses'])
            // SR1: a copy of a home listed on another licensed MLS is shown once.
            ->where(fn (Builder $w) => $w->whereNull('duplicate_of_source_id')->orWhereNotIn('duplicate_of_source_id', $licensed ?: [0]));

        if ($filters['q'] !== '') {
            $term = $filters['q'];
            if (preg_match('/^\d{5}$/', $term) === 1) {
                $q->where('postal_code', $term);
            } else {
                $like = '%'.str_replace(['%', '_', '\\'], '', $term).'%';
                $number = preg_match('/^(\d+)\s+(.+)$/', $term, $m) === 1 ? $m : null;
                $q->where(fn (Builder $w) => $w->where('city', $term)->orWhere('listing_id', $term)->orWhere('subdivision', 'like', $like)
                    ->orWhere('street_name', 'like', $like)
                    ->when($number !== null, fn (Builder $x) => $x->orWhere(fn (Builder $y) => $y->where('street_number', $number[1] ?? '')
                        ->where('street_name', 'like', '%'.str_replace(['%', '_', '\\'], '', $number[2] ?? '').'%'))));
            }
        }
        foreach (['min_price' => '>=', 'max_price' => '<='] as $k => $op) {
            if ($filters[$k] !== null) {
                $q->where('list_price_cents', $op, $filters[$k] * 100);
            }
        }
        if ($filters['beds'] !== null) {
            $q->where('beds_total', '>=', $filters['beds']);
        }
        if ($filters['baths'] !== null) {
            $q->where('baths_full', '>=', $filters['baths']);
        }
        if ($filters['min_sqft'] !== null) {
            $q->where('living_area_sqft', '>=', $filters['min_sqft']);
        }
        if ($filters['types'] !== []) {
            $q->whereIn('property_type', $filters['types']);
        }
        if ($filters['bounds'] !== null) {
            [$s, $w, $n, $e] = $filters['bounds'];
            $q->whereBetween('latitude', [$s, $n])->whereBetween('longitude', [$w, $e])->where('address_display_allowed', true);
        }
        if ($filters['agent_mls_id'] !== null) {
            $q->where('list_agent_mls_id', $filters['agent_mls_id']);
        }
        if ($filters['states'] !== []) {
            $q->whereIn('state_or_province', $filters['states']); // Movewise: only the states switched on
        }
        if ($filters['since'] !== null) {
            $q->where(fn (Builder $w) => $w->where('created_at', '>=', $filters['since'])->orWhere('price_changed_at', '>=', $filters['since']));
        }

        return match ($filters['sort']) {
            'price_asc' => $q->orderBy('list_price_cents')->orderBy('id'),
            'price_desc' => $q->orderByDesc('list_price_cents')->orderBy('id'),
            'sqft_desc' => $q->orderByDesc('living_area_sqft')->orderBy('id'),
            'reduced' => $q->whereNotNull('price_changed_at')->orderByDesc('price_changed_at')->orderBy('id'),
            'distance' => $filters['near'] !== null
                ? $q->orderByRaw('((latitude - ?) * (latitude - ?)) + (((longitude - ?) * ?) * ((longitude - ?) * ?))', [$filters['near'][0], $filters['near'][0],
                    $filters['near'][1], cos(deg2rad($filters['near'][0])), $filters['near'][1], cos(deg2rad($filters['near'][0]))])->orderBy('id')
                : $q->orderByDesc('on_market_date')->orderByDesc('id'),
            default => $q->orderByDesc('on_market_date')->orderByDesc('id'),
        };
    }

    /**
     * The listing's page on the brokerage's public website (bound by the Sites module): null when the brokerage has
     * no live website. $label adds the address words to the link.
     */
    public static function publicUrl(string $ulid, ?string $label = null): ?string
    {
        $resolver = app()->bound('listings.public_url') ? app('listings.public_url') : null;
        $url = is_callable($resolver) ? $resolver($ulid, $label) : null;

        return is_string($url) ? $url : null;
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array{q: string, min_price: int|null, max_price: int|null, beds: int|null, baths: int|null, min_sqft: int|null, types: list<string>, statuses: list<string>, bounds: list<float>|null, polygon: list<array{0: float, 1: float}>|null, near: array{0: float, 1: float, 2: float}|null, agent_mls_id: string|null, since: string|null, states: list<string>, sort: string, page: int, per_page: int}
     */
    public static function clean(array $f): array
    {
        $int = static fn (string $k, int $max): ?int => is_numeric($f[$k] ?? null) && (int) $f[$k] > 0 ? min($max, (int) $f[$k]) : null;
        $list = static fn (string $k): array => array_values(array_filter(array_map(fn ($v): string => mb_substr(trim((string) $v), 0, 40), is_array($f[$k] ?? null) ? $f[$k] : (is_string($f[$k] ?? null) && $f[$k] !== '' ? explode(',', $f[$k]) : [])), fn (string $v): bool => $v !== ''));
        $statuses = array_values(array_intersect($list('statuses'), self::STATUSES));
        $bounds = null;
        if (is_string($f['bounds'] ?? null) && preg_match('/^-?\d+(\.\d+)?(,-?\d+(\.\d+)?){3}$/', $f['bounds']) === 1) {
            $b = array_map('floatval', explode(',', $f['bounds']));
            if ($b[0] < $b[2] && $b[1] < $b[3] && abs($b[0]) <= 90 && abs($b[2]) <= 90) {
                $bounds = $b; // south, west, north, east
            }
        }
        // A drawn area: "lat,lng;lat,lng;…" with 3 to 60 points.
        $polygon = null;
        if (is_string($f['poly'] ?? null) && preg_match('/^-?\d+(\.\d+)?,-?\d+(\.\d+)?(;-?\d+(\.\d+)?,-?\d+(\.\d+)?){2,59}$/', $f['poly']) === 1) {
            $points = [];
            foreach (explode(';', $f['poly']) as $pair) {
                [$lat, $lng] = array_map('floatval', explode(',', $pair));
                if (abs($lat) <= 90 && abs($lng) <= 180) {
                    $points[] = [$lat, $lng];
                }
            }
            $polygon = count($points) >= 3 ? $points : null;
        }
        // Near a point: "lat,lng,miles" (a tenth of a mile to 100 miles).
        $near = null;
        if (is_string($f['near'] ?? null) && preg_match('/^-?\d+(\.\d+)?,-?\d+(\.\d+)?,\d+(\.\d+)?$/', $f['near']) === 1) {
            [$lat, $lng, $miles] = array_map('floatval', explode(',', $f['near']));
            if (abs($lat) <= 90 && abs($lng) <= 180 && $miles >= 0.1 && $miles <= 100) {
                $near = [$lat, $lng, $miles];
            }
        }
        $since = null;
        if (is_string($f['since'] ?? null)) {
            try {
                $since = CarbonImmutable::parse($f['since'])->format('Y-m-d H:i:s');
            } catch (Throwable) {
                $since = null;
            }
        }

        return ['q' => mb_substr(trim((string) (is_scalar($f['q'] ?? null) ? $f['q'] : '')), 0, 80), 'min_price' => $int('min_price', 1_000_000_000), 'max_price' => $int('max_price', 1_000_000_000),
            'beds' => $int('beds', 20), 'baths' => $int('baths', 20), 'min_sqft' => $int('min_sqft', 1_000_000), 'types' => $list('types'),
            'statuses' => $statuses !== [] ? $statuses : ['Active', 'Coming Soon'], 'bounds' => $bounds, 'polygon' => $polygon, 'near' => $near,
            'agent_mls_id' => is_string($f['agent_mls_id'] ?? null) && $f['agent_mls_id'] !== '' ? mb_substr($f['agent_mls_id'], 0, 30) : null, 'since' => $since,
            'states' => array_values(array_filter(array_map(fn ($s): string => strtoupper((string) $s), (array) ($f['states'] ?? [])), fn (string $s): bool => preg_match('/^[A-Z]{2}$/', $s) === 1)),
            'sort' => in_array($f['sort'] ?? null, self::SORTS, true) && ($f['sort'] !== 'distance' || $near !== null) ? (string) $f['sort'] : 'newest',
            'page' => max(1, min(500, (int) (is_numeric($f['page'] ?? null) ? $f['page'] : 1))), 'per_page' => max(1, min(48, (int) (is_numeric($f['per_page'] ?? null) ? $f['per_page'] : 24)))];
    }
}
