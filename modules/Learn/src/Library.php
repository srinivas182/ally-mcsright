<?php

declare(strict_types=1);

namespace Modules\Learn;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Access\Can;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Learn\Models\Course;
use Modules\Learn\Models\Lesson;

/**
 * The brokerage's course library (LN1, specs/learn/01). Owners and staff (or anyone with "Manage training")
 * write courses as short lessons: text, a video from YouTube, Vimeo or Loom, or a quiz. RightAlly ships starter
 * courses as files; adding one copies it into the brokerage's library, where it can be changed like any other.
 */
final class Library
{
    /** Video sites whose players may be shown in a lesson. */
    private const VIDEO_HOSTS = ['www.youtube.com' => 'youtube', 'youtube.com' => 'youtube', 'youtu.be' => 'youtube', 'vimeo.com' => 'vimeo', 'player.vimeo.com' => 'vimeo',
        'www.loom.com' => 'loom', 'loom.com' => 'loom'];

    public static function manages(User $user): bool
    {
        return in_array($user->kind, [UserKind::Owner, UserKind::Staff], true) || Can::manage($user, 'learn.manage');
    }

    /**
     * @param  array{title?: string, summary?: string|null, pass_mark?: int|string|null, minutes?: int|string|null}  $in
     */
    public function save(?Course $course, array $in, User $by, string $source = 'brokerage'): Course
    {
        self::guard($by);
        $title = trim(strip_tags((string) ($in['title'] ?? '')));
        if ($title === '') {
            throw ValidationException::withMessages(['title' => 'Name the course.']);
        }
        $data = ['title' => mb_substr($title, 0, 160), 'summary' => isset($in['summary']) ? mb_substr(trim(strip_tags((string) $in['summary'])), 0, 500) : null,
            'pass_mark' => max(50, min(100, (int) ($in['pass_mark'] ?? 80))), 'minutes' => max(1, min(600, (int) ($in['minutes'] ?? 10)))];
        if ($course === null) {
            $course = Course::query()->create($data + ['source' => $source, 'status' => 'draft', 'created_by' => $by->id]);
            app(AuditLogger::class)->record('learn.course.created', metadata: ['course' => $course->ulid, 'source' => $source]);
        } else {
            $course->update($data);
        }

        return $course;
    }

    /**
     * Replaces the course's lessons in the order given.
     *
     * @param  list<array<string, mixed>>  $lessons
     */
    public function saveLessons(Course $course, array $lessons, User $by): void
    {
        self::guard($by);
        $clean = [];
        foreach ($lessons as $i => $l) {
            $clean[] = self::lesson($l, $i);
        }
        DB::connection(Connections::cell())->transaction(function () use ($course, $clean): void {
            Lesson::query()->where('course_id', $course->id)->delete();
            foreach ($clean as $i => $l) {
                Lesson::query()->create($l + ['course_id' => $course->id, 'sort' => $i]);
            }
        });
    }

    public function publish(Course $course, bool $published, User $by): void
    {
        self::guard($by);
        if ($published && ! Lesson::query()->where('course_id', $course->id)->exists()) {
            throw ValidationException::withMessages(['course' => 'Add at least one lesson first.']);
        }
        $course->update(['status' => $published ? 'published' : 'draft']);
        app(AuditLogger::class)->record($published ? 'learn.course.published' : 'learn.course.unpublished', metadata: ['course' => $course->ulid]);
    }

    /**
     * The starter courses RightAlly ships, and whether each is already in the library.
     *
     * @return list<array{key: string, title: string, summary: string, minutes: int, lessons: int, added: bool}>
     */
    public function starters(): array
    {
        $added = Course::query()->whereNotNull('starter_key')->pluck('starter_key')->all();
        $out = [];
        foreach (self::starterFiles() as $key => $c) {
            $out[] = ['key' => $key, 'title' => (string) $c['title'], 'summary' => (string) ($c['summary'] ?? ''), 'minutes' => (int) ($c['minutes'] ?? 10),
                'lessons' => count((array) ($c['lessons'] ?? [])), 'added' => in_array($key, $added, true)];
        }

        return $out;
    }

