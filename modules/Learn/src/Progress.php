<?php

declare(strict_types=1);

namespace Modules\Learn;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Learn\Models\Course;
use Modules\Learn\Models\Enrolment;
use Modules\Learn\Models\Lesson;

/**
 * Taking courses (LN1, specs/learn/01). People start any published course, or the broker assigns one with a due
 * date. A text or video lesson is done when marked done; a quiz is done when passed (the course's pass mark,
 * 80% by default), with the right answers and the reasons shown after each try. When every lesson is done the
 * course is complete and a certificate can be downloaded.
 */
final class Progress
{
    public function enrol(Course $course, User $user, ?User $assignedBy = null, ?string $due = null): Enrolment
    {
        if ($course->status !== 'published') {
            throw ValidationException::withMessages(['course' => 'This course is not published yet.']);
        }
        $e = Enrolment::query()->firstOrCreate(['course_id' => $course->id, 'user_id' => $user->id]);
        if ($assignedBy !== null) {
            $e->update(['assigned_by' => $assignedBy->id, 'due_on' => $due !== null && $due !== '' ? CarbonImmutable::parse($due)->toDateString() : null]);
        }
        if ($e->started_at === null && $assignedBy === null) {
            $e->update(['started_at' => CarbonImmutable::now()]);
        }

        return $e;
    }

    /**
     * Assigns a course to people (the broker or staff), with an optional due date.
     *
     * @param  list<int>  $userIds
     */
    public function assign(Course $course, array $userIds, ?string $due, User $by): int
    {
        if (! Library::manages($by)) {
            throw ValidationException::withMessages(['course' => 'Assigning training needs the "Manage training" permission.']);
        }
        if ($due !== null && $due !== '' && CarbonImmutable::parse($due)->isPast()) {
            throw ValidationException::withMessages(['due_on' => 'Choose a due date in the future.']);
        }
        $n = 0;
        foreach (User::query()->whereIn('id', $userIds)->get() as $u) {
            $this->enrol($course, $u, $by, $due);
            $n++;
        }
        app(AuditLogger::class)->record('learn.course.assigned', metadata: ['course' => $course->ulid, 'people' => $n, 'due_on' => $due]);

        return $n;
    }

    public function markDone(Enrolment $e, Lesson $lesson): Enrolment
    {
        if ($lesson->course_id !== $e->course_id || $lesson->kind === 'quiz') {
            throw ValidationException::withMessages(['lesson' => 'Answer the quiz to finish this lesson.']);
        }

        return $this->finish($e, $lesson->id);
    }

    /**
     * Grades a quiz try. Returns the score, whether it passed, and each question's right answer and reason.
     *
     * @param  array<int|string, int|string>  $answers  question index => chosen option index
     * @return array{score: int, passed: bool, pass_mark: int, results: list<array{correct: bool, answer: int, why: string}>}
     */
    public function grade(Enrolment $e, Lesson $lesson, array $answers): array
    {
        if ($lesson->course_id !== $e->course_id || $lesson->kind !== 'quiz') {
            abort(404);
        }
        $course = Course::query()->whereKey($e->course_id)->firstOrFail();
        $questions = (array) $lesson->questions;
        $right = 0;
        $results = [];
        foreach ($questions as $i => $q) {
            $ok = isset($answers[$i]) && (int) $answers[$i] === (int) $q['answer'];
            $right += $ok ? 1 : 0;
            $results[] = ['correct' => $ok, 'answer' => (int) $q['answer'], 'why' => (string) ($q['why'] ?? '')];
        }
        $score = $questions === [] ? 0 : (int) round($right * 100 / count($questions));
        $scores = (array) $e->scores;
        $best = array_key_exists((string) $lesson->id, $scores) ? (int) $scores[(string) $lesson->id] : 0;
        $scores[(string) $lesson->id] = max($score, $best);
        $e->update(['scores' => $scores, 'started_at' => $e->started_at ?? CarbonImmutable::now()]);
        $passed = $score >= $course->pass_mark;
        if ($passed) {
            $this->finish($e, $lesson->id);
        }

        return ['score' => $score, 'passed' => $passed, 'pass_mark' => $course->pass_mark, 'results' => $results];
    }

    /**
     * Per-lesson state for the course player.
     *
     * @return array{done: list<int>, percent: int, completed: bool}
     */
    public static function state(Enrolment $e): array
    {
        $ids = Lesson::query()->where('course_id', $e->course_id)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $done = array_values(array_intersect(array_map('intval', (array) $e->done), $ids));

        return ['done' => $done, 'percent' => $ids === [] ? 0 : (int) floor(count($done) * 100 / count($ids)), 'completed' => $e->completed_at !== null];
    }

    private function finish(Enrolment $e, int $lessonId): Enrolment
    {
        $done = array_values(array_unique(array_merge(array_map('intval', (array) $e->done), [$lessonId])));
        $e->update(['done' => $done, 'started_at' => $e->started_at ?? CarbonImmutable::now()]);
        $all = Lesson::query()->where('course_id', $e->course_id)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($e->completed_at === null && array_diff($all, $done) === []) {
            $e->update(['completed_at' => CarbonImmutable::now()]);
            app(AuditLogger::class)->record('learn.course.completed', metadata: ['course' => $e->course_id, 'user' => $e->user_id]);
        }

        return $e;
    }
}
