<?php

declare(strict_types=1);

namespace Modules\Billing\Credits;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\Billing\Models\Package;
use Modules\Core\Support\Connections;

/**
 * What credits really cost RightAlly (specs/billing/02). For each priced action it works out a planning cost, the
 * higher of:
 * - the real cost at the 90th percentile over the last 30 days, including retries and failed attempts;
 * - an estimate of one use on the most expensive model the action can fall back to.
 * From that it suggests credits per action for HQ's target margin, and shows each package's margin for a typical
 * customer and for the worst case (every included credit spent on the costliest action).
 *
 * All money is in micro-cents (millionths of a cent).
 *
 * @phpstan-type ActionEconomics array{action: string, label: string, credits: int, automation: bool, feature: string|null,
 *     model: array{name: string, provider: string, input_per_mtok_cents: int, output_per_mtok_cents: int, audio_per_minute_microcents: int}|null,
 *     costliest_model: string|null, estimate: array{tokens_in: int, tokens_out: int, audio_seconds: int},
 *     uses_30d: int, avg_tokens_in: int|null, avg_tokens_out: int|null, avg_cost_microcents: int|null, p90_cost_microcents: int|null,
 *     estimate_microcents: int|null, planning_microcents: int|null, revenue_microcents: int, margin_pct: int|null,
 *     suggested_credits: int|null, cost_per_credit_microcents: int|null, tokens_per_credit: int|null, below_floor: bool}
 */
final class Economics
{
    /** Non-AI actions priced from the cost list: [cost item, units per use]. */
    public const USAGE_COSTS = [
        'usage.text_segment' => ['twilio.sms_segment', 1],
        'usage.call_minute' => ['twilio.call_minute', 1],
        'usage.map_views_10' => ['mapbox.map_load', 10],
    ];

    /** Package allowance columns and the cost item each is priced at. */
    public const ALLOWANCES = [
        'included_text_segments' => 'twilio.sms_segment',
        'included_call_minutes' => 'twilio.call_minute',
        'included_map_views' => 'mapbox.map_load',
    ];

    /** @var array<string, ActionEconomics>|null */
    private ?array $actions = null;

    public function __construct(private readonly RateCard $rates) {}

    /**
     * @return array<string, ActionEconomics>
     */
    public function actions(): array
    {
        if ($this->actions !== null) {
            return $this->actions;
        }
        $value = $this->rates->creditValueMicrocents();
        $margins = $this->rates->margins();
        $estimates = $this->rates->estimates();
        $routes = AiFeatureRoute::query()->get()->groupBy('credit_action_key');
        $models = AiModel::query()->with('provider')->get()->keyBy('id');
        $real = $this->realCosts();
        $catalog = $this->catalogCosts();

        $out = [];
        foreach ($this->rates->all() as $key => $rate) {
            $est = $estimates[$key];
            $featureRoutes = $routes->get($key);
            $route = $featureRoutes?->first();
            $primary = $route !== null ? $models->get($route->primary_model_id) : null;
            // Estimate on the costliest model any of this action's routes can use (primary or fallback).
            $estimate = null;
            $costliest = null;
            foreach ($featureRoutes ?? [] as $r) {
                foreach (array_unique([$r->primary_model_id, ...$r->fallback_model_ids]) as $id) {
                    $m = $models->get($id);
                    if ($m === null) {
                        continue;
                    }
                    $c = $est['audio_seconds'] > 0 && $m->audio_cost_per_minute_microcents > 0
                        ? (int) ceil($est['audio_seconds'] * $m->audio_cost_per_minute_microcents / 60)
                        : $m->costMicrocents($est['tokens_in'], $est['tokens_out']);
                    if ($estimate === null || $c > $estimate) {
                        $estimate = $c;
                        $costliest = $m->display_name;
                    }
                }
            }
            if (isset(self::USAGE_COSTS[$key])) {
                [$item, $units] = self::USAGE_COSTS[$key];
                $estimate = isset($catalog[$item]) ? $catalog[$item] * $units : null;
            }
            $stats = $route !== null ? ($real[$route->feature_key] ?? null) : null;
            $planning = max($stats['p90'] ?? 0, $estimate ?? 0);
            $planning = ($stats === null && $estimate === null) ? null : $planning;
            $revenue = $rate['credits'] * $value;
            $margin = $planning !== null && $revenue > 0 ? (int) floor(($revenue - $planning) * 100 / $revenue) : null;
            $suggested = $planning !== null ? max(1, (int) ceil($planning / max(1, $value * (100 - $margins['target']) / 100))) : null;

            $tokensPerCredit = null;
            if ($primary !== null) {
                $in = $stats['avg_in'] ?? $est['tokens_in'];
                $outT = $stats['avg_out'] ?? $est['tokens_out'];
                $perMillion = ($in * $primary->input_cost_per_mtok_cents + $outT * $primary->output_cost_per_mtok_cents) / max(1, $in + $outT);
                $tokensPerCredit = $perMillion > 0 ? (int) floor($value / $perMillion) : null;
            }

            $out[$key] = ['action' => $key, 'label' => $rate['label'], 'credits' => $rate['credits'], 'automation' => $rate['automation'],
                'feature' => $route?->feature_key,
                'model' => $primary !== null ? ['name' => $primary->display_name, 'provider' => $primary->provider->name,
                    'input_per_mtok_cents' => $primary->input_cost_per_mtok_cents, 'output_per_mtok_cents' => $primary->output_cost_per_mtok_cents,
                    'audio_per_minute_microcents' => $primary->audio_cost_per_minute_microcents] : null,
                'costliest_model' => $costliest, 'estimate' => $est,
                'uses_30d' => $stats['uses'] ?? 0, 'avg_tokens_in' => $stats['avg_in'] ?? null, 'avg_tokens_out' => $stats['avg_out'] ?? null,
                'avg_cost_microcents' => $stats['avg'] ?? null, 'p90_cost_microcents' => $stats['p90'] ?? null, 'estimate_microcents' => $estimate,
                'planning_microcents' => $planning, 'revenue_microcents' => $revenue, 'margin_pct' => $margin, 'suggested_credits' => $suggested,
                'cost_per_credit_microcents' => $planning !== null && $rate['credits'] > 0 ? (int) ceil($planning / $rate['credits']) : null,
                'tokens_per_credit' => $tokensPerCredit, 'below_floor' => $margin !== null && $margin < $margins['floor']];
        }

        return $this->actions = $out;
    }

