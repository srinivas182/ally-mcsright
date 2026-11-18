<?php

declare(strict_types=1);

namespace Modules\Sites\Funnels;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Listings\Display\ListingPresenter;
use Modules\Listings\Models\Listing;

/**
 * "Homes like yours that sold" (MK3, C23, specs/grow/03): recent sales near a home, for the monthly home report and
 * the homeowner's dashboard. Only what the MLS allows: a sale is shown only where its MLS permits sold data and its
 * waiting period has passed (ListingPresenter), and the sold-price range uses only sales the MLS allows for
 * valuations (avm_allowed).
 *
 * Also "since you bought": what they paid and when, from their closed purchase with the brokerage.
 */
final class SoldComps
{
    public const MONTHS = 6;

    /**
     * Up to $limit recent sales like this home (same ZIP; beds ±1; size ±25% when known), newest first, and the
     * range of what similar homes sold for (when at least three sales may be used).
     *
     * @return array{homes: list<array<string, mixed>>, range: array{low: int, high: int, count: int}|null}
     */
    public function near(int $tenantId, string $zip, ?int $beds, ?int $sqft, int $limit = 3): array
    {
        $q = Listing::query()->visibleTo($tenantId)->where('standard_status', 'Closed')->where('postal_code', $zip)->whereNotNull('close_price_cents')
            ->where('close_date', '>=', CarbonImmutable::today()->subMonths(self::MONTHS)->toDateString());
        if ($beds !== null) {
            $q->whereBetween('beds_total', [max(0, $beds - 1), $beds + 1]);
        }
        if ($sqft !== null && $sqft >= 300) {
            $q->whereBetween('living_area_sqft', [(int) ($sqft * 0.75), (int) ($sqft * 1.25)]);
        }
        $rows = $q->with('source.rulesProfile')->orderByDesc('close_date')->orderByDesc('id')->limit(60)->get();
        $homes = [];
        foreach ($rows as $l) {
            if (count($homes) >= $limit) {
                break;
            }
            $shown = ListingPresenter::forDisplay($l);
            if ($shown !== null && ($shown['price'] ?? null) !== null) {
                $homes[] = $shown;
            }
        }
        $prices = $rows->filter(fn (Listing $l): bool => $l->avm_allowed)->map(fn (Listing $l): int => intdiv((int) $l->close_price_cents, 100))->sort()->values();
        $range = null;
        if ($prices->count() >= 3) {
            // The middle of the sales (25th to 75th percentile), so one odd sale doesn't skew it.
            $at = fn (float $p): int => (int) $prices[(int) floor(($prices->count() - 1) * $p)];
            $range = ['low' => (int) (round($at(0.25) / 1000) * 1000), 'high' => (int) (round($at(0.75) / 1000) * 1000), 'count' => $prices->count()];
        }

        return ['homes' => $homes, 'range' => $range];
    }

    /**
     * What a past client paid for this address and when, from their closed purchase (null when unknown).
     *
     * @return array{price: int, on: string}|null
     */
    public static function purchase(int $contactId, string $address): ?array
    {
        $street = mb_strtolower(trim(explode(',', $address)[0]));
        $deal = rescue(fn () => DB::connection(Connections::cell())->table('tx_deals')->where('contact_id', $contactId)->whereIn('type', ['buyer', 'dual', 'new_construction'])
            ->where('status', 'closed')->whereNotNull('closed_on')->where('price_cents', '>', 0)->orderByDesc('closed_on')->get(['street', 'price_cents', 'closed_on'])
            ->first(fn ($d): bool => $street !== '' && mb_strtolower(trim((string) $d->street)) === $street), null, false); // no deals module: unknown

        return $deal !== null ? ['price' => intdiv((int) $deal->price_cents, 100), 'on' => substr((string) $deal->closed_on, 0, 10)] : null;
    }

    /** Percent change from what they paid to today's middle estimate (null when either is unknown). */
    public static function sinceBought(?int $boughtPrice, ?int $mid): ?float
    {
        return $boughtPrice !== null && $boughtPrice > 0 && $mid !== null ? round(($mid - $boughtPrice) * 100 / $boughtPrice, 1) : null;
    }
}
