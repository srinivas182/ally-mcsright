<?php

declare(strict_types=1);

namespace Modules\Movewise\Matching;

use Carbon\CarbonImmutable;

/**
 * How long the chosen agent has to accept (specs/movewise/00 §4.5): 5 minutes inside their working hours; outside
 * them, until 30 minutes after their next start. Both are HQ settings (movewise.accept_minutes, .after_hours_minutes).
 */
final class AcceptClock
{
    /** @param  array{tz: string, days: array<int|string, array{0: string, 1: string}>}  $hours */
    public static function deadline(array $hours, CarbonImmutable $now): CarbonImmutable
    {
        $local = $now->setTimezone($hours['tz']);
        $quick = (int) config('movewise.accept_minutes', 5);
        $grace = (int) config('movewise.after_hours_minutes', 30);
        $today = $hours['days'][$local->dayOfWeek] ?? $hours['days'][(string) $local->dayOfWeek] ?? null;
        if ($today !== null) {
            $start = $local->setTimeFromTimeString($today[0]);
            $end = $local->setTimeFromTimeString($today[1]);
            if ($local->gte($start) && $local->lt($end)) {
                return $now->addMinutes($quick);
            }
            if ($local->lt($start)) {
                return $start->addMinutes($grace)->utc();
            }
        }
        for ($d = 1; $d <= 7; $d++) {
            $day = $local->addDays($d)->startOfDay();
            $span = $hours['days'][$day->dayOfWeek] ?? $hours['days'][(string) $day->dayOfWeek] ?? null;
            if ($span !== null) {
                return $day->setTimeFromTimeString($span[0])->addMinutes($grace)->utc();
            }
        }

        return $now->addMinutes($quick);
    }
}
