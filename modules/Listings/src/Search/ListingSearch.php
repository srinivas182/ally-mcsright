<?php

declare(strict_types=1);

namespace Modules\Listings\Search;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Core\Support\Connections;
use Modules\Listings\Display\ListingPresenter;
use Modules\Listings\Media\Photos;
use Modules\Listings\Models\Listing;

/**
 * Listing search for the back office and the public websites (specs/websites/02): only MLSs the brokerage is
 * licensed for, only what the MLS rules allow (every result passes ListingPresenter), with filters, a map area,
 * sorting and paging. Indexed columns only; a search engine can replace the query later behind this class.
 */
final class ListingSearch
{
    public const SORTS = ['newest', 'price_asc', 'price_desc', 'sqft_desc', 'reduced'];

    public const STATUSES = ['Active', 'Coming Soon', 'Active Under Contract', 'Pending'];

    /**
     * @param  array<string, mixed>  $f
     * @return array{results: list<array<string, mixed>>, total: int, page: int, per_page: int, filters: array<string, mixed>}
     */
    public function search(int $tenantId, array $f, string $scope = 'idx'): array
    {
        $filters = self::clean($f);
        $query = $this->query($tenantId, $filters, $scope);
        $total = (clone $query)->count();
        $rows = $query->with('source.rulesProfile')->forPage($filters['page'], $filters['per_page'])->get();

        $results = [];
        foreach ($rows as $l) {
            $shown = ListingPresenter::forDisplay($l);
            if ($shown !== null) {
                $results[] = $shown + ['photo' => Photos::url($l->cover_path), 'reduced' => $l->original_list_price_cents !== null && $l->list_price_cents !== null
                    && $l->list_price_cents < $l->original_list_price_cents, 'listed' => $l->on_market_date?->toDateString(),
                    'added_at' => $l->created_at?->toIso8601String(), 'price_changed_at' => $l->price_changed_at?->toIso8601String(), 'url' => self::publicUrl($l->ulid)];
            }
        }

        return ['results' => $results, 'total' => $total, 'page' => $filters['page'], 'per_page' => $filters['per_page'], 'filters' => $filters];
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

        return $shown + ['photos' => Photos::forListing($l), 'price_history' => $history, 'listed' => $l->on_market_date?->toDateString()];
    }

    /**
     * @param  array<string, mixed>  $filters  already cleaned
     * @return Builder<Listing>
     */
    public function query(int $tenantId, array $filters, string $scope = 'idx'): Builder
    {
        $q = Listing::query()->visibleTo($tenantId, $scope)->where('internet_display_allowed', true)->whereNull('off_market_at')
            ->whereIn('standard_status', $filters['statuses']);

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
        if ($filters['since'] !== null) {
            $q->where(fn (Builder $w) => $w->where('created_at', '>=', $filters['since'])->orWhere('price_changed_at', '>=', $filters['since']));
        }

        return match ($filters['sort']) {
            'price_asc' => $q->orderBy('list_price_cents')->orderBy('id'),
            'price_desc' => $q->orderByDesc('list_price_cents')->orderBy('id'),
            'sqft_desc' => $q->orderByDesc('living_area_sqft')->orderBy('id'),
            'reduced' => $q->whereNotNull('price_changed_at')->orderByDesc('price_changed_at')->orderBy('id'),
            default => $q->orderByDesc('on_market_date')->orderByDesc('id'),
        };
    }

    /** The listing's page on the brokerage's public website, once the site engine provides one (Sprint 17C). */
    public static function publicUrl(string $ulid): ?string
    {
        return Route::has('site.listing') ? route('site.listing', ['listing' => $ulid]) : null;
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array{q: string, min_price: int|null, max_price: int|null, beds: int|null, baths: int|null, min_sqft: int|null, types: list<string>, statuses: list<string>, bounds: list<float>|null, agent_mls_id: string|null, since: string|null, sort: string, page: int, per_page: int}
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
        $since = null;
        if (is_string($f['since'] ?? null)) {
            try {
                $since = CarbonImmutable::parse($f['since'])->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                $since = null;
            }
        }

        return ['q' => mb_substr(trim((string) (is_scalar($f['q'] ?? null) ? $f['q'] : '')), 0, 80), 'min_price' => $int('min_price', 1_000_000_000), 'max_price' => $int('max_price', 1_000_000_000),
            'beds' => $int('beds', 20), 'baths' => $int('baths', 20), 'min_sqft' => $int('min_sqft', 1_000_000), 'types' => $list('types'),
            'statuses' => $statuses !== [] ? $statuses : ['Active', 'Coming Soon'], 'bounds' => $bounds,
            'agent_mls_id' => is_string($f['agent_mls_id'] ?? null) && $f['agent_mls_id'] !== '' ? mb_substr($f['agent_mls_id'], 0, 30) : null, 'since' => $since,
            'sort' => in_array($f['sort'] ?? null, self::SORTS, true) ? (string) $f['sort'] : 'newest',
            'page' => max(1, min(500, (int) (is_numeric($f['page'] ?? null) ? $f['page'] : 1))), 'per_page' => max(1, min(48, (int) (is_numeric($f['per_page'] ?? null) ? $f['per_page'] : 24)))];
    }
}
