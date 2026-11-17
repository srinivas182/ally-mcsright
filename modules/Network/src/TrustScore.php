<?php

declare(strict_types=1);

namespace Modules\Network;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use stdClass;

/**
 * The Referral Trust Score (AR2, specs/network/02): 0 to 100, from what an agent actually did with the referrals
 * sent to them in the last two years. Shown only after 3 answered referrals, with every part explained.
 *
 * | Part | Weight | What counts |
 * | Pays the fee | 35 | closed referrals where the referring side confirmed the fee within 45 days (an open dispute counts as not paid) |
 * | Answers quickly | 20 | referrals answered (accepted or declined) within 24 hours; unanswered ones count against |
 * | Keeps you updated | 20 | accepted referrals with an update in the last 30 days, or finished |
 * | Closes | 15 | accepted referrals that closed, of those that finished |
 * | Rated by referring agents | 10 | average star rating |
 *
 * A part with nothing to measure yet is left out and the others are scaled up. Nothing else (brokerage size,
 * plan, payments to RightAlly) affects the score.
 */
final class TrustScore
{
    public const MIN_REFERRALS = 3;

    public const WEIGHTS = ['pays' => 35, 'answers' => 20, 'updates' => 20, 'closes' => 15, 'rating' => 10];

    public const PAY_DAYS = 45;

    /**
     * Works out and saves a profile's score.
     *
     * @return array{score: int|null, count: int, parts: array<string, array{score: int, label: string, detail: string}>}
     */
    public function refresh(int $profileId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $rows = DB::connection(Connections::control())->table('network_referrals')->where('to_profile_id', $profileId)
            ->where('created_at', '>=', $now->subYears(2))->get();
        $out = self::compute($rows, $now);
        DB::connection(Connections::control())->table('network_profiles')->where('id', $profileId)->update(['trust_score' => $out['score'], 'trust_parts' => json_encode($out['parts']),
            'trust_count' => $out['count'], 'trust_at' => $now]);

        return $out;
    }

    /** Daily: every profile. */
    public function refreshAll(): int
    {
        $n = 0;
        foreach (DB::connection(Connections::control())->table('network_profiles')->pluck('id') as $id) {
            $this->refresh((int) $id);
            $n++;
        }

        return $n;
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return array{score: int|null, count: int, parts: array<string, array{score: int, label: string, detail: string}>}
     */
    public static function compute(Collection $rows, CarbonImmutable $now): array
    {
        $at = static fn (mixed $v): ?CarbonImmutable => $v !== null ? CarbonImmutable::parse((string) $v) : null;
        $answered = $rows->whereNotIn('status', ['sent']);
        $count = $answered->where('status', '!=', 'expired')->count();
        $parts = [];

        $due = $answered->filter(fn ($r): bool => $r->status !== 'sent');
        if ($due->isNotEmpty()) {
            $fast = $due->filter(fn ($r): bool => $r->responded_at !== null && $at($r->responded_at)?->lessThanOrEqualTo($at($r->created_at)?->addDay() ?? $now) === true)->count();
            $parts['answers'] = self::part($fast, $due->count(), 'Answers quickly', "Answered {$fast} of {$due->count()} referrals within 24 hours.");
        }
        $accepted = $rows->whereIn('status', ['accepted', 'under_contract', 'closed', 'fell_through']);
        if ($accepted->isNotEmpty()) {
            $kept = $accepted->filter(fn ($r): bool => in_array($r->status, ['closed', 'fell_through'], true)
                || ($at($r->last_update_at ?? $r->responded_at)?->greaterThanOrEqualTo($now->subDays(30)) ?? false))->count();
            $parts['updates'] = self::part($kept, $accepted->count(), 'Keeps you updated', "Kept the referring agent up to date on {$kept} of {$accepted->count()} accepted referrals.");
        }
        $finished = $rows->whereIn('status', ['closed', 'fell_through']);
        if ($finished->isNotEmpty()) {
            $closed = $finished->where('status', 'closed')->count();
            $parts['closes'] = self::part($closed, $finished->count(), 'Closes', "Closed {$closed} of {$finished->count()} finished referrals.");
        }
        $owed = $rows->where('status', 'closed')->filter(fn ($r): bool => $r->fee_paid_at !== null || $r->disputed_at !== null
            || ($at($r->closed_on)?->addDays(self::PAY_DAYS)->isBefore($now) ?? false));
        if ($owed->isNotEmpty()) {
            $paid = $owed->filter(fn ($r): bool => $r->fee_paid_at !== null && ($r->disputed_at === null || $r->dispute_closed_at !== null)
                && ($at($r->fee_paid_at)?->lessThanOrEqualTo(($at($r->closed_on) ?? $now)->addDays(self::PAY_DAYS)->endOfDay()) ?? false))->count();
            $parts['pays'] = self::part($paid, $owed->count(), 'Pays the fee', "Paid {$paid} of {$owed->count()} referral fees within ".self::PAY_DAYS.' days of closing.');
        }
        $rated = $rows->whereNotNull('rating');
        if ($rated->isNotEmpty()) {
            $avg = round((float) $rated->avg('rating'), 1);
            $parts['rating'] = ['score' => (int) round(($avg - 1) / 4 * 100), 'label' => 'Rated by referring agents', 'detail' => "{$avg} stars from {$rated->count()} referring agents."];
        }
        $weight = array_sum(array_intersect_key(self::WEIGHTS, $parts));
        $score = $count >= self::MIN_REFERRALS && $weight > 0
            ? (int) round(array_sum(array_map(fn (string $k, array $p): float => $p['score'] * self::WEIGHTS[$k], array_keys($parts), $parts)) / $weight)
            : null;

        return ['score' => $score, 'count' => $count, 'parts' => $parts];
    }

    /**
     * Plain tips for the agent's own profile.
     *
     * @param  array<string, array{score: int, label: string, detail: string}>  $parts
     * @return list<string>
     */
    public static function tips(array $parts, int $count): array
    {
        $tips = [];
        if ($count < self::MIN_REFERRALS) {
            $tips[] = 'Your score shows once you have answered '.self::MIN_REFERRALS.' referrals.';
        }
        if (($parts['answers']['score'] ?? 100) < 80) {
            $tips[] = 'Answer new referrals within 24 hours, even if it\'s a no.';
        }
        if (($parts['updates']['score'] ?? 100) < 80) {
            $tips[] = 'Send an update on each accepted referral at least once a month.';
        }
        if (($parts['pays']['score'] ?? 100) < 100) {
            $tips[] = 'Ask your brokerage to send referral fees within '.self::PAY_DAYS.' days of closing.';
        }

        return $tips;
    }

    /** @return array{score: int, label: string, detail: string} */
    private static function part(int $good, int $of, string $label, string $detail): array
    {
        return ['score' => (int) round(100 * $good / max(1, $of)), 'label' => $label, 'detail' => $detail];
    }
}
