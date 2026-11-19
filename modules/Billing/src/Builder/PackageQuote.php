<?php

declare(strict_types=1);

namespace Modules\Billing\Builder;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Credits\Economics;
use Modules\Core\Support\Connections;

/**
 * PB1: what a package costs RightAlly to run, and the price at HQ's markup.
 *
 * - Every module's included usage is priced from the cost list (real provider prices, kept up to date in HQ →
 *   Credits) as if all of it is used ("full use"), and at HQ's typical use (half by default) for information.
 * - AI credits are priced at the typical cost per credit from real use; the worst case (every credit on the costliest
 *   AI action) is shown as a warning, not used for the price.
 * - Fixed costs: the platform cost per person (seat) and per brokerage (hosting).
 * - Card fees (Stripe, 2.9% + $0.30 by default) are worked out on the price and added to the cost.
 * - Price = full-use cost × (1 + markup), rounded up to whole dollars ending in 9. Markup 70% by default (cost
 *   $100, price $170): you keep 70 of every 170, a margin of about 41%. Both numbers are always shown.
 *
 * All money in micro-cents (millionths of a cent) inside; cents in the result.
 */
final class PackageQuote
{
    public function __construct(private readonly Economics $economics) {}

    /**
     * @return array{markup: int, min_markup: int, typical_use: int, card_fee_bp: int, card_fee_fixed_cents: int, yearly_months: int}
     */
    public static function rules(): array
    {
        $row = DB::connection(Connections::control())->table('credit_settings')->first();

        return ['markup' => (int) ($row->markup_pct ?? 70), 'min_markup' => (int) ($row->min_markup_pct ?? 40), 'typical_use' => (int) ($row->typical_use_pct ?? 50),
            'card_fee_bp' => (int) ($row->card_fee_bp ?? 290), 'card_fee_fixed_cents' => (int) ($row->card_fee_fixed_cents ?? 30), 'yearly_months' => (int) ($row->yearly_months ?? 10)];
    }

