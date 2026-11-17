<?php

declare(strict_types=1);

namespace Modules\Pricing;

use Carbon\CarbonImmutable;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Models\Listing;

/**
 * Turning comps into a price (CM1, specs/listings/01). Each comp's price is adjusted to be like the subject home,
 * line by line, with every amount shown and editable by the agent: size (half the comps' median price per square
 * foot), bedrooms and bathrooms, age, pool, garage and lot (rates in config/pricing.php). The range is the middle
 * half of the adjusted prices around their weighted average (closer, more recent, more similar comps count more).
 * Confidence says how much to trust it, and why.
 */
final class Valuer
{
    /**
     * @param  array<string, mixed>  $s  subject
     * @param  list<array{listing: Listing, score: float, miles: float|null, reason: string, extras: array{pool: bool|null, garage: int|null}, overrides: array<string, int>}>  $comps
     * @return array{comps: list<array<string, mixed>>, low_cents: int, likely_cents: int, high_cents: int, confidence: string, confidence_reason: string, median_ppsf_cents: int}
     */
    public function value(array $s, array $comps, bool $sold): array
    {
        $prices = array_map(fn (array $c): int => self::price($c['listing'], $sold), $comps);
        $ppsf = array_values(array_filter(array_map(fn (array $c): ?float => (int) $c['listing']->living_area_sqft > 0 ? self::price($c['listing'], $sold) / (int) $c['listing']->living_area_sqft : null, $comps)));
        $medianPrice = (int) round(self::median($prices));
        $medianPpsf = self::median($ppsf);
        $a = (array) config('pricing.adjustments');
        $out = [];
        foreach ($comps as $c) {
            $l = $c['listing'];
            $lines = [];
            $add = function (string $key, string $label, float $cents) use (&$lines, $c): void {
                $value = array_key_exists($key, $c['overrides']) ? (int) $c['overrides'][$key] : (int) (round($cents / 100_000) * 100_000);
                if ($value !== 0 || array_key_exists($key, $c['overrides'])) {
                    $lines[] = ['key' => $key, 'label' => $label, 'cents' => $value, 'edited' => array_key_exists($key, $c['overrides'])];
                }
            };
            if (isset($s['sqft']) && (int) $s['sqft'] > 0 && (int) $l->living_area_sqft > 0) {
                $diff = (int) $s['sqft'] - (int) $l->living_area_sqft;
                $add('size', sprintf('Size (%s sq ft %s)', number_format(abs($diff)), $diff >= 0 ? 'smaller' : 'larger'), $diff * $medianPpsf * (float) $a['size_factor']);
            }
            foreach ([['beds', 'beds_total', 'bed_pct', 'Bedrooms'], ['baths_full', 'baths_full', 'full_bath_pct', 'Full baths'], ['baths_half', 'baths_half', 'half_bath_pct', 'Half baths']] as [$key, $col, $rate, $label]) {
                if (isset($s[$key]) && is_numeric($s[$key]) && $l->{$col} !== null) {
                    $add($key, $label, ((int) $s[$key] - (int) $l->{$col}) * $medianPrice * (float) $a[$rate] / 100);
                }
            }
            if (isset($s['year_built']) && (int) $s['year_built'] > 1800 && (int) $l->year_built > 1800) {
                $years = max(-(int) $a['age_cap'], min((int) $a['age_cap'], (int) $s['year_built'] - (int) $l->year_built));
                $add('age', 'Age (built '.$l->year_built.')', $years * $medianPrice * (float) $a['age_pct'] / 100);
            }
            if (isset($s['pool']) && is_bool($s['pool']) && $c['extras']['pool'] !== null && $s['pool'] !== $c['extras']['pool']) {
                $add('pool', $s['pool'] ? 'No pool' : 'Has a pool', ($s['pool'] ? 1 : -1) * $medianPrice * (float) $a['pool_pct'] / 100);
            }
            if (isset($s['garage']) && is_numeric($s['garage']) && $c['extras']['garage'] !== null) {
                $add('garage', 'Garage ('.$c['extras']['garage'].' spaces)', (min(3, (int) $s['garage']) - min(3, $c['extras']['garage'])) * $medianPrice * (float) $a['garage_pct'] / 100);
            }
            if (isset($s['lot_sqft']) && (int) $s['lot_sqft'] > 0 && (int) $l->lot_size_sqft > 0) {
                $share = ((int) $s['lot_sqft'] - (int) $l->lot_size_sqft) / (int) $l->lot_size_sqft;
                $add('lot', 'Lot ('.number_format((int) $l->lot_size_sqft).' sq ft)', max(-5, min(5, $share * 10 * (float) $a['lot_pct'])) * $medianPrice / 100);
            }
            $price = self::price($l, $sold);
            $out[] = ['listing_id' => $l->id, 'ulid' => $l->ulid, 'address' => $l->address(), 'city' => $l->city, 'status' => $l->standard_status, 'price_cents' => $price,
                'sold_on' => $sold ? $l->close_date?->toDateString() : null, 'beds' => $l->beds_total, 'baths_full' => $l->baths_full, 'baths_half' => $l->baths_half,
                'sqft' => $l->living_area_sqft, 'year_built' => $l->year_built, 'dom' => $l->days_on_market, 'miles' => $c['miles'], 'reason' => $c['reason'], 'score' => $c['score'],
                'adjustments' => $lines, 'adjusted_cents' => $price + array_sum(array_column($lines, 'cents')), 'photo' => $l->cover_path];
        }
        if ($out === []) {
            return ['comps' => [], 'low_cents' => 0, 'likely_cents' => 0, 'high_cents' => 0, 'confidence' => 'none', 'confidence_reason' => 'No comparable homes chosen.', 'median_ppsf_cents' => 0];
        }
        $adjusted = array_column($out, 'adjusted_cents');
        $weights = array_map(fn (array $c): float => max(1.0, (float) $c['score']), $out);
        $likely = array_sum(array_map(fn (int $p, float $w): float => $p * $w, $adjusted, $weights)) / array_sum($weights);
        sort($adjusted);
        $low = min($likely, self::percentile($adjusted, 0.25));
        $high = max($likely, self::percentile($adjusted, 0.75));
        $round = fn (float $c): int => (int) (round($c / 100_000) * 100_000);
        $n = count($out);
        $spread = $likely > 0 ? ($high - $low) / $likely : 1;
        [$confidence, $why] = match (true) {
            $n >= 5 && $spread <= 0.08 => ['high', "{$n} close comps that agree within ".(int) round($spread * 100).'%.'],
            $n >= 3 && $spread <= 0.15 => ['medium', "{$n} comps within ".(int) round($spread * 100).'% of each other.'],
            default => ['low', $n < 3 ? "Only {$n} comparable ".($n === 1 ? 'home' : 'homes').'.' : 'The comps differ by '.(int) round($spread * 100).'%; check them before sharing.'],
        };
        if (! $sold) {
            $confidence = 'low';
            $why = 'Asking prices of homes for sale, not sold prices: homes often sell for a different price.';
        }

        return ['comps' => $out, 'low_cents' => $round($low), 'likely_cents' => $round($likely), 'high_cents' => $round($high), 'confidence' => $confidence, 'confidence_reason' => $why,
            'median_ppsf_cents' => (int) round($medianPpsf)];
    }

