<?php

declare(strict_types=1);

namespace Modules\Learn\Http;

use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Tenancy\TenantContext;
use Modules\Learn\Knowledge;
use Modules\Learn\Library;
use Modules\Learn\Models\Article;
use Modules\Learn\Models\Course;
use Modules\Learn\Models\Enrolment;
use Modules\Learn\Models\Lesson;
use Modules\Learn\Progress;
use Modules\Team\Help\HelpCentre;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Learn for everyone (LN1): my courses, the library, the course player, certificates and the knowledge base. */
final class LearnController
{
    public function index(Request $request, Knowledge $kb): Response
    {
        $user = self::user($request);
        $mine = Enrolment::query()->where('user_id', $user->id)->get()->keyBy('course_id');
        $courses = Course::query()->where('status', 'published')->orderBy('title')->get();
        $q = trim((string) $request->query('q', ''));

        return Inertia::render('learn/index', [
            'courses' => $courses->map(fn (Course $c): array => self::card($c, $mine->get($c->id)))->values(),
            'knowledge' => $kb->index($user),
            'outstanding' => $kb->outstanding($user),
            'q' => $q,
            'results' => $q !== '' ? $kb->search($q) : null,
            'manages' => Library::manages($user),
        ]);
    }

    public function course(Request $request, Course $course): Response
    {
        $user = self::user($request);
        abort_unless($course->status === 'published' || Library::manages($user), 404);
        $e = Enrolment::query()->where('course_id', $course->id)->where('user_id', $user->id)->first();

        return Inertia::render('learn/course', [
            'course' => ['ulid' => $course->ulid, 'title' => $course->title, 'summary' => $course->summary, 'minutes' => $course->minutes, 'pass_mark' => $course->pass_mark,
                'status' => $course->status],
            'lessons' => Lesson::query()->where('course_id', $course->id)->orderBy('sort')->get()->map(fn (Lesson $l): array => ['id' => $l->id, 'title' => $l->title,
                'kind' => $l->kind, 'html' => $l->body !== null ? HelpCentre::html($l->body) : null, 'embed' => Library::embed($l->video_url),
                'questions' => $l->kind === 'quiz' ? array_map(fn (array $q): array => ['q' => $q['q'], 'options' => $q['options']], (array) $l->questions) : null])->values(),
            'enrolment' => $e !== null ? Progress::state($e) + ['due_on' => $e->due_on?->toDateString(), 'scores' => (array) $e->scores] : null,
            'quiz' => $request->session()->get('quiz'),
        ]);
    }

    public function start(Request $request, Course $course, Progress $progress): RedirectResponse
    {
        $progress->enrol($course, self::user($request));

        return redirect('/learn/courses/'.$course->ulid);
    }

    public function done(Request $request, Course $course, int $lesson, Progress $progress): RedirectResponse
    {
        $progress->markDone(self::enrolment($request, $course), Lesson::query()->whereKey($lesson)->firstOrFail());

        return back();
    }

    public function quiz(Request $request, Course $course, int $lesson, Progress $progress): RedirectResponse
    {
        $data = $request->validate(['answers' => ['required', 'array', 'max:30'], 'answers.*' => ['integer', 'min:0', 'max:9']]);
        $result = $progress->grade(self::enrolment($request, $course), Lesson::query()->whereKey($lesson)->firstOrFail(), $data['answers']);

        return back()->with('quiz', ['lesson' => $lesson] + $result);
    }

    public function certificate(Request $request, Course $course, TenantContext $tenants): HttpResponse
    {
        $user = self::user($request);
        $e = Enrolment::query()->where('course_id', $course->id)->where('user_id', $user->id)->whereNotNull('completed_at')->firstOrFail();

        return Pdf::loadView('learn::certificate', ['name' => (string) $user->name, 'course' => $course->title, 'brokerage' => $tenants->current()->name,
            'date' => $e->completed_at?->toFormattedDateString(), 'minutes' => $course->minutes, 'id' => strtoupper(substr($course->ulid, -6)).'-'.$e->id])
            ->setPaper('letter', 'landscape')->download('Certificate - '.$course->title.'.pdf');
    }

    public function article(Request $request, Article $article, Knowledge $kb): Response
    {
        $user = self::user($request);
        abort_unless($article->status === 'published' || Library::manages($user), 404);

        return Inertia::render('learn/article', ['article' => ['ulid' => $article->ulid, 'title' => $article->title, 'category' => $article->category,
            'html' => HelpCentre::html($article->body), 'required' => $article->required, 'version' => $article->version, 'updated' => $article->updated_at->toDateString()],
            'needs_ack' => $kb->read($article, $user), 'manages' => Library::manages($user)]);
    }

    public function acknowledge(Request $request, Article $article, Knowledge $kb): RedirectResponse
    {
        $kb->acknowledge($article, self::user($request));

        return back();
    }

    /** @return array<string, mixed> */
    private static function card(Course $c, ?Enrolment $e): array
    {
        return ['ulid' => $c->ulid, 'title' => $c->title, 'summary' => $c->summary, 'minutes' => $c->minutes, 'source' => $c->source,
            'enrolled' => $e !== null, 'percent' => $e !== null ? Progress::state($e)['percent'] : 0, 'completed' => $e?->completed_at !== null,
            'due_on' => $e?->due_on?->toDateString(), 'assigned' => $e?->assigned_by !== null];
    }

    private static function enrolment(Request $request, Course $course): Enrolment
    {
        return Enrolment::query()->where('course_id', $course->id)->where('user_id', self::user($request)->id)->firstOrFail();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && in_array($user->kind, [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant], true), 403);

        return $user;
    }
}
