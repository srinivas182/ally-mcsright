<?php

declare(strict_types=1);

namespace Modules\Learn;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Support\Connections;
use Modules\Learn\Models\Course;

/**
 * The AI course builder (LN2, specs/learn/02). The broker names a topic and can paste their own notes (an office
 * policy, a script, a checklist). AI drafts a short course: three to six text lessons and a quiz. It is saved as a
 * DRAFT for the broker to read, change and publish; nothing reaches agents until a person publishes it. When notes
 * are given, the course must stay within them, and the broker is reminded to check every fact and rule.
 */
final class CourseBuilder
{
    public const FEATURE = 'learn.course_builder';

    private const SCHEMA = ['type' => 'object', 'required' => ['title', 'summary', 'lessons', 'quiz'], 'properties' => [
        'title' => ['type' => 'string'], 'summary' => ['type' => 'string'], 'minutes' => ['type' => 'integer'],
        'lessons' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['title', 'body'], 'properties' => ['title' => ['type' => 'string'], 'body' => ['type' => 'string']]]],
        'quiz' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['q', 'options', 'answer'], 'properties' => ['q' => ['type' => 'string'],
            'options' => ['type' => 'array', 'items' => ['type' => 'string']], 'answer' => ['type' => 'integer'], 'why' => ['type' => 'string']]]],
    ]];

    public function __construct(private readonly AiGateway $gateway, private readonly Library $library) {}

    public function build(string $topic, ?string $notes, ?string $skill, User $by): Course
    {
        if (! Library::manages($by)) {
            throw ValidationException::withMessages(['topic' => 'Building courses needs the "Manage training" permission.']);
        }
        $topic = trim(strip_tags($topic));
        if (mb_strlen($topic) < 4) {
            throw ValidationException::withMessages(['topic' => 'Say what the course should teach, for example "Running a great open house".']);
        }
        $notes = $notes !== null ? trim($notes) : '';
        $result = $this->gateway->run(new AiTask(self::FEATURE, 'learn.course_builder', 'course-builder:'.$by->id.':'.md5($topic.'|'.$notes),
            inputs: ['topic' => mb_substr($topic, 0, 200), 'grounding' => $notes !== '' ? 'Use ONLY the brokerage notes provided as data.' : 'Use widely accepted good practice for US real estate agents.'],
            untrustedContent: $notes !== '' ? [mb_substr($notes, 0, 20000)] : [], outputSchema: self::SCHEMA, userId: $by->id));
        $json = (array) $result->json;
        $lessons = [];
        foreach (array_slice((array) ($json['lessons'] ?? []), 0, 6) as $l) {
            $title = trim((string) ($l['title'] ?? ''));
            $body = trim((string) ($l['body'] ?? ''));
            if ($title !== '' && $body !== '') {
                $lessons[] = ['title' => $title, 'kind' => 'text', 'body' => $body];
            }
        }
        $questions = [];
        foreach (array_slice((array) ($json['quiz'] ?? []), 0, 8) as $q) {
            $options = array_values(array_filter(array_map(fn ($o): string => trim((string) $o), (array) ($q['options'] ?? [])), fn (string $o): bool => $o !== ''));
            $answer = (int) ($q['answer'] ?? -1);
            if (trim((string) ($q['q'] ?? '')) !== '' && count($options) >= 2 && isset($options[$answer])) {
                $questions[] = ['q' => (string) $q['q'], 'options' => $options, 'answer' => $answer, 'why' => (string) ($q['why'] ?? '')];
            }
        }
        if ($lessons === []) {
            throw ValidationException::withMessages(['topic' => 'The draft came back empty. Try a clearer topic, or add your notes.']);
        }
        $lessons[0]['body'] = "> Draft written by AI. Check every fact and rule before you publish.\n\n".$lessons[0]['body'];
        if ($questions !== []) {
            $lessons[] = ['title' => 'Check what you learned', 'kind' => 'quiz', 'questions' => $questions];
        }

        return DB::connection(Connections::cell())->transaction(function () use ($json, $topic, $skill, $lessons, $by): Course {
            $course = $this->library->save(null, ['title' => (string) ($json['title'] ?? $topic) ?: $topic, 'summary' => (string) ($json['summary'] ?? ''),
                'minutes' => (int) ($json['minutes'] ?? 10), 'skill' => $skill], $by, 'ai');
            $this->library->saveLessons($course, $lessons, $by);

            return $course;
        });
    }
}
