<?php

declare(strict_types=1);

namespace Modules\Movewise\Growth;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Connections;
use Modules\Listings\Access\MlsAccess;
use Modules\Movewise\Movewise;

/**
 * Market facts for Movewise's city, ZIP code and neighbourhood pages (specs/movewise/07), from the listings Movewise
 * may show. Plain numbers with what they count, so search engines and AI answers can quote them: homes for sale,
 * the median asking price and price per square foot, new this week, typical days on market, and a six-month report
 * of new listings and (where the MLS shares them) sales.
 */
final class Places
{
    public function __construct(private readonly Movewise $movewise) {}

    /**
     * @param  array{state: string, city?: string|null, zip?: string|null, hood?: string|null}  $where
     * @return array<string, mixed>|null null when Movewise has no homes there
     */
    public function facts(array $where): ?array
    {
        $tenant = $this->movewise->feedTenant();
        if ($tenant === null) {
            return null;
        }
        $sources = app(MlsAccess::class)->licensedSources($tenant->id);
        $city = $where['city'] ?? null;
        $zip = $where['zip'] ?? null;
        $hood = $where['hood'] ?? null;
        $base = fn (): Builder => DB::connection(Connections::listings())->table('listings')->whereIn('mls_source_id', $sources)->where('state_or_province', $where['state'])
            ->where('internet_display_allowed', true)
            ->when($city !== null, fn (Builder $q) => $q->whereRaw('lower(city) = ?', [mb_strtolower((string) $city)]))
            ->when($zip !== null, fn (Builder $q) => $q->where('postal_code', 'like', $zip.'%'))
            ->when($hood !== null, fn (Builder $q) => $q->whereRaw('lower(subdivision) = ?', [mb_strtolower((string) $hood)]));
        $active = fn (): Builder => $base()->whereNull('off_market_at')->whereIn('standard_status', ['Active', 'Coming Soon']);
        $count = $active()->count();
        if ($count === 0) {
            return null;
        }
        $prices = array_map('intval', $active()->whereNotNull('list_price_cents')->orderBy('list_price_cents')->pluck('list_price_cents')->all());
        $ppsf = $active()->whereNotNull('list_price_cents')->where('living_area_sqft', '>', 200)->get(['list_price_cents', 'living_area_sqft'])
            ->map(fn ($r): float => $r->list_price_cents / 100 / max(1, (int) $r->living_area_sqft))->sort()->values()->all();
        $dom = array_map('intval', $active()->whereNotNull('days_on_market')->orderBy('days_on_market')->pluck('days_on_market')->all());
        $now = CarbonImmutable::now();
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = $now->subMonthsNoOverflow($i)->startOfMonth();
            $sold = $base()->where('standard_status', 'Closed')->whereBetween('close_date', [$m->toDateString(), $m->endOfMonth()->toDateString()]);
            $closePrices = array_map('intval', (clone $sold)->whereNotNull('close_price_cents')->orderBy('close_price_cents')->pluck('close_price_cents')->all());
            $months[] = ['month' => $m->format('M Y'), 'new' => $base()->whereBetween('created_at', [$m, $m->endOfMonth()])->count(), 'sold' => (clone $sold)->count(),
                'median_sold' => $closePrices !== [] ? intdiv($closePrices[intdiv(count($closePrices), 2)], 100) : null];
        }
        $zips = $zip !== null ? [] : $active()->whereNotNull('postal_code')->groupBy(DB::raw('substr(postal_code, 1, 5)'))->orderByDesc(DB::raw('count(*)'))->limit(30)
            ->get([DB::raw('substr(postal_code, 1, 5) as zip'), DB::raw('count(*) as n')])->map(fn ($r): array => ['zip' => (string) $r->zip, 'count' => (int) $r->n])->all();
        $hoods = $city === null || $hood !== null ? [] : $active()->whereNotNull('subdivision')->where('subdivision', '!=', '')
            ->groupBy('subdivision')->havingRaw('count(*) >= 1')->orderByDesc(DB::raw('count(*)'))->limit(30)->get([DB::raw('subdivision'), DB::raw('count(*) as n')])
            ->map(fn ($r): array => ['name' => (string) $r->subdivision, 'slug' => Str::slug((string) $r->subdivision), 'count' => (int) $r->n])->all();

        return ['count' => $count, 'median_price' => $prices !== [] ? intdiv($prices[intdiv(count($prices), 2)], 100) : null,
            'median_ppsf' => $ppsf !== [] ? (int) round($ppsf[intdiv(count($ppsf), 2)]) : null, 'median_days' => $dom !== [] ? $dom[intdiv(count($dom), 2)] : null,
            'new_week' => $active()->where('created_at', '>=', $now->subDays(7))->count(), 'months' => $months, 'zips' => $zips, 'hoods' => $hoods,
            'as_of' => $now->toDateString()];
    }

    /** The real city name for a slug ("sugar-land" → "Sugar Land"), from the listings. */
    public function city(string $state, string $slug): ?string
    {
        $tenant = $this->movewise->feedTenant();
        if ($tenant === null) {
            return null;
        }
        foreach (DB::connection(Connections::listings())->table('listings')->whereIn('mls_source_id', app(MlsAccess::class)->licensedSources($tenant->id))
            ->where('state_or_province', $state)->whereNotNull('city')->distinct()->pluck('city') as $c) {
            if (Str::slug((string) $c) === $slug) {
                return (string) $c;
            }
        }

        return null;
    }

    /** The real neighbourhood name for a slug, within a city. */
    public function hood(string $state, string $city, string $slug): ?string
    {
        $tenant = $this->movewise->feedTenant();
        if ($tenant === null) {
            return null;
        }
        foreach (DB::connection(Connections::listings())->table('listings')->whereIn('mls_source_id', app(MlsAccess::class)->licensedSources($tenant->id))
            ->where('state_or_province', $state)->whereRaw('lower(city) = ?', [mb_strtolower($city)])->whereNotNull('subdivision')->distinct()->pluck('subdivision') as $h) {
            if (Str::slug((string) $h) === $slug) {
                return (string) $h;
            }
        }

        return null;
    }
}
