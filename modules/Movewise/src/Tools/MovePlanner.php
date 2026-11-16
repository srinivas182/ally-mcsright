<?php

declare(strict_types=1);

namespace Modules\Movewise\Tools;

use Carbon\CarbonImmutable;
use Modules\Movewise\I18n;

/**
 * The move planner (specs/movewise/02): a checklist dated back from the move day, so nothing is forgotten (utilities,
 * movers, address changes). Signed-in people keep their ticks.
 */
final class MovePlanner
{
    /** weeks before the move (negative: after) => tasks */
    public const TASKS = [
        8 => ['budget' => 'Set your moving budget', 'movers' => 'Get three quotes from licensed movers', 'declutter' => 'Sort what to sell, give away or keep'],
        4 => ['book_movers' => 'Book your movers or truck', 'school' => 'Ask schools for records, if you need them', 'insurance' => 'Set up home insurance for the new place'],
        2 => ['utilities_new' => 'Set up electricity, water, gas and internet at the new home', 'utilities_old' => 'Book shut-off at the old home for the day after you leave',
            'usps' => 'Change your address with USPS (usps.com/move)', 'pack' => 'Start packing rooms you use least'],
        1 => ['banks' => 'Update your address with banks, work and subscriptions', 'essentials' => 'Pack an essentials box: papers, chargers, medicines, a change of clothes'],
        0 => ['walkthrough' => 'Final walk-through and meter readings', 'keys' => 'Hand over or collect keys'],
        -1 => ['license' => 'Update your driver\'s licence and car registration (Texas: within 30 days)', 'voter' => 'Update your voter registration', 'locks' => 'Change the locks'],
    ];

    /**
     * @param  list<string>  $done
     * @return list<array{weeks: int, when: string, date: string|null, tasks: list<array{key: string, label: string, done: bool}>}>
     */
    public static function plan(?string $moveDate, array $done): array
    {
        $day = $moveDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $moveDate) === 1 ? CarbonImmutable::parse($moveDate) : null;
        $out = [];
        foreach (self::TASKS as $weeks => $tasks) {
            $when = match (true) {
                $weeks > 0 => I18n::t($weeks === 1 ? 'plan.week_before' : 'tools.weeks_before', ['n' => $weeks]),
                $weeks === 0 => I18n::t('tools.week_of'),
                default => I18n::t('tools.after'),
            };
            $out[] = ['weeks' => $weeks, 'when' => $when, 'date' => $day?->subWeeks(max(0, $weeks))->addWeeks($weeks < 0 ? 1 : 0)->toDateString(),
                'tasks' => array_map(fn (string $k, string $label): array => ['key' => $k, 'label' => I18n::t('plan.'.$k) === 'plan.'.$k ? $label : I18n::t('plan.'.$k), 'done' => in_array($k, $done, true)], array_keys($tasks), array_values($tasks))];
        }

        return $out;
    }
}
