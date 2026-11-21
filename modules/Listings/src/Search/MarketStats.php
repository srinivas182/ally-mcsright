<?php

declare(strict_types=1);

namespace Modules\Listings\Search;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Listings\Models\Listing;

/**
 * Live market figures for a city or ZIP from the listings a brokerage is licensed to show (specs/websites/06):
 * how many homes are for sale, median list price and price per square foot, typical days on market, new listings
 * and price cuts in the last 30 days. Areas with fewer than MIN_LISTINGS homes are not published (thin content).
 * Cached for an hour per brokerage and area.
 */
final class MarketStats
{
    public const MIN_LISTINGS = 3;

    /**
     * Areas worth a page: cities and ZIPs with enough homes for sale, busiest first.
     *
     * @return list<array{kind: string, slug: string, name: string, count: int}>
     */
    public function places(int $tenantId, int $limit = 60): array
    {
        return Cache::remember("market:places:{$tenantId}:{$limit}", 3600, function () use ($tenantId, $limit): array {
            $cities = $this->base($tenantId)->whereNotNull('city')->whereNotNull('state_or_province')
                ->groupBy('city', 'state_or_province')->selectRaw('city, state_or_province, count(*) as n')->havingRaw('count(*) >= ?', [self::MIN_LISTINGS])
                ->orderByDesc('n')->limit($limit)->toBase()->get();
            $zips = $this->base($tenantId)->whereNotNull('postal_code')->groupBy('postal_code')->selectRaw('postal_code, min(city) as city, count(*) as n')
                ->havingRaw('count(*) >= ?', [self::MIN_LISTINGS])->orderByDesc('n')->limit($limit)->toBase()->get();
            // IDX9: neighbourhoods (the MLS's subdivision), within a city.
            $areas = $this->base($tenantId)->whereNotNull('subdivision')->where('subdivision', '!=', '')->whereNotNull('city')->whereNotNull('state_or_province')
                ->groupBy('subdivision', 'city', 'state_or_province')->selectRaw('subdivision, city, state_or_province, count(*) as n')->havingRaw('count(*) >= ?', [self::MIN_LISTINGS])
                ->orderByDesc('n')->limit($limit)->toBase()->get();
            $out = [];
            foreach ($cities as $c) {
                $out[] = ['kind' => 'city', 'slug' => self::citySlug((string) $c->city, (string) $c->state_or_province), 'name' => $c->city.', '.$c->state_or_province, 'count' => (int) $c->n];
            }
            foreach ($areas as $a) {
                $slug = self::areaSlug((string) $a->subdivision, (string) $a->city, (string) $a->state_or_province);
                if ($slug !== null) {
                    $out[] = ['kind' => 'area', 'slug' => $slug, 'name' => self::areaName((string) $a->subdivision).', '.$a->city, 'count' => (int) $a->n];
                }
            }
            foreach ($zips as $z) {
                $out[] = ['kind' => 'zip', 'slug' => (string) $z->postal_code, 'name' => $z->postal_code.($z->city ? ' ('.$z->city.')' : ''), 'count' => (int) $z->n];
            }

            return $out;
        });
    }