    /**
     * Cost per credit for a typical customer: the real mix of credits used over the last 30 days across all
     * brokerages, priced at each action's planning cost. Before there is any use, the plain average of the actions.
     */
    public function typicalCostPerCredit(): int
    {
        $actions = array_filter($this->actions(), fn (array $a): bool => $a['cost_per_credit_microcents'] !== null);
        if ($actions === []) {
            return 0;
        }
        $used = DB::connection(Connections::cell())->table('credit_ledger')->where('entry_type', 'consume')->where('created_at', '>=', CarbonImmutable::now()->subDays(30))
            ->selectRaw('action_key, -sum(amount) as credits')->groupBy('action_key')->pluck('credits', 'action_key');
        $credits = 0;
        $cost = 0;
        foreach ($used as $action => $n) {
            if (isset($actions[$action])) {
                $credits += (int) $n;
                $cost += (int) $n * (int) $actions[$action]['cost_per_credit_microcents'];
            }
        }
        if ($credits > 0) {
            return (int) ceil($cost / $credits);
        }

        // No use yet: AI actions are what credits mostly buy, so average those (allowance overage only once seen).
        $ai = array_filter($actions, fn (array $a): bool => str_starts_with($a['action'], 'ai.'));
        $pool = $ai !== [] ? $ai : $actions;

        return (int) ceil(array_sum(array_column($pool, 'cost_per_credit_microcents')) / count($pool));
    }

    /** Whether any AI action can be priced yet (models with prices are routed). */
    public function aiPriced(): bool
    {
        foreach ($this->actions() as $a) {
            if (str_starts_with($a['action'], 'ai.') && $a['planning_microcents'] !== null) {
                return true;
            }
        }

        return false;
    }

    /** The costliest credit: every included credit spent on the action that costs most per credit. */
    public function worstCostPerCredit(): int
    {
        $costs = array_filter(array_column($this->actions(), 'cost_per_credit_microcents'));

        return $costs === [] ? 0 : (int) max($costs);
    }

    /** @return array<string, int> cost per unit by cost-list key */
    public function catalogCosts(): array
    {
        return DB::connection(Connections::control())->table('cost_catalog')->pluck('cost_microcents', 'key')->map(fn ($c): int => (int) $c)->all();
    }

    /**
     * What a package's included texts, call minutes and map views cost if all are used (map views priced as if past
     * the free tier on RightAlly's account, to stay on the safe side).
     *
     * @return array{texts: int, calls: int, maps: int, total: int}
     */
    public function allowanceCosts(Package $package): array
    {
        $catalog = $this->catalogCosts();
        $cost = fn (string $column): int => (int) $package->getAttribute($column) * ($catalog[self::ALLOWANCES[$column]] ?? 0);
        $out = ['texts' => $cost('included_text_segments'), 'calls' => $cost('included_call_minutes'), 'maps' => $cost('included_map_views')];

        return $out + ['total' => array_sum($out)];
    }

    /** Monthly costs per brokerage that credits don't cover (hosting and the like). */
    public function fixedMonthlyMicrocents(): int
    {
        return (int) DB::connection(Connections::control())->table('cost_catalog')->where('kind', 'monthly')->sum('cost_microcents');
    }

