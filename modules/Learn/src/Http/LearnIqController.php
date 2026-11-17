<?php

declare(strict_types=1);

namespace Modules\Learn\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Learn\Certifications;
use Modules\Learn\CourseBuilder;
use Modules\Learn\LaunchProgram;
use Modules\Learn\Library;
use Modules\Learn\Models\Course;
use Modules\Learn\Models\Launch;
use Modules\Learn\Models\Mentor;
use Modules\Learn\Scorecard;

/** Learn-IQ (LN2): the New Agent Launch, mentors, certifications that open tools, the AI course builder, scorecards. */
final class LearnIqController
{
    /** The agent's own launch and scorecard, or (for the broker) everyone's. */
    public function launch(Request $request, LaunchProgram $program): Response
    {
        $user = self::user($request);
        $manages = Library::manages($user);
        $mine = Launch::query()->where('user_id', $user->id)->first();
        $mentoring = Launch::query()->where('mentor_id', $user->id)->whereNull('finished_at')->get();
        $all = $manages ? Launch::query()->orderByDesc('started_on')->limit(100)->get() : collect();
        $ids = $all->pluck('user_id')->merge($all->pluck('mentor_id'))->merge($mentoring->pluck('user_id'))->merge([$mine?->user_id, $mine?->mentor_id]);
        $names = User::query()->whereIn('id', $ids->filter()->unique()->values())->get()
            ->mapWithKeys(fn (User $u): array => [$u->id => (string) $u->name]);
        $row = fn (Launch $l): array => ['id' => $l->id, 'agent' => (string) ($names[$l->user_id] ?? ''), 'mentor' => $l->mentor_id !== null ? (string) ($names[$l->mentor_id] ?? '') : null,
            'started_on' => $l->started_on->toDateString(), 'finished' => $l->finished_at !== null, 'steps' => $program->steps($l, $user)];

        return Inertia::render('learn/launch', [
            'mine' => $mine !== null ? $row($mine) : null,
            'mentoring' => $mentoring->map($row)->values(),
            'launches' => $all->map($row)->values(),
            'scorecard' => Scorecard::forUser($user->id),
            'mentor' => Mentor::query()->where('user_id', $user->id)->first()?->only(['strengths', 'max_mentees', 'active']),
            'manages' => $manages,
            'agents' => $manages ? User::query()->where('kind', UserKind::Agent->value)->where('status', UserStatus::Active->value)->orderBy('first_name')->get()
                ->map(fn (User $u): array => ['id' => $u->id, 'name' => (string) $u->name])->values() : [],
            'suggested' => $manages ? LaunchProgram::mentorSuggestions() : [],
            'gates' => $manages ? app(Certifications::class)->settings() : [],
            'courses' => $manages ? Course::query()->where('status', 'published')->orderBy('title')->get(['ulid', 'title']) : [],
            'skills' => Scorecard::SKILLS,
        ]);
    }

    public function start(Request $request, LaunchProgram $program): RedirectResponse
    {
        $data = $request->validate(['agent' => ['required', 'integer'], 'mentor' => ['nullable', 'integer']]);
        $agent = User::query()->whereKey((int) $data['agent'])->firstOrFail();
        $mentor = isset($data['mentor']) ? User::query()->whereKey((int) $data['mentor'])->firstOrFail() : null;
        $program->start($agent, $mentor, self::user($request));

        return back();
    }

    public function tick(Request $request, int $launch, string $step, LaunchProgram $program): RedirectResponse
    {
        $program->tick(Launch::query()->whereKey($launch)->firstOrFail(), $step, self::user($request));

        return back();
    }

    public function mentor(Request $request): RedirectResponse
    {
        $user = self::user($request);
        abort_unless($user->kind === UserKind::Agent || $user->kind === UserKind::Owner, 403);
        $data = $request->validate(['active' => ['required', 'boolean'], 'strengths' => ['nullable', 'string', 'max:200'], 'max_mentees' => ['nullable', 'integer', 'min:1', 'max:5']]);
        Mentor::query()->updateOrCreate(['user_id' => $user->id], ['active' => (bool) $data['active'], 'strengths' => $data['strengths'] ?? null,
            'max_mentees' => (int) ($data['max_mentees'] ?? 2)]);

        return back();
    }

    public function gate(Request $request, Certifications $certs): RedirectResponse
    {
        $data = $request->validate(['tool' => ['required', 'string'], 'course' => ['nullable', 'string', 'size:26']]);
        $course = isset($data['course']) ? Course::query()->where('ulid', $data['course'])->firstOrFail() : null;
        $certs->set($data['tool'], $course, self::user($request));

        return back();
    }

    public function build(Request $request, CourseBuilder $builder): RedirectResponse
    {
        $data = $request->validate(['topic' => ['required', 'string', 'max:200'], 'notes' => ['nullable', 'string', 'max:20000'], 'skill' => ['nullable', 'string', 'max:30']]);
        $course = $builder->build($data['topic'], $data['notes'] ?? null, $data['skill'] ?? null, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Draft ready. Read it, change what you need, then publish.']);

        return redirect('/learn/manage/courses/'.$course->ulid);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant], true), 403);

        return $user;
    }
}
