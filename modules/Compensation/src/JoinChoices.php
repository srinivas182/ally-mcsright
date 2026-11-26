<?php

declare(strict_types=1);

namespace Modules\Compensation;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanStatus;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Enums\SplitMode;
use Modules\Compensation\Models\Plan;
use Modules\Compensation\Rules\CommissionRules;
use Throwable;

/**
 * AG4: the commission plans a new agent can pick when they join online (the owner offers them under Commissions ›
 * Plans), each told in plain words, and placing the agent on the one they picked. Nothing offered: the agent joins
 * on the default plan, as before.
 */
final class JoinChoices
{
    public function __construct(private readonly ManagePlans $plans) {}

    /**
     * @return list<array{ulid: string, name: string, description: string|null, lines: list<string>, default: bool}>
     */
    public function offered(): array
    {
        $out = [];
        foreach ($this->query()->with('versions')->orderByDesc('is_default')->orderBy('name')->get() as $plan) {
            $version = $plan->latestPublished();
            if ($version === null) {
                continue;
            }
            try {
                $lines = self::lines($version->commissionRules());
            } catch (Throwable) {
                continue; // rules that no longer read are never offered
            }
            $out[] = ['ulid' => $plan->ulid, 'name' => $plan->name, 'description' => $plan->description, 'lines' => $lines, 'default' => $plan->is_default];
        }

        return $out;
    }

    /**
     * AG6: every published, active commission plan an owner can invite an agent on (not only those offered at
     * joining), the default first.
     *
     * @return list<array{ulid: string, name: string, default: bool}>
     */
    public function invitable(): array
    {
        return array_values(Plan::query()->where('type', PlanType::Commission)->where('status', PlanStatus::Active)->orderByDesc('is_default')->orderBy('name')->get()
            ->filter(fn (Plan $p): bool => $p->latestPublished() !== null)
            ->map(fn (Plan $p): array => ['ulid' => $p->ulid, 'name' => $p->name, 'default' => $p->is_default])->all());
    }

    /** One of the offered plans, by its public id; null when it is not (or no longer) offered. */
    public function find(string $ulid): ?Plan
    {
        $plan = $this->query()->where('ulid', $ulid)->first();

        return $plan instanceof Plan && $plan->latestPublished() !== null ? $plan : null;
    }

    /**
     * Puts the new agent on the plan they picked, from today. A plan archived since then leaves them on the default
     * plan (logged), so joining never fails over it.
     */
    public function place(int $userId, int $planId): ?string
    {
        $plan = Plan::query()->find($planId);
        if (! $plan instanceof Plan || $plan->status !== PlanStatus::Active) {
            Log::warning('AG4: the plan picked at joining is no longer active; the agent is on the default plan.', ['user' => $userId, 'plan' => $planId]);

            return null;
        }
        try {
            $this->plans->assign($userId, $plan, CarbonImmutable::today());
        } catch (Throwable $e) {
            Log::warning('AG4: could not place the new agent on the plan they picked.', ['user' => $userId, 'plan' => $planId, 'error' => $e->getMessage()]);

            return null;
        }

        return $plan->name;
    }

    /**
     * What a plan means for the agent, in a few plain sentences (split, fee, cap, after the cap, revenue share).
     *
     * @return list<string>
     */
    public static function lines(CommissionRules $rules): array
    {
        $split = $rules->split;
        $lines = [];
        if ($split->mode === SplitMode::Percentage) {
            $agent = (string) $split->agentPercent;
            $lines[] = 'You keep '.self::pct($agent).' of each commission; the brokerage keeps '.self::pct((string) BigDecimal::of(100)->minus($agent)).'.';
        } elseif ($split->mode === SplitMode::FlatFee) {
            $bands = $split->bands;
            if (count($bands) === 1) {
                $lines[] = 'You keep 100% of each commission, less a flat '.self::money($bands[0]['fee_cents']).' per deal.';
            } else {
                $parts = [];
                foreach ($bands as $b) {
                    $parts[] = self::money($b['fee_cents']).($b['up_to_price_cents'] !== null ? ' on homes up to '.self::money($b['up_to_price_cents']) : ' above that');
                }
                $lines[] = 'You keep 100% of each commission, less a flat fee per deal: '.implode(', ', $parts).'.';
            }
        } else {
            $tiers = $split->tiers;
            $first = (string) ($tiers[0]['agent_percent'] ?? '0');
            $last = (string) ($tiers[count($tiers) - 1]['agent_percent'] ?? $first);
            $lines[] = BigDecimal::of($first)->isEqualTo($last) ? 'You keep '.self::pct($first).' of each commission.'
                : 'You keep '.self::pct($first).' of each commission at first, rising to '.self::pct($last).' as you close more in the year.';
        }

        $cap = $rules->cap;
        if ($cap !== null) {
            $lines[] = 'The brokerage\'s share stops at '.self::money($cap->amountCents).' a year (your cap). '
                .($cap->anchor === 'anniversary' ? 'Your year starts on the day you join.' : 'The year starts each '.self::monthDay((string) $cap->anchorMonthDay).'.');
            if ($cap->postCapCalc === 'flat' && $cap->postCapAmount !== null) {
                $lines[] = 'After you cap, you pay '.self::money((int) $cap->postCapAmount).' a deal for the rest of that year.';
            } elseif ($cap->postCapCalc === 'percent' && $cap->postCapAmount !== null) {
                $lines[] = 'After you cap, you pay '.self::pct((string) $cap->postCapAmount).' a deal for the rest of that year.';
            } else {
                $lines[] = 'After you cap, you keep 100% for the rest of that year.';
            }
        }

        $levels = $rules->revenueShare !== null ? count($rules->revenueShare->levels) : 0;
        if ($levels > 0) {
            $lines[] = 'Earn revenue share on the agents you bring in, '.$levels.' '.($levels === 1 ? 'level' : 'levels').' deep.';
        }

        return $lines;
    }

    /** @return Builder<Plan> */
    private function query(): Builder
    {
        return Plan::query()->where('type', PlanType::Commission)->where('status', PlanStatus::Active)->where('offered_on_join', true);
    }

    private static function money(int $cents): string
    {
        return '$'.number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);
    }

    /** A percentage as text, without trailing zeros ("90%", "12.5%"); kept exact (no floats in money code). */
    private static function pct(string $p): string
    {
        $text = (string) BigDecimal::of($p)->toScale(2, RoundingMode::HalfUp);

        return (str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text).'%';
    }

    private static function monthDay(string $md): string
    {
        $d = CarbonImmutable::createFromFormat('m-d', $md);

        return $d !== null ? $d->format('F j') : 'January 1';
    }
}