    /**
     * One package's margins: for a typical customer and in the worst case, after fixed monthly costs.
     *
     * @return array{revenue_microcents: int, included_credits: int, typical_cost_microcents: int, worst_cost_microcents: int, fixed_microcents: int,
     *     allowance_microcents: int, typical_margin_pct: int|null, worst_margin_pct: int|null, below_floor: bool, suggested_credits: int}
     */
    public function packagePlan(Package $package, ?int $includedCredits = null): array
    {
        $credits = $includedCredits ?? (int) $package->getAttribute('included_credits');
        $revenue = $package->monthly_cents * 1_000_000;
        $fixed = $this->fixedMonthlyMicrocents();
        $allowances = $this->allowanceCosts($package)['total'];
        $typical = $credits * $this->typicalCostPerCredit();
        $worst = $credits * $this->worstCostPerCredit();
        $margin = fn (int $cost): ?int => $revenue > 0 ? (int) floor(($revenue - $cost - $fixed - $allowances) * 100 / $revenue) : null;
        $typicalMargin = $margin($typical);

        return ['revenue_microcents' => $revenue, 'included_credits' => $credits, 'typical_cost_microcents' => $typical, 'worst_cost_microcents' => $worst,
            'fixed_microcents' => $fixed, 'allowance_microcents' => $allowances, 'typical_margin_pct' => $typicalMargin, 'worst_margin_pct' => $margin($worst),
            'below_floor' => $typicalMargin !== null && $typicalMargin < $this->rates->margins()['floor'],
            'suggested_credits' => $this->suggestedIncludedCredits($package)];
    }

    /**
     * Included credits a package can afford: its price × the usage budget, at the typical cost per credit,
     * rounded down to a tidy number.
     */
    public function suggestedIncludedCredits(Package $package): int
    {
        $perCredit = $this->typicalCostPerCredit();
        if ($perCredit <= 0 || $package->monthly_cents <= 0) {
            return 0;
        }
        $raw = (int) floor($package->monthly_cents * 1_000_000 * $this->rates->margins()['usage_budget'] / 100 / $perCredit);
        $step = $raw >= 10_000 ? 1000 : ($raw >= 1000 ? 100 : 10);

        return max(0, intdiv($raw, $step) * $step);
    }

    /**
     * Actions whose real average cost over the last few days leaves less than HQ's minimum margin (a provider raised
     * prices, traffic moved to a costlier fallback model, or uses got bigger).
     *
     * @return list<array{action: string, label: string, credits: int, uses: int, avg_cost_microcents: int, margin_pct: int, suggested_credits: int}>
     */
    public function marginAlerts(int $days = 7): array
    {
        $value = $this->rates->creditValueMicrocents();
        $margins = $this->rates->margins();
        $routes = AiFeatureRoute::query()->get()->keyBy('feature_key');
        $rates = $this->rates->all();
        $alerts = [];
        foreach ($this->realCosts($days) as $feature => $stats) {
            $action = $routes->get($feature)?->credit_action_key;
            if ($action === null || ! isset($rates[$action])) {
                continue;
            }
            $revenue = $rates[$action]['credits'] * $value;
            $margin = $revenue > 0 ? (int) floor(($revenue - $stats['avg']) * 100 / $revenue) : -100;
            if ($margin < $margins['floor']) {
                $alerts[] = ['action' => $action, 'label' => $rates[$action]['label'], 'credits' => $rates[$action]['credits'], 'uses' => $stats['uses'],
                    'avg_cost_microcents' => $stats['avg'], 'margin_pct' => $margin,
                    'suggested_credits' => max(1, (int) ceil($stats['p90'] / max(1, $value * (100 - $margins['target']) / 100)))];
            }
        }

        return $alerts;
    }

    /**
     * Real cost per feature over the last 30 days: average and 90th percentile per successful use, with failed and
     * retried attempts' cost spread over the successes.
     *
     * @return array<string, array{uses: int, avg: int, p90: int, avg_in: int, avg_out: int}>
     */
    private function realCosts(int $days = 30): array
    {
        $rows = DB::connection(Connections::cell())->table('ai_requests')->where('created_at', '>=', CarbonImmutable::now()->subDays($days))
            ->orderByDesc('id')->limit(200_000)->get(['feature_key', 'status', 'cost_microcents', 'tokens_in', 'tokens_out']);
        $by = [];
        foreach ($rows as $r) {
            $f = (string) $r->feature_key;
            $by[$f] ??= ['ok' => [], 'all' => 0, 'in' => 0, 'out' => 0];
            $by[$f]['all'] += (int) $r->cost_microcents;
            if ($r->status === 'ok') {
                $by[$f]['ok'][] = (int) $r->cost_microcents;
                $by[$f]['in'] += (int) $r->tokens_in;
                $by[$f]['out'] += (int) $r->tokens_out;
            }
        }
        $out = [];
        foreach ($by as $feature => $d) {
            $uses = count($d['ok']);
            if ($uses === 0) {
                continue;
            }
            sort($d['ok']);
            $okTotal = max(1, array_sum($d['ok']));
            $overhead = $d['all'] / $okTotal; // ≥ 1: failed attempts and retries
            $p90 = $d['ok'][(int) min($uses - 1, (int) ceil($uses * 0.9) - 1)];
            $out[$feature] = ['uses' => $uses, 'avg' => (int) round($d['all'] / $uses), 'p90' => (int) ceil($p90 * $overhead),
                'avg_in' => (int) round($d['in'] / $uses), 'avg_out' => (int) round($d['out'] / $uses)];
        }

        return $out;
    }
}
