<?php

declare(strict_types=1);

namespace Modules\Movewise\Outcomes;

use Carbon\CarbonImmutable;
use Modules\Core\Audit\AuditLogger;
use Modules\Movewise\Models\Agent;
use Modules\Movewise\Models\Connection;

/**
 * The published scorecard and tiers (specs/movewise/00 §6.3, 06), recalculated weekly from the last 90 days.
 *
 * - New: until 5 introductions have been rated (small caps, shown less).
 * - Preferred: median accept in 10 minutes or less, status kept up to date on at least 90%, a rating of 4.5 or
 *   more, and stops or changes on no more than 10%.
 * - Standard: everyone else.
 *
 * The agent sees every measure and exactly which ones decided the tier.
 */
final class Scorecards
{
    public const DAYS = 90;

    public const RATED_FOR_TIER = 5;

    /** @return array<string, mixed> */
    public function for(Agent $agent, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $all = Connection::query()->where('agent_id', $agent->id)->where('offered_at', '>=', $now->subDays(self::DAYS))->get();
        $accepted = $all->filter(fn (Connection $c): bool => $c->accepted_at !== null);
        $minutes = $accepted->map(fn (Connection $c): float => $c->offered_at->diffInSeconds($c->accepted_at, true) / 60)->sort()->values()->all();
        $median = $minutes === [] ? null : round((float) $minutes[intdiv(count($minutes), 2)], 1);
        $due = $accepted->filter(fn (Connection $c): bool => $c->accepted_at !== null && $c->accepted_at->lt($now->subDays(Outcomes::REMIND_DAYS))
            && ! in_array($c->status, ['withdrawn'], true));
        $current = $due->filter(fn (Connection $c): bool => in_array($c->stage, ['closed', 'lost'], true) || in_array($c->status, ['stopped', 'changed'], true)
            || ($c->stage_at ?? $c->accepted_at)?->gte($now->subDays(Outcomes::REMIND_DAYS)));
        $follow = $due->count() > 0 ? round($current->count() / $due->count(), 2) : null;
        $rated = $all->filter(fn (Connection $c): bool => $c->rating !== null);
        $rating = $rated->count() > 0 ? round((float) $rated->avg('rating'), 2) : null;
        $per100 = fn (int $n): ?float => $accepted->count() > 0 ? round(100 * $n / $accepted->count(), 1) : null;
        $order = array_keys(Outcomes::STAGES);
        $reached = fn (string $s): int => $accepted->filter(fn (Connection $c): bool => $c->stage !== null && $c->stage !== 'lost'
            && array_search($c->stage, $order, true) >= array_search($s, $order, true))->count();
        $stops = $accepted->count() > 0 ? round($accepted->filter(fn (Connection $c): bool => in_array($c->status, ['stopped', 'changed', 'withdrawn'], true))->count() / $accepted->count(), 2) : null;

        $why = [];
        if ($rated->count() < self::RATED_FOR_TIER) {
            $tier = 'new';
            $why[] = $rated->count().' of '.self::RATED_FOR_TIER.' rated introductions needed to leave New';
        } else {
            $checks = ['Median time to accept 10 minutes or less' => $median !== null && $median <= 10, 'Status up to date on 90% or more' => $follow === null || $follow >= 0.9,
                'Rating 4.5 or more' => $rating !== null && $rating >= 4.5, 'Stops and changes 10% or less' => $stops === null || $stops <= 0.1];
            $tier = ! in_array(false, $checks, true) ? 'preferred' : 'standard';
            foreach ($checks as $label => $ok) {
                $why[] = ($ok ? 'Met: ' : 'Not met: ').$label;
            }
        }

        return ['tier' => $tier, 'why' => $why, 'introductions' => $all->count(), 'accepted' => $accepted->count(), 'median_accept_minutes' => $median,
            'follow_through' => $follow, 'rating' => $rating, 'ratings' => $rated->count(), 'appointments_per_100' => $per100($reached('met')),
            'agreements_per_100' => $per100($reached('agreement')), 'closings_per_100' => $per100($reached('closed')), 'stops_share' => $stops,
            'since' => $now->subDays(self::DAYS)->toDateString()];
    }

    /** Weekly for every agent in the program. */
    public function recalculate(): int
    {
        $n = 0;
        foreach (Agent::query()->where('status', '!=', 'removed')->orderBy('id')->get() as $agent) {
            $card = $this->for($agent);
            if ($card['tier'] !== $agent->tier) {
                app(AuditLogger::class)->record('movewise.agent.tier', $agent, metadata: ['from' => $agent->tier, 'to' => $card['tier']], tenantId: $agent->tenant_id);
            }
            $agent->forceFill(['tier' => $card['tier'], 'scorecard' => $card, 'scored_at' => CarbonImmutable::now()])->save();
            $n++;
        }

        return $n;
    }
}
