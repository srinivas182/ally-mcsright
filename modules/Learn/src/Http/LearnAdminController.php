<?php

declare(strict_types=1);

namespace Modules\Learn\Http;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Learn\Knowledge;
use Modules\Learn\Library;
use Modules\Learn\Models\Article;
use Modules\Learn\Models\Course;
use Modules\Learn\Models\Enrolment;
use Modules\Learn\Models\Lesson;
use Modules\Learn\Progress;
use Modules\Learn\Scorecard;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Learn › Manage (LN1): write courses and knowledge base pages, add starter courses, assign, and see who finished. */
final class LearnAdminController
{
    public function index(Request $request, Library $library): Response
    {
        self::user($request);
        $courses = Course::query()->where('status', '!=', 'archived')->orderBy('title')->get();
        $stats = Enrolment::query()->selectRaw('course_id, count(*) as people, sum(case when completed_at is not null then 1 else 0 end) as finished, '
            .'sum(case when completed_at is null and due_on < ? then 1 else 0 end) as late', [now()->toDateString()])->groupBy('course_id')->get()->keyBy('course_id');

        return Inertia::render('learn/manage', [
            'courses' => $courses->map(fn (Course $c): array => ['ulid' => $c->ulid, 'title' => $c->title, 'status' => $c->status, 'source' => $c->source,
                'lessons' => Lesson::query()->where('course_id', $c->id)->count(), 'people' => (int) ($stats->get($c->id)->people ?? 0),
                'finished' => (int) ($stats->get($c->id)->finished ?? 0), 'late' => (int) ($stats->get($c->id)->late ?? 0)])->values(),
            'starters' => $library->starters(),
            'articles' => Article::query()->where('status', '!=', 'archived')->orderBy('category')->orderBy('title')->get()->map(fn (Article $a): array => ['ulid' => $a->ulid,
                'title' => $a->title, 'category' => $a->category, 'required' => $a->required, 'status' => $a->status, 'version' => $a->version])->values(),
            'people' => self::people(),
        ]);
    }

    public function edit(Request $request, ?Course $course = null): Response
    {
        self::user($request);

        return Inertia::render('learn/edit-course', ['skills' => Scorecard::SKILLS, 'course' => $course !== null ? ['ulid' => $course->ulid, 'title' => $course->title, 'summary' => $course->summary, 'skill' => $course->skill, 'source' => $course->source,
            'pass_mark' => $course->pass_mark, 'minutes' => $course->minutes, 'status' => $course->status] : null,
            'lessons' => $course !== null ? Lesson::query()->where('course_id', $course->id)->orderBy('sort')->get(['title', 'kind', 'body', 'video_url', 'questions']) : []]);
    }

    public function save(Request $request, Library $library, ?Course $course = null): RedirectResponse
    {
        $user = self::user($request);
        $data = $request->validate(['title' => ['required', 'string', 'max:160'], 'summary' => ['nullable', 'string', 'max:500'], 'skill' => ['nullable', 'string', 'max:30'], 'pass_mark' => ['nullable', 'integer'],
            'minutes' => ['nullable', 'integer'], 'lessons' => ['present', 'array', 'max:60'], 'publish' => ['sometimes', 'boolean']]);
        // All or nothing: a lesson that does not pass the checks leaves no half-made course behind.
        $course = DB::connection(Connections::cell())->transaction(function () use ($library, $course, $data, $user): Course {
            $course = $library->save($course, $data, $user);
            $library->saveLessons($course, array_values($data['lessons']), $user);
            if (array_key_exists('publish', $data)) {
                $library->publish($course, (bool) $data['publish'], $user);
            }

            return $course;
        });

        return redirect('/learn/manage/courses/'.$course->ulid);
    }

    public function starter(Request $request, string $key, Library $library): RedirectResponse
    {
        $library->addStarter($key, self::user($request));

        return back();
    }

    public function assign(Request $request, Course $course, Progress $progress): RedirectResponse
    {
        $data = $request->validate(['people' => ['required', 'array', 'min:1', 'max:1000'], 'people.*' => ['integer'], 'due_on' => ['nullable', 'date']]);
        $ids = array_values(array_intersect(array_map('intval', $data['people']), array_column(self::people(), 'id')));
        $n = $progress->assign($course, $ids, $data['due_on'] ?? null, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Assigned to {$n} people."]);

        return back();
    }

    public function article(Request $request, Knowledge $kb, ?Article $article = null): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:160'], 'category' => ['nullable', 'string', 'max:60'], 'body' => ['required', 'string', 'max:50000'],
            'required' => ['sometimes', 'boolean'], 'status' => ['nullable', 'in:draft,published,archived']]);
        $a = $kb->save($article, $data, self::user($request));

        return redirect('/learn/kb/'.$a->ulid);
    }

    public function editArticle(Request $request, ?Article $article = null): Response
    {
        self::user($request);

        return Inertia::render('learn/edit-article', ['article' => $article !== null ? ['ulid' => $article->ulid, 'title' => $article->title, 'category' => $article->category,
            'body' => $article->body, 'required' => $article->required, 'status' => $article->status] : null,
            'categories' => Article::query()->distinct()->orderBy('category')->pluck('category')->values()]);
    }

    /** Who finished what, for the broker's records or a state audit. Formula-safe. */
    public function report(Request $request): StreamedResponse
    {
        self::user($request);
        $rows = Enrolment::query()->orderBy('course_id')->orderBy('user_id')->get();
        $courses = Course::query()->pluck('title', 'id');
        $names = User::query()->whereIn('id', $rows->pluck('user_id')->unique())->get()->mapWithKeys(fn (User $u): array => [$u->id => (string) $u->name]);
        $safe = fn (?string $c): string => preg_match('/^[=+\-@\t\r]/', (string) $c) === 1 ? "'".$c : (string) $c;

        return response()->streamDownload(function () use ($rows, $courses, $names, $safe): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['Person', 'Course', 'Assigned', 'Due', 'Started', 'Completed'], escape: '');
            foreach ($rows as $e) {
                fputcsv($out, array_map($safe, [(string) ($names[$e->user_id] ?? ''), (string) ($courses[$e->course_id] ?? ''), $e->assigned_by !== null ? 'Yes' : 'No',
                    $e->due_on?->toDateString(), $e->started_at?->toDateString(), $e->completed_at?->toDateString()]), escape: '');
            }
            fclose($out);
        }, 'training-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** @return list<array{id: int, name: string}> */
    private static function people(): array
    {
        return array_values(User::query()->whereIn('kind', [UserKind::Agent->value, UserKind::Staff->value, UserKind::Assistant->value])->where('status', UserStatus::Active->value)
            ->orderBy('first_name')->get()->map(fn (User $u): array => ['id' => $u->id, 'name' => (string) $u->name])->all());
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && Library::manages($user), 403);

        return $user;
    }
}