    /** Copies a starter course into the library, published and ready to assign. */
    public function addStarter(string $key, User $by): Course
    {
        self::guard($by);
        $c = self::starterFiles()[$key] ?? null;
        if ($c === null) {
            throw ValidationException::withMessages(['starter' => 'That course was not found.']);
        }
        $existing = Course::query()->where('starter_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }
        $course = $this->save(null, ['title' => (string) $c['title'], 'summary' => (string) ($c['summary'] ?? ''), 'pass_mark' => (int) ($c['pass_mark'] ?? 80),
            'minutes' => (int) ($c['minutes'] ?? 10)], $by, 'rightally');
        $course->update(['starter_key' => $key]);
        $this->saveLessons($course, array_values((array) $c['lessons']), $by);
        $this->publish($course, true, $by);

        return $course;
    }

    /**
     * Turns an allowed video address into its player address, or null.
     */
    public static function embed(?string $url): ?string
    {
        $p = parse_url(trim((string) $url));
        $host = is_array($p) ? strtolower($p['host'] ?? '') : '';
        if (! is_array($p) || ($p['scheme'] ?? '') !== 'https' || ! isset(self::VIDEO_HOSTS[$host])) {
            return null;
        }
        $site = self::VIDEO_HOSTS[$host];
        $path = (string) ($p['path'] ?? '');
        parse_str((string) ($p['query'] ?? ''), $q);
        $id = match ($site) {
            'youtube' => preg_match('~^/(?:embed/|shorts/)?([A-Za-z0-9_-]{11})$~', $path, $m) === 1 && $path !== '/watch' ? $m[1] : (is_string($q['v'] ?? null) ? $q['v'] : ''),
            'vimeo' => preg_match('~/(?:video/)?(\d{6,12})$~', $path, $m) === 1 ? $m[1] : '',
            'loom' => preg_match('~^/(?:share|embed)/([a-f0-9]{32})$~', $path, $m) === 1 ? $m[1] : '',
        };
        if (($site === 'youtube' && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) !== 1) || $id === '') {
            return null;
        }

        return match ($site) {
            'youtube' => 'https://www.youtube-nocookie.com/embed/'.$id,
            'vimeo' => 'https://player.vimeo.com/video/'.$id,
            'loom' => 'https://www.loom.com/embed/'.$id,
        };
    }

    /**
     * @param  array<string, mixed>  $l
     * @return array{title: string, kind: string, body: string|null, video_url: string|null, questions: list<array{q: string, options: list<string>, answer: int, why: string}>|null}
     */
    private static function lesson(array $l, int $i): array
    {
        $n = $i + 1;
        $title = trim(strip_tags((string) ($l['title'] ?? '')));
        $kind = (string) ($l['kind'] ?? 'text');
        if ($title === '' || ! in_array($kind, Lesson::KINDS, true)) {
            throw ValidationException::withMessages(['lessons' => "Lesson {$n} needs a title and a type."]);
        }
        $body = trim((string) ($l['body'] ?? ''));
        $video = null;
        $questions = null;
        if ($kind === 'video') {
            $video = trim((string) ($l['video_url'] ?? ''));
            if (self::embed($video) === null) {
                throw ValidationException::withMessages(['lessons' => "Lesson {$n}: use a YouTube, Vimeo or Loom link."]);
            }
        }
        if ($kind === 'text' && $body === '') {
            throw ValidationException::withMessages(['lessons' => "Lesson {$n} has no text."]);
        }
        if ($kind === 'quiz') {
            $questions = [];
            foreach (array_slice((array) ($l['questions'] ?? []), 0, 30) as $j => $q) {
                $options = array_values(array_filter(array_map(fn ($o): string => mb_substr(trim(strip_tags((string) $o)), 0, 200), (array) ($q['options'] ?? [])),
                    fn (string $o): bool => $o !== ''));
                $answer = (int) ($q['answer'] ?? -1);
                $text = mb_substr(trim(strip_tags((string) ($q['q'] ?? ''))), 0, 300);
                if ($text === '' || count($options) < 2 || ! isset($options[$answer])) {
                    throw ValidationException::withMessages(['lessons' => "Lesson {$n}, question ".($j + 1).': add the question, two or more answers, and mark the right one.']);
                }
                $questions[] = ['q' => $text, 'options' => array_slice($options, 0, 6), 'answer' => $answer, 'why' => mb_substr(trim(strip_tags((string) ($q['why'] ?? ''))), 0, 400)];
            }
            if ($questions === []) {
                throw ValidationException::withMessages(['lessons' => "Lesson {$n} is a quiz with no questions."]);
            }
        }

        return ['title' => mb_substr($title, 0, 160), 'kind' => $kind, 'body' => $body !== '' ? mb_substr($body, 0, 20000) : null, 'video_url' => $video, 'questions' => $questions];
    }

    /** @return array<string, array<string, mixed>> */
    private static function starterFiles(): array
    {
        $out = [];
        foreach (glob(__DIR__.'/../resources/courses/*.json') ?: [] as $file) {
            $c = json_decode((string) file_get_contents($file), true);
            if (is_array($c) && isset($c['title'], $c['lessons'])) {
                $out[basename($file, '.json')] = $c;
            }
        }
        ksort($out);

        return $out;
    }

    private static function guard(User $by): void
    {
        if (! self::manages($by)) {
            throw ValidationException::withMessages(['course' => 'Managing training needs the "Manage training" permission.']);
        }
    }
}
