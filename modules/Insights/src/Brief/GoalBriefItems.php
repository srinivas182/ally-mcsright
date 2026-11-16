<?php

declare(strict_types=1);

namespace Modules\Insights\Brief;

use App\Models\User;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\TenantContext;
use Modules\Insights\Planner;

/**
 * The goal planner in the daily brief (SO1): how many conversations today keep the yearly closings goal on track, or
 * (for solo agents) a nudge to set one. Shown to people who sell: agents, and the owner of a solo agent's RightAlly.
 */
final class GoalBriefItems
{
    public static function register(DailyBriefs $briefs): void
    {
        $briefs->add('goal', self::items(...));
    }

    /** @return list<array<string, mixed>> */
    public static function items(User $user): array
    {
        if (! in_array($user->kind->value, ['agent', 'owner'], true)) {
            return [];
        }
        $daily = app(Planner::class)->daily($user);
        if ($daily === null) {
            // The nudge to set a goal is for solo agents; brokerage agents get it from their broker's goals (K1).
            return app(TenantContext::class)->current()->type === TenantType::Solo
                ? [['key' => 'goal-set', 'title' => 'Set your goal for this year', 'why' => 'Tell RightAlly how many closings you want; it works out who to call each day.',
                    'action' => 'Set my goal', 'url' => '/today#goal', 'urgency' => 1, 'stakes_cents' => null]]
                : [];
        }
        $left = max(0, $daily['per_day'] - $daily['done_today']);
        if ($left === 0) {
            return [];
        }

        return [['key' => 'goal-today', 'title' => "{$left} more conversation".($left === 1 ? '' : 's').' today keeps your goal on track',
            'why' => "Goal: {$daily['closings']} closings this year, about {$daily['per_day']} conversations a working day. Done today: {$daily['done_today']}.",
            'action' => 'Who to call', 'url' => '/today', 'urgency' => 2, 'stakes_cents' => null]];
    }
}
