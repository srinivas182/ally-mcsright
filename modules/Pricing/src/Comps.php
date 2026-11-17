<?php

declare(strict_types=1);

namespace Modules\Pricing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Models\Listing;

/**
 * Finding comparable homes for a CMA (CM1, specs/listings/01). Sold homes only from MLSs the brokerage may use sold
 * data from (a back-office or VOW licence); otherwise homes for sale, clearly labelled as asking prices. The search
 * starts close and recent and widens until there are enough: 6 then 12 months, 1 then 2 then 3 miles (or the same
 * ZIP code when the home has no map position), ±25% then ±40% of the size, beds ±1. Each comp gets a plain reason.
 */
final class Comps
{
    /** @return array{sources: list<int>, sold: bool} */
    public function sources(int $tenantId): array
    {
        $access = app(MlsAccess::class);
        $sold = array_values(array_unique([...$access->licensedSources($tenantId, 'back_office'), ...$access->licensedSources($tenantId, 'vow')]));

        return $sold !== [] ? ['sources' => $sold, 'sold' => true] : ['sources' => $access->licensedSources($tenantId, 'idx'), 'sold' => false];
    }

    /**
     * Candidates, best first, each with its score, distance and reason.
     *
     * @param  array<string, mixed>  $s  the subject
     * @return list<array{listing: Listing, score: float, miles: float|null, reason: string}>
     */
    public function find(int $tenantId, array $s, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        ['sources' => $sources, 'sold' => $sold] = $this->sources($tenantId);
        if ($sources === []) {
            return [];
        }
        $cfg = (array) config('pricing.comps');
        $wanted = (int) $cfg['wanted'];
        $lat = isset($s['lat']) && is_numeric($s['lat']) ? (float) $s['lat'] : null;
        $lng = isset($s['lng']) && is_numeric($s['lng']) ? (float) $s['lng'] : null;
        $sqft = isset($s['sqft']) && (int) $s['sqft'] > 0 ? (int) $s['sqft'] : null;
        $beds = isset($s['beds']) && is_numeric($s['beds']) ? (int) $s['beds'] : null;
        $found = [];
        foreach ($cfg['months'] as $months) {
            foreach ($lat !== null && $lng !== null ? $cfg['miles'] : [null] as $miles) {
                foreach ($cfg['size'] as $band) {
                    $q = Listing::query()->whereIn('mls_source_id', $sources)
                        ->when($sold, fn ($w) => $w->where('standard_status', 'Closed')->whereNotNull('close_price_cents')->where('close_date', '>=', $today->subMonths((int) $months)->toDateString()),
                            fn ($w) => $w->whereIn('standard_status', ['Active', 'Active Under Contract', 'Pending'])->whereNull('off_market_at'))
                        ->when(isset($s['listing_id']), fn ($w) => $w->where('id', '!=', (int) $s['listing_id']))
                        ->when($sqft !== null, fn ($w) => $w->whereBetween('living_area_sqft', [(int) floor($sqft * (1 - $band)), (int) ceil($sqft * (1 + $band))]))
                        ->when($beds !== null, fn ($w) => $w->whereBetween('beds_total', [max(0, $beds - 1), $beds + 1]));
                    if ($miles !== null) {
                        $dLat = $miles / 69.0;
                        $dLng = $miles / max(1.0, 69.0 * cos(deg2rad((float) $lat)));
                        $q->whereBetween('latitude', [$lat - $dLat, $lat + $dLat])->whereBetween('longitude', [$lng - $dLng, $lng + $dLng]);
                    } else {
                        $q->where('postal_code', (string) ($s['zip'] ?? ''));
                    }
                    $rows = $q->limit(200)->get();
                    foreach ($rows as $l) {
                        $d = $lat !== null && $lng !== null && $l->latitude !== null && $l->longitude !== null ? self::miles($lat, $lng, (float) $l->latitude, (float) $l->longitude) : null;
                        if ($miles !== null && $d !== null && $d > $miles) {
                            continue;
                        }
                        $found[$l->id] ??= $this->scored($l, $s, $d, $sold, $today);
                    }
                    if (count($found) >= $wanted) {
                        break 3;
                    }
                }
            }
        }
        usort($found, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($found, 0, 18);
    }

    /**
     * Pool and garage spaces from the MLS record, where the MLS sends them.
     *
     * @param  list<int>  $ids
     * @return array<int, array{pool: bool|null, garage: int|null}>
     */
    public function extras(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (DB::connection(Connections::listings())->table('listing_payloads')->whereIn('listing_id', $ids)->get(['listing_id', 'payload']) as $p) {
            $data = (array) json_decode((string) $p->payload, true);
            $out[(int) $p->listing_id] = ['pool' => isset($data['PoolPrivateYN']) ? (bool) $data['PoolPrivateYN'] : null,
                'garage' => isset($data['GarageSpaces']) && is_numeric($data['GarageSpaces']) ? (int) $data['GarageSpaces'] : null];
        }

        return $out;
    }

    public static function miles(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 3958.8;
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array{listing: Listing, score: float, miles: float|null, reason: string}
     */
    private function scored(Listing $l, array $s, ?float $miles, bool $sold, CarbonImmutable $today): array
    {
        $sqft = (int) ($s['sqft'] ?? 0);
        $sizeDiff = $sqft > 0 && (int) $l->living_area_sqft > 0 ? abs((int) $l->living_area_sqft - $sqft) / $sqft : 0.3;
        $bedDiff = isset($s['beds']) && $l->beds_total !== null ? abs((int) $l->beds_total - (int) $s['beds']) : 1;
        $days = $sold && $l->close_date !== null ? (int) $l->close_date->diffInDays($today) : 0;
        $score = 100 - ($miles ?? 1.0) * 15 - $days / 9 - $sizeDiff * 80 - $bedDiff * 8;
        $sameStreet = $l->street_name !== null && isset($s['street']) && str_contains(mb_strtolower((string) $s['street']), mb_strtolower((string) $l->street_name));
        $parts = array_filter([
            $sameStreet ? 'same street' : ($miles !== null ? number_format($miles, 1).' mi away' : 'same ZIP code'),
            $bedDiff === 0 ? 'same beds' : null,
            $sizeDiff <= 0.1 ? 'similar size' : null,
            $sold ? ($days <= 1 ? 'sold yesterday' : "sold {$days} days ago") : 'for sale now',
        ]);

        return ['listing' => $l, 'score' => round($score + ($sameStreet ? 5 : 0), 2), 'miles' => $miles !== null ? round($miles, 2) : null, 'reason' => ucfirst(implode(' · ', $parts))];
    }
}
