<?php

declare(strict_types=1);

namespace Modules\Engage;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Task;
use Modules\Learn\Models\Enrolment;
use Modules\Learn\Models\RolePlaySession;
use Modules\Recruiting\Retention;
use Modules\Transactions\Models\Deal;

/**
 * Agent activity and engagement for the broker (EN1, D21, specs/engage/01): for each active agent, when they last
 * used RightAlly, their outreach and tasks this week, deals opened this month, learning and practice, and the
 * "keeping agents" signals (Recruiting). Counts only: the broker never sees anyone's messages or practice here.
 */
final class Engagement
{
    public function __construct(private readonly Retention $retention) {}

    /**
     * @return list<array{id: int, name: string, last_active: string|null, outreach_7d: int, tasks_7d: int, deals_30d: int, courses_90d: int, practice_30d: int, risk: int, reasons: list<string>}>
     */
    public function forBroker(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $out = [];
        foreach (User::query()->where('kind', UserKind::Agent->value)->where('status', UserStatus::Active->value)->orderBy('first_name')->get() as $a) {
            [$risk, $reasons] = $this->retention->score($a, $today);
            $active = $a->last_active_at ?? $a->last_login_at;
            $out[] = ['id' => $a->id, 'name' => (string) $a->name, 'last_active' => $active !== null ? CarbonImmutable::parse((string) $active)->toDateString() : null,
                'outreach_7d' => Activity::query()->where('user_id', $a->id)->where('direction', 'out')->where('occurred_at', '>=', $today->subDays(7))->count(),
                'tasks_7d' => Task::query()->where('completed_by', $a->id)->where('completed_at', '>=', $today->subDays(7))->count(),
                'deals_30d' => Deal::query()->where('agent_user_id', $a->id)->where('created_at', '>=', $today->subDays(30))->count(),
                'courses_90d' => Enrolment::query()->where('user_id', $a->id)->where('completed_at', '>=', $today->subDays(90))->count(),
                'practice_30d' => RolePlaySession::query()->where('user_id', $a->id)->whereNotNull('finished_at')->where('finished_at', '>=', $today->subDays(30))->count(),
                'risk' => $risk, 'reasons' => $reasons];
        }
        usort($out, fn (array $x, array $y): int => [$y['risk'], $x['name']] <=> [$x['risk'], $y['name']]);

        return $out;
    }
}
