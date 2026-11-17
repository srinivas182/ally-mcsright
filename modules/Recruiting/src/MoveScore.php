<?php

declare(strict_types=1);

namespace Modules\Recruiting;

use Carbon\CarbonImmutable;
use Modules\Recruiting\Models\MlsAgent;

/**
 * Likely to move (RC2, specs/grow/17): a 0–100 score for each agent with the plain reasons behind it, from what the
 * MLS shows. No black box: every point comes from a reason the recruiter can read and check.
 *
 * - Production down 30% or more on the year before (3+ sides before): 35
 * - Agents leaving their office: 2 or more in the last 90 days: 25 (4 or more: 35)
 * - About a year at their office (11–13 months since they moved): 20
 * - Growing fast (see AgentDatabase::jumped): 20, as they may be outgrowing their split
 */
final class MoveScore
{
    /** Score every agent in one MLS. */
    public function scoreAll(int $source, ?CarbonImmutable $today = null): void
    {
        $today ??= CarbonImmutable::today();
        $leaving = MlsAgent::query()->where('mls_source_id', $source)->whereNotNull('previous_office_mls_id')
            ->where('office_changed_on', '>=', $today->subDays(90)->toDateString())->get(['previous_office_mls_id'])
            ->countBy(fn (MlsAgent $a): string => (string) $a->previous_office_mls_id)->all();
        MlsAgent::query()->where('mls_source_id', $source)->lazyById(500)->each(function (MlsAgent $a) use ($leaving, $today): void {
            [$score, $reasons] = $this->score($a, (int) ($leaving[(string) $a->office_mls_id] ?? 0), $today);
            if ($score !== $a->move_score || $reasons !== ($a->move_reasons ?? [])) {
                $a->forceFill(['move_score' => $score, 'move_reasons' => $reasons])->save();
            }
        });
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    public function score(MlsAgent $a, int $leftOffice, CarbonImmutable $today): array
    {
        $score = 0;
        $reasons = [];
        if ($a->sides_before >= 3 && $a->sides * 10 <= $a->sides_before * 7) {
            $score += 35;
            $reasons[] = "Closed {$a->sides} sides, down ".(int) round(100 * ($a->sides_before - $a->sides) / $a->sides_before).'% on the year before.';
        }
        if ($leftOffice >= 2 && $a->office_mls_id !== null) {
            $score += $leftOffice >= 4 ? 35 : 25;
            $reasons[] = "{$leftOffice} agents left ".($a->office_name ?? 'their office').' in the last 90 days.';
        }
        if ($a->office_changed_on !== null && $a->office_changed_on->betweenIncluded($today->subMonths(13), $today->subMonths(11))) {
            $score += 20;
            $reasons[] = 'About a year at '.($a->office_name ?? 'their office').': often when agents review their plan.';
        }
        if (app(AgentDatabase::class)->jumped($a)) {
            $score += 20;
            $reasons[] = "Growing fast ({$a->sides} sides, up from {$a->sides_before}): may be outgrowing their split.";
        }

        return [min(100, $score), $reasons];
    }
}
