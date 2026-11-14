<?php

declare(strict_types=1);

namespace Modules\Sites\Visitors;

use Illuminate\Database\Eloquent\Builder;
use Modules\Listings\Models\Listing;

/**
 * A home's likely price range from comparable homes for sale in the same ZIP (specs/websites/07): similar size
 * (±30%) and bedrooms (±1) when known, by price per square foot; widened step by step when there are too few.
 * It is a starting point the agent confirms with recent sales, and the page says so.
 */
final class Valuation
{
    public const MIN_COMPS = 3;

    /**
     * @return array{low: int, mid: int, high: int, comps: int, basis: string, comp_ulids: list<string>}|null
     */
    public function estimate(int $tenantId, string $zip, ?int $beds, ?int $sqft): ?array
    {
        if ($sqft !== null && $sqft >= 300) {
            // Narrowest first: size ±30% and beds ±1, then size ±40%, then any home with a size.
            foreach ([[0.7, 1.3, true], [0.6, 1.4, false], [null, null, false]] as [$low, $high, $useBeds]) {
                $q = $this->base($tenantId, $zip);
                $low !== null && $high !== null ? $q->whereBetween('living_area_sqft', [(int) ($sqft * $low), (int) ($sqft * $high)]) : $q->where('living_area_sqft', '>', 300);
                if ($useBeds && $beds !== null) {
                    $q->whereBetween('beds_total', [$beds - 1, $beds + 1]);
                }
                $rows = $q->limit(400)->get(['ulid', 'list_price_cents', 'living_area_sqft']);
                if ($rows->count() >= self::MIN_COMPS) {
                    $values = [];
                    foreach ($rows as $l) {
                        $values[] = (int) $l->list_price_cents / 100 / max(1, (int) $l->living_area_sqft) * $sqft;
                    }
                    $closest = $rows->sortBy(fn (Listing $l): int => abs((int) $l->living_area_sqft - $sqft))->take(3);

                    return self::range($values, $rows->count(), 'size', self::ulids($closest->all()));
                }
            }

            return null;
        }
        $q = $this->base($tenantId, $zip);
        if ($beds !== null) {
            $q->where('beds_total', $beds);
        }
        $rows = $q->limit(400)->get(['ulid', 'list_price_cents']);
        if ($rows->count() < self::MIN_COMPS) {
            return null;
        }
        $values = [];
        foreach ($rows as $l) {
            $values[] = (int) $l->list_price_cents / 100;
        }

        return self::range($values, $rows->count(), 'price', self::ulids($rows->take(3)->all()));
    }

    /**
     * @return Builder<Listing>
     */
    private function base(int $tenantId, string $zip): Builder
    {
        return Listing::query()->visibleTo($tenantId, 'idx')->where('internet_display_allowed', true)->whereNull('off_market_at')
            ->whereIn('standard_status', ['Active', 'Coming Soon', 'Active Under Contract', 'Pending'])->where('postal_code', $zip)->whereNotNull('list_price_cents');
    }

    /**
     * @param  array<int, Listing>  $listings
     * @return list<string>
     */
    private static function ulids(array $listings): array
    {
        $out = [];
        foreach ($listings as $l) {
            $out[] = $l->ulid;
        }

        return $out;
    }

    /**
     * @param  list<float>  $values
     * @param  list<string>  $ulids
     * @return array{low: int, mid: int, high: int, comps: int, basis: string, comp_ulids: list<string>}
     */
    private static function range(array $values, int $comps, string $basis, array $ulids): array
    {
        sort($values);
        $at = static fn (float $p): int => (int) (round($values[(int) floor($p * (count($values) - 1))] / 5000) * 5000);

        return ['low' => $at(0.25), 'mid' => $at(0.5), 'high' => $at(0.75), 'comps' => $comps, 'basis' => $basis, 'comp_ulids' => $ulids];
    }
}