    /**
     * The market in the subject's ZIP code: last 90 days.
     *
     *
     * @param  list<int>  $soldSources
     * @return array{zip: string, sold_90: int, median_dom: int|null, sale_to_list: float|null, active: int, months_of_inventory: float|null, ppsf_change: float|null}
     */
    public function market(int $tenantId, string $zip, array $soldSources, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $sold = $soldSources === [] ? collect() : Listing::query()->whereIn('mls_source_id', $soldSources)->where('postal_code', $zip)->where('standard_status', 'Closed')
            ->where('close_date', '>=', $today->subMonths(6)->toDateString())->get(['close_price_cents', 'list_price_cents', 'close_date', 'days_on_market', 'living_area_sqft']);
        $recent = $sold->filter(fn (Listing $l): bool => $l->close_date !== null && $l->close_date->gte($today->subDays(90)));
        $active = Listing::query()->visibleTo($tenantId, 'idx')->where('postal_code', $zip)->where('standard_status', 'Active')->whereNull('off_market_at')->count();
        $ratio = $recent->filter(fn (Listing $l): bool => (int) $l->list_price_cents > 0)->map(fn (Listing $l): float => (int) $l->close_price_cents / (int) $l->list_price_cents)->values()->all();
        $ppsf = fn ($rows): float => self::median($rows->filter(fn (Listing $l): bool => (int) $l->living_area_sqft > 0)->map(fn (Listing $l): float => (int) $l->close_price_cents / (int) $l->living_area_sqft)->values()->all());
        $now = $ppsf($sold->filter(fn (Listing $l): bool => $l->close_date !== null && $l->close_date->gte($today->subMonths(3))));
        $before = $ppsf($sold->filter(fn (Listing $l): bool => $l->close_date !== null && $l->close_date->lt($today->subMonths(3))));
        $monthly = $recent->count() / 3;

        return ['zip' => $zip, 'sold_90' => $recent->count(), 'median_dom' => $recent->isEmpty() ? null : (int) round(self::median($recent->pluck('days_on_market')->filter()->map(fn ($d): int => (int) $d)->values()->all())),
            'sale_to_list' => $ratio === [] ? null : round(self::median($ratio) * 100, 1), 'active' => $active,
            'months_of_inventory' => $monthly > 0 ? round($active / $monthly, 1) : null, 'ppsf_change' => $before > 0 && $now > 0 ? round(100 * ($now - $before) / $before, 1) : null];
    }

    public static function price(Listing $l, bool $sold): int
    {
        return (int) ($sold ? ($l->close_price_cents ?? $l->list_price_cents) : $l->list_price_cents);
    }

    /** @param array<int|float> $values */
    public static function median(array $values): float
    {
        $values = array_values($values);
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);

        return $n % 2 === 1 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }

    /** @param list<int> $sorted */
    private static function percentile(array $sorted, float $p): float
    {
        $i = ($n = count($sorted)) > 1 ? $p * ($n - 1) : 0;
        $lo = (int) floor($i);
        $hi = (int) ceil($i);

        return $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($i - $lo);
    }

    /** @return list<int> */
    public static function soldSources(int $tenantId): array
    {
        $access = app(MlsAccess::class);

        return array_values(array_unique([...$access->licensedSources($tenantId, 'back_office'), ...$access->licensedSources($tenantId, 'vow')]));
    }
}