    /**
     * @param  list<string>  $modules
     * @param  array<string, array<string, int>>  $usage  module => usage key => amount (overrides the defaults)
     * @return array<string, mixed>
     */
    public function quote(string $audience, array $modules, int $seats, array $usage = [], ?int $markup = null): array
    {
        $rules = self::rules();
        $markup ??= $rules['markup'];
        $seats = max(1, min(10_000, $seats));
        $catalog = $this->economics->catalogCosts();
        $labels = DB::connection(Connections::control())->table('cost_catalog')->get(['key', 'label', 'unit', 'source_url', 'note'])->keyBy('key');
        $typicalCredit = $this->economics->typicalCostPerCredit();
        $worstCredit = $this->economics->worstCostPerCredit();
        $offered = ModuleCatalog::for($audience);
        $chosen = array_values(array_unique([...array_keys(array_filter($offered, fn (array $m): bool => $m['always'] ?? false)), ...array_intersect($modules, array_keys($offered))]));

        $lines = [];
        $full = 0;
        $typical = 0;
        $worst = 0;
        $included = [];
        foreach ($chosen as $key) {
            $m = $offered[$key];
            $items = [];
            $moduleFull = 0;
            foreach ($m['usage'] as [$usageKey, $costKey, $default, $per]) {
                $amount = max(0, (int) ($usage[$key][$usageKey] ?? $default));
                $units = $per === 'seat' ? $amount * $seats : $amount;
                $included[$usageKey] = ($included[$usageKey] ?? 0) + $units;
                $unit = $costKey === ModuleCatalog::AI_CREDIT ? $typicalCredit : ($catalog[$costKey] ?? 0);
                $cost = $units * $unit;
                $moduleFull += $cost;
                $worst += $costKey === ModuleCatalog::AI_CREDIT ? $units * $worstCredit : $cost;
                $label = ModuleCatalog::usageLabel($usageKey);
                $source = $costKey === ModuleCatalog::AI_CREDIT ? 'typical AI cost per credit from the last 30 days' : (string) ($labels[$costKey]->note ?? $labels[$costKey]->label ?? $costKey);
                $items[] = ['usage' => $usageKey, 'label' => $label, 'amount' => $amount, 'per' => $per, 'units' => $units, 'unit_cost_microcents' => $unit,
                    'cost_cents' => self::cents($cost), 'priced' => $unit > 0,
                    'tip' => number_format($units).' '.$label.' × '.self::money($unit).' = '.self::money($cost).' ('.$source.')'];
            }
            $full += $moduleFull;
            $typical += (int) round($moduleFull * $rules['typical_use'] / 100);
            $lines[] = ['key' => $key, 'label' => $m['label'], 'about' => $m['about'], 'items' => $items, 'cost_cents' => self::cents($moduleFull)];
        }

        // Fixed: per person and per brokerage.
        $perSeat = (int) ($catalog['platform.seat'] ?? 0) * $seats;
        $perBrokerage = (int) DB::connection(Connections::control())->table('cost_catalog')->where('kind', 'monthly')->sum('cost_microcents');
        $fixed = $perSeat + $perBrokerage;

        // Card fees on the price (worked out on the price before fees, then added to the cost).
        $before = ($full + $fixed) * (100 + $markup) / 100;
        $fees = (int) round($before * $rules['card_fee_bp'] / 10_000) + $rules['card_fee_fixed_cents'] * 1_000_000;
        $cost = $full + $fixed + $fees;
        $price = self::roundPrice((int) ceil($cost * (100 + $markup) / 100 / 100_000_000)); // whole dollars

        return [
            'audience' => $audience, 'seats' => $seats, 'modules' => $chosen, 'lines' => $lines, 'included' => $included,
            'fixed' => ['per_seat_cents' => self::cents($perSeat), 'per_brokerage_cents' => self::cents($perBrokerage),
                'tip' => $seats.' '.($seats === 1 ? 'person' : 'people').' × '.self::money((int) ($catalog['platform.seat'] ?? 0)).' platform cost, plus '.self::money($perBrokerage).' hosting per brokerage'],
            'card_fees_cents' => self::cents($fees),
            'cost_cents' => self::cents($cost), 'typical_cost_cents' => self::cents($typical + $fixed + $fees), 'worst_cost_cents' => self::cents($worst + $fixed + $fees),
            'markup_pct' => $markup, 'price_cents' => $price, 'yearly_cents' => $price * $rules['yearly_months'],
            'margin_pct' => $price > 0 ? (int) floor(($price - self::cents($cost)) * 100 / $price) : 0,
            'worst_margin_pct' => $price > 0 ? (int) floor(($price - self::cents($worst + $fixed + $fees)) * 100 / $price) : 0,
            'unpriced' => self::unpriced($lines),
            'ai_priced' => $typicalCredit > 0,
        ];
    }

    /**
     * Check a price HQ typed against the minimum markup.
     *
     * @param  array<string, mixed>  $quote
     */
    public static function checkPrice(array $quote, int $priceCents, ?string $reason): void
    {
        $cost = (int) $quote['cost_cents'];
        $min = self::rules()['min_markup'];
        if ($cost > 0 && $priceCents * 100 < $cost * (100 + $min) && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['price' => 'This price is below cost plus '.$min.'%. Write why (for example "launch offer until March"), or raise the price.']);
        }
    }

    /** Whole dollars ending in 9, at or above the amount ($163 → $169, $170 → $179, $5 → $9). */
    public static function roundPrice(int $dollars): int
    {
        $dollars = max(9, $dollars);
        $up = $dollars % 10 === 9 ? $dollars : $dollars + (9 - $dollars % 10);

        return $up * 100;
    }

    /**
     * Included usage with no cost entered yet (shown so HQ fills it in).
     *
     * @param  list<array{items: list<array{label: string, priced: bool}>}>  $lines
     * @return list<string>
     */
    private static function unpriced(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            foreach ($l['items'] as $i) {
                if (! $i['priced']) {
                    $out[$i['label']] = true;
                }
            }
        }

        return array_keys($out);
    }

    private static function cents(int $microcents): int
    {
        return (int) ceil($microcents / 1_000_000);
    }

    private static function money(int $microcents): string
    {
        $dollars = $microcents / 100_000_000;

        return '$'.($dollars >= 1 ? number_format($dollars, 2) : rtrim(rtrim(number_format($dollars, 4), '0'), '.'));
    }
}
