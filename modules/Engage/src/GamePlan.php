<?php

declare(strict_types=1);

namespace Modules\Engage;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Models\Task;
use Modules\Crm\TodaysTen;
use Modules\Insights\Planner;
use Modules\Learn\Models\RolePlaySession;
use Modules\Learn\RolePlay;

/**
 * The daily game plan and AI coach (EN1, D18, specs/engage/01). One page that says what today is for:
 *  - the calls the goal planner asks for and who to call (Today's 10), tasks due, and the ranked daily brief;
 *  - today's five-minute practice drill;
 *  - "Coach me on today": a short plan in time blocks and one thing to focus on. The AI sees only counts and kinds
 *    of work (never names, numbers or notes), costs 1 credit, and is kept for the day.
 */
final class GamePlan
{
    public const FEATURE = 'engage.coach';

    private const SCHEMA = ['type' => 'object', 'required' => ['focus', 'blocks', 'encouragement'], 'properties' => [
        'focus' => ['type' => 'string'], 'encouragement' => ['type' => 'string'],
        'blocks' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['when', 'what'], 'properties' => ['when' => ['type' => 'string'], 'what' => ['type' => 'string']]]],
    ]];

    public function __construct(private readonly AiGateway $gateway, private readonly TenantContext $tenants) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $goal = app(Planner::class)->daily($user);
        $calls = count(app(TodaysTen::class)->for($user, $goal !== null ? max(10, min(25, $goal['per_day'])) : 10));
        $tasks = Task::query()->where('assigned_to', $user->id)->whereNull('completed_at')->where('due_at', '<=', CarbonImmutable::now()->endOfDay());
        $drill = RolePlay::todays($user);

        return [
            'goal' => $goal,
            'calls' => $calls,
            'tasks_due' => (clone $tasks)->count(),
            'tasks_late' => (clone $tasks)->where('due_at', '<', CarbonImmutable::now()->startOfDay())->count(),
            'brief' => array_map(fn (array $i): array => array_intersect_key($i, array_flip(['key', 'title', 'why', 'action', 'url', 'urgency'])),
                array_values(array_filter(app(DailyBriefs::class)->for($user, 8), fn (array $i): bool => $i['source'] !== 'engage'))),
            'drill' => ['title' => RolePlay::SCENARIOS[$drill][0], 'done' => RolePlaySession::query()->where('user_id', $user->id)->where('kind', 'daily')
                ->whereNotNull('finished_at')->where('finished_at', '>=', CarbonImmutable::today())->exists(), 'streak' => RolePlay::streak($user)],
            'coach' => $this->cached($user),
        ];
    }

    /** @return array{focus: string, blocks: list<array{when: string, what: string}>, encouragement: string} */
    public function coach(User $user): array
    {
        $cached = $this->cached($user);
        if ($cached !== null) {
            return $cached;
        }
        $plan = $this->for($user);
        $kinds = array_count_values(array_map(fn (array $i): string => (int) $i['urgency'] === 3 ? 'urgent' : ((int) $i['urgency'] === 2 ? 'this_week' : 'good_to_know'), $plan['brief']));
        $facts = ['calls_to_make' => $plan['calls'], 'conversation_goal_today' => $plan['goal']['per_day'] ?? null, 'conversations_done_today' => $plan['goal']['done_today'] ?? 0,
            'tasks_due_today' => $plan['tasks_due'], 'tasks_late' => $plan['tasks_late'], 'brief_items' => $kinds, 'practice_drill_done' => $plan['drill']['done'],
            'practice_streak_days' => $plan['drill']['streak'], 'weekday' => CarbonImmutable::now($user->timezone ?: 'America/New_York')->format('l')];
        $result = $this->gateway->run(new AiTask(self::FEATURE, 'engage.coach', 'coach:'.$user->id.':'.CarbonImmutable::today()->toDateString(),
            inputs: ['facts' => (string) json_encode($facts)], outputSchema: self::SCHEMA, userId: $user->id));
        $json = (array) $result->json;
        $note = ['focus' => mb_substr(trim((string) ($json['focus'] ?? '')), 0, 300), 'encouragement' => mb_substr(trim((string) ($json['encouragement'] ?? '')), 0, 300),
            'blocks' => array_values(array_map(fn ($b): array => ['when' => mb_substr(trim((string) ($b['when'] ?? '')), 0, 40), 'what' => mb_substr(trim((string) ($b['what'] ?? '')), 0, 200)],
                array_slice(array_filter((array) ($json['blocks'] ?? []), fn ($b): bool => is_array($b) && trim((string) ($b['what'] ?? '')) !== ''), 0, 5)))];
        DB::connection(Connections::cell())->table('engage_coach_notes')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'user_id' => $user->id,
            'day' => CarbonImmutable::today()->toDateString()], ['note' => Crypt::encryptString((string) json_encode($note)), 'created_at' => now(), 'updated_at' => now()]);

        return $note;
    }

    /** @return array{focus: string, blocks: list<array{when: string, what: string}>, encouragement: string}|null */
    private function cached(User $user): ?array
    {
        $row = DB::connection(Connections::cell())->table('engage_coach_notes')->where('tenant_id', $this->tenants->id())->where('user_id', $user->id)
            ->where('day', CarbonImmutable::today()->toDateString())->value('note');
        if (! is_string($row)) {
            return null;
        }
        /** @var array{focus: string, blocks: list<array{when: string, what: string}>, encouragement: string} $note */
        $note = (array) json_decode(Crypt::decryptString($row), true);

        return $note;
    }
}