    /**
     * Figures for one area ("austin-tx" or "78704"); null when the area is unknown or too small to publish.
     *
     * @return array{kind: string, slug: string, name: string, city: string|null, state: string|null, zip: string|null, area?: string|null, as_of: string, active: int, median_price: int|null, low_price: int|null, high_price: int|null, median_ppsf: int|null, median_dom: int|null, new_30: int, reduced_30: int, reduced_share: int, types: array<string, int>, zips: list<string>}|null
     */
    public function forPlace(int $tenantId, string $place): ?array
    {
        $place = strtolower(trim($place));
        $now = CarbonImmutable::now();

        return Cache::remember("market:{$tenantId}:{$place}:".$now->format('YmdH'), 3600, function () use ($tenantId, $place, $now): ?array {
            if (preg_match('/^\d{5}$/', $place) === 1) {
                $q = $this->base($tenantId)->where('postal_code', $place);
                $city = (string) ($this->base($tenantId)->where('postal_code', $place)->value('city') ?? '');
                $state = (string) ($this->base($tenantId)->where('postal_code', $place)->value('state_or_province') ?? '');
                $meta = ['kind' => 'zip', 'slug' => $place, 'name' => $place.($city !== '' ? " ({$city}, {$state})" : ''), 'city' => $city ?: null, 'state' => $state ?: null, 'zip' => $place];
            } elseif (preg_match('/^([a-z0-9-]+)--([a-z0-9-]+)-([a-z]{2})$/', $place, $m) === 1) {
                // IDX9: a neighbourhood, "river-oaks--houston-tx".
                $state = strtoupper($m[3]);
                $pair = $this->base($tenantId)->where('state_or_province', $state)->whereNotNull('subdivision')->whereNotNull('city')
                    ->select('subdivision', 'city')->distinct()->limit(5000)->toBase()->get()
                    ->first(fn (object $r): bool => Str::slug((string) $r->subdivision) === $m[1] && Str::slug((string) $r->city) === $m[2]);
                if ($pair === null) {
                    return null;
                }
                $q = $this->base($tenantId)->where('state_or_province', $state)->where('city', $pair->city)->where('subdivision', $pair->subdivision);
                $meta = ['kind' => 'area', 'slug' => $place, 'name' => self::areaName((string) $pair->subdivision).', '.$pair->city, 'city' => (string) $pair->city,
                    'state' => $state, 'zip' => null, 'area' => (string) $pair->subdivision];
            } elseif (preg_match('/^([a-z0-9-]+)-([a-z]{2})$/', $place, $m) === 1) {
                $state = strtoupper($m[2]);
                $city = $this->base($tenantId)->where('state_or_province', $state)->whereNotNull('city')->distinct()->limit(2000)->pluck('city')
                    ->first(fn ($c): bool => Str::slug((string) $c) === $m[1]);
                if ($city === null) {
                    return null;
                }
                $q = $this->base($tenantId)->where('state_or_province', $state)->where('city', $city);
                $meta = ['kind' => 'city', 'slug' => $place, 'name' => "{$city}, {$state}", 'city' => (string) $city, 'state' => $state, 'zip' => null];
            } else {
                return null;
            }

            $rows = (clone $q)->limit(10000)->get(['list_price_cents', 'living_area_sqft', 'on_market_date', 'days_on_market', 'created_at', 'price_changed_at', 'property_type', 'postal_code']);
            if ($rows->count() < self::MIN_LISTINGS) {
                return null;
            }
            $prices = self::sorted($rows->pluck('list_price_cents')->filter()->map(fn ($c): int => intdiv((int) $c, 100))->all());
            $ppsf = self::sorted($rows->filter(fn (Listing $l): bool => $l->list_price_cents !== null && ($l->living_area_sqft ?? 0) > 200)
                ->map(fn (Listing $l): int => (int) round($l->list_price_cents / 100 / (int) $l->living_area_sqft))->all());
            $dom = self::sorted($rows->map(fn (Listing $l): ?int => $l->days_on_market ?? ($l->on_market_date !== null ? (int) $l->on_market_date->diffInDays($now) : null))
                ->filter(fn (?int $d): bool => $d !== null && $d >= 0)->all());
            $since = $now->subDays(30);
            $new = $rows->filter(fn (Listing $l): bool => ($l->on_market_date ?? $l->created_at)?->gte($since) ?? false)->count();
            $reduced = $rows->filter(fn (Listing $l): bool => $l->price_changed_at?->gte($since) ?? false)->count();
            $types = $rows->countBy(fn (Listing $l): string => (string) ($l->property_type ?? 'Other'))->sortDesc()->all();

            return $meta + [
                'as_of' => $now->toDateString(), 'active' => $rows->count(),
                'median_price' => self::median($prices), 'low_price' => self::percentile($prices, 0.1), 'high_price' => self::percentile($prices, 0.9),
                'median_ppsf' => self::median($ppsf), 'median_dom' => self::median($dom), 'new_30' => $new, 'reduced_30' => $reduced,
                'reduced_share' => (int) round($reduced * 100 / max(1, $rows->count())), 'types' => $types,
                'zips' => $meta['kind'] === 'city' ? array_values($rows->pluck('postal_code')->filter()->countBy()->filter(fn (int $n): bool => $n >= self::MIN_LISTINGS)->sortDesc()->keys()->map(fn ($z): string => (string) $z)->take(12)->all()) : [],
            ];
        });
    }

    /** IDX9: "RIVER OAKS SEC 02" → "river-oaks-sec-02--houston-tx"; null when the name has no letters. */
    public static function areaSlug(string $subdivision, string $city, string $state): ?string
    {
        $area = Str::slug($subdivision);
        if ($area === '' || strlen($area) > 60 || preg_match('/[a-z]/', $area) !== 1 || str_contains($area, '--')) {
            return null;
        }

        return $area.'--'.self::citySlug($city, $state);
    }

    /** MLS subdivision names are often in capitals; show them in title case. */
    public static function areaName(string $subdivision): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $subdivision) ?? $subdivision);

        return mb_strtoupper($name) === $name ? Str::title(mb_strtolower($name)) : $name;
    }

    public static function citySlug(string $city, string $state): string
    {
        return Str::slug($city).'-'.strtolower($state);
    }

    /**
     * @return Builder<Listing>
     */
    private function base(int $tenantId): Builder
    {
        return Listing::query()->visibleTo($tenantId, 'idx')->where('internet_display_allowed', true)->whereNull('off_market_at')
            ->whereIn('standard_status', ['Active', 'Coming Soon', 'Active Under Contract']);
    }

    /**
     * @param  array<array-key, int|null>  $values
     * @return list<int>
     */
    private static function sorted(array $values): array
    {
        $out = array_values(array_map('intval', array_filter($values, fn (?int $v): bool => $v !== null)));
        sort($out);

        return $out;
    }

    /**
     * @param  list<int>  $sorted
     */
    private static function median(array $sorted): ?int
    {
        $n = count($sorted);
        if ($n === 0) {
            return null;
        }

        return $n % 2 === 1 ? $sorted[intdiv($n, 2)] : (int) round(($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2);
    }

    /**
     * @param  list<int>  $sorted
     */
    private static function percentile(array $sorted, float $p): ?int
    {
        return $sorted === [] ? null : $sorted[(int) floor($p * (count($sorted) - 1))];
    }
}
