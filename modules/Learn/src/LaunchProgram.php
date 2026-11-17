<?php

declare(strict_types=1);

namespace Modules\Learn;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Learn\Models\Course;
use Modules\Learn\Models\Enrolment;
use Modules\Learn\Models\Launch;
use Modules\Learn\Models\Mentor;

/**
 * The New Agent Launch (LN2, specs/learn/02): a 30-day plan of small daily steps, with a mentor. Some steps tick
 * themselves (two-step sign-in, the starter course, the office pages); the rest the agent ticks, and the last one
 * (the 30-day check-in) only the broker or the mentor can tick. Late steps are shown to the broker.
 */
final class LaunchProgram
{
    /** key => [day, title, how, who ticks: agent|leader|auto] */
    public const STEPS = [
        'two_factor' => [1, 'Turn on two-step sign-in', 'Settings › Security. It keeps your clients\' details safe.', 'auto'],
        'profile' => [1, 'Add your photo and phone number', 'Settings › Profile, so clients and your broker can reach you.', 'agent'],
        'basics' => [2, 'Finish "RightAlly in 20 minutes"', 'Learn › Course library.', 'auto'],
        'office_pages' => [3, 'Read and confirm the office pages', 'Learn › Office knowledge base, pages marked "Please read".', 'auto'],
        'meet_mentor' => [3, 'Meet your mentor', 'Coffee or a call. Agree on a weekly time.', 'agent'],
        'sphere' => [5, 'Bring in the people you know', 'Contacts › Import. Aim for at least 25 people.', 'agent'],
        'website' => [7, 'Check your agent page and share your link', 'My website. Download the QR code for your cards.', 'agent'],
        'first_calls' => [10, 'Make your first 10 calls', "Today's 10 picks who to call and says why.", 'agent'],
        'shadow' => [14, 'Go with your mentor to a showing or listing appointment', 'Watch, then talk about what went well.', 'agent'],
        'open_house' => [21, 'Hold or help at an open house', 'Ask your broker which listing.', 'agent'],
        'review' => [30, '30-day check-in with your broker', 'Talk about what is working and your next 60 days.', 'leader'],
    ];

    public function __construct(private readonly Knowledge $kb) {}

    public function start(User $agent, ?User $mentor, User $by): Launch
    {
        if (! Library::manages($by)) {
            throw ValidationException::withMessages(['agent' => 'Starting a launch needs the "Manage training" permission.']);
        }
        if ($agent->kind !== UserKind::Agent || $agent->status !== UserStatus::Active) {
            throw ValidationException::withMessages(['agent' => 'Choose an active agent.']);
        }
        if ($mentor !== null && ($mentor->id === $agent->id || $mentor->status !== UserStatus::Active)) {
            throw ValidationException::withMessages(['mentor' => 'Choose another active person as the mentor.']);
        }
        $launch = Launch::query()->updateOrCreate(['user_id' => $agent->id], ['mentor_id' => $mentor?->id, 'started_on' => CarbonImmutable::today()->toDateString(),
            'done' => [], 'finished_at' => null, 'started_by' => $by->id]);
        $basics = Course::query()->where('starter_key', 'rightally-basics')->where('status', 'published')->first();
        if ($basics !== null) {
            app(Progress::class)->enrol($basics, $agent, $by, CarbonImmutable::today()->addDays(self::STEPS['basics'][0] - 1)->toDateString());
        }
        app(AuditLogger::class)->record('learn.launch.started', metadata: ['agent' => $agent->id, 'mentor' => $mentor?->id]);

        return $launch;
    }

    /**
     * @return list<array{key: string, day: int, title: string, how: string, who: string, due: string, done: bool, late: bool, can_tick: bool}>
     */
    public function steps(Launch $launch, User $viewer): array
    {
        $agent = User::query()->whereKey($launch->user_id)->firstOrFail();
        $ticked = (array) $launch->done;
        $leader = Library::manages($viewer) || $viewer->id === $launch->mentor_id;
        $out = [];
        foreach (self::STEPS as $key => [$day, $title, $how, $who]) {
            $done = $who === 'auto' ? $this->auto($key, $agent) : in_array($key, $ticked, true);
            $due = $launch->started_on->addDays($day - 1);
            $out[] = ['key' => $key, 'day' => $day, 'title' => $title, 'how' => $how, 'who' => $who, 'due' => $due->toDateString(), 'done' => $done,
                'late' => ! $done && $due->lessThan(CarbonImmutable::today()),
                'can_tick' => ! $done && (($who === 'agent' && ($viewer->id === $agent->id || $leader)) || ($who === 'leader' && $leader))];
        }

        return $out;
    }

    public function tick(Launch $launch, string $key, User $by): void
    {
        $step = collect($this->steps($launch, $by))->firstWhere('key', $key);
        if ($step === null || ! $step['can_tick']) {
            abort(403);
        }
        $launch->update(['done' => array_values(array_unique(array_merge((array) $launch->done, [$key])))]);
        if (collect($this->steps($launch->refresh(), $by))->every(fn (array $s): bool => $s['done'])) {
            $launch->update(['finished_at' => CarbonImmutable::now()]);
            app(AuditLogger::class)->record('learn.launch.finished', metadata: ['agent' => $launch->user_id]);
        }
    }

    /**
     * Mentors with room, fewest mentees first: suggestions for a new agent.
     *
     * @return list<array{id: int, name: string, strengths: string|null, mentees: int}>
     */
    public static function mentorSuggestions(?int $exclude = null): array
    {
        $out = [];
        foreach (Mentor::query()->where('active', true)->get() as $m) {
            $u = User::query()->whereKey($m->user_id)->where('status', UserStatus::Active->value)->first();
            $mentees = Launch::query()->where('mentor_id', $m->user_id)->whereNull('finished_at')->count();
            if ($u === null || $u->id === $exclude || $mentees >= $m->max_mentees) {
                continue;
            }
            $out[] = ['id' => $u->id, 'name' => (string) $u->name, 'strengths' => $m->strengths, 'mentees' => $mentees];
        }
        usort($out, fn (array $a, array $b): int => [$a['mentees'], $a['name']] <=> [$b['mentees'], $b['name']]);

        return $out;
    }

    private function auto(string $key, User $agent): bool
    {
        return match ($key) {
            'two_factor' => $agent->getAttribute('two_factor_confirmed_at') !== null,
            'basics' => Enrolment::query()->whereIn('course_id', Course::query()->where('starter_key', 'rightally-basics')->select('id'))->where('user_id', $agent->id)
                ->whereNotNull('completed_at')->exists(),
            'office_pages' => $this->kb->outstanding($agent) === 0,
            default => false,
        };
    }
}
