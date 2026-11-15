<?php

declare(strict_types=1);

namespace Modules\Sites\Ailly;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * The brokerage's own answers for AIlly (specs/websites/12): questions visitors ask often (fees, office hours,
 * partner lenders, how commissions work), answered once by the broker. A close match is answered word for word for
 * free; all answers are also given to the AI as facts. Questions AIlly couldn't answer are collected so the broker
 * can add answers.
 */
final class BrokerageAnswers
{
    public const MAX = 50;

    private const STOP = ['a', 'an', 'the', 'is', 'are', 'do', 'does', 'you', 'your', 'i', 'my', 'me', 'we', 'our', 'to', 'of', 'in', 'on', 'for', 'and', 'or',
        'what', 'how', 'can', 'it', 'be', 'with', 'about', 'there', 'any', 'this', 'that', 'have', 'has', 'at', 'if', 'please', 'tell'];

    /** @var list<\stdClass>|null */
    private ?array $all = null;

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return list<array{id: int, question: string, answer: string, hits: int}>
     */
    public function list(): array
    {
        return array_map(fn (\stdClass $a): array => ['id' => (int) $a->id, 'question' => (string) $a->question, 'answer' => (string) $a->answer, 'hits' => (int) $a->hits], $this->rows());
    }

    public function save(?int $id, string $question, string $answer, User $by): void
    {
        $question = mb_substr(trim(strip_tags($question)), 0, 200);
        $answer = mb_substr(trim(strip_tags($answer)), 0, 1200);
        if ($question === '' || $answer === '') {
            throw ValidationException::withMessages(['question' => 'Add a question and its answer.']);
        }
        $db = DB::connection(Connections::cell())->table('ailly_answers');
        $values = ['question' => $question, 'answer' => $answer, 'keywords' => mb_substr(implode(' ', self::words($question)), 0, 400), 'updated_by' => $by->id, 'updated_at' => now()];
        if ($id === null) {
            if ((clone $db)->where('tenant_id', $this->tenants->id())->count() >= self::MAX) {
                throw ValidationException::withMessages(['question' => 'You can keep up to '.self::MAX.' answers. Remove one first.']);
            }
            (clone $db)->insert($values + ['tenant_id' => $this->tenants->id(), 'hits' => 0, 'created_at' => now()]);
        } else {
            (clone $db)->where('tenant_id', $this->tenants->id())->where('id', $id)->update($values) === 1 || abort(404);
        }
        // Questions this answer now covers are no longer "unanswered".
        $words = self::words($question);
        foreach (DB::connection(Connections::cell())->table('ailly_unanswered')->where('tenant_id', $this->tenants->id())->whereNull('resolved_at')->get(['id', 'question']) as $u) {
            if (self::similar($words, self::words((string) $u->question))) {
                DB::connection(Connections::cell())->table('ailly_unanswered')->where('id', $u->id)->update(['resolved_at' => now(), 'updated_at' => now()]);
            }
        }
        $this->all = null;
        app(AuditLogger::class)->record('sites.ailly.answer_saved', metadata: ['question' => $question], severity: 'info');
    }

    public function delete(int $id): void
    {
        DB::connection(Connections::cell())->table('ailly_answers')->where('tenant_id', $this->tenants->id())->where('id', $id)->delete();
        $this->all = null;
    }

    /** The broker's answer to a question that closely matches one of theirs, or null. */
    public function match(string $text): ?string
    {
        $words = self::words($text);
        if (count($words) < 2) {
            return null;
        }
        foreach ($this->rows() as $a) {
            $theirs = array_values(array_filter(explode(' ', (string) $a->keywords)));
            // Every meaningful word of the broker's question is in the visitor's (e.g. "office hours" in "when is your office open, hours?").
            $covered = count($theirs) >= 2 && array_diff($theirs, $words) === [] && count($words) <= count($theirs) + 3;
            if ($covered || self::similar($words, $theirs)) {
                DB::connection(Connections::cell())->table('ailly_answers')->where('id', $a->id)->increment('hits');

                return (string) $a->answer;
            }
        }

        return null;
    }

    /** All answers as facts for the AI. */
    public function asContext(): string
    {
        return mb_substr(implode("\n", array_map(fn (\stdClass $a): string => 'Q: '.$a->question."\nA: ".$a->answer, $this->rows())), 0, 8000);
    }

    /** Remembers a question the AI said it couldn't answer from the website's facts. */
    public function unanswered(string $question): void
    {
        $words = self::words($question);
        if (count($words) < 2) {
            return;
        }
        $hash = hash('sha256', implode(' ', $words));
        $db = DB::connection(Connections::cell())->table('ailly_unanswered');
        $where = fn ($q) => $q->where('tenant_id', $this->tenants->id())->where('question_hash', $hash);
        if ((clone $db)->where($where)->exists()) {
            (clone $db)->where($where)->update(['times' => DB::raw('times + 1'), 'last_asked_at' => now(), 'resolved_at' => null, 'updated_at' => now()]);
        } else {
            (clone $db)->insert(['tenant_id' => $this->tenants->id(), 'question_hash' => $hash, 'question' => mb_substr(trim($question), 0, 300), 'times' => 1,
                'last_asked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Open questions AIlly couldn't answer, most asked first.
     *
     * @return list<array{id: int, question: string, times: int, last_asked_at: string}>
     */
    public function openQuestions(int $limit = 20): array
    {
        return array_values(DB::connection(Connections::cell())->table('ailly_unanswered')->where('tenant_id', $this->tenants->id())->whereNull('resolved_at')
            ->orderByDesc('times')->orderByDesc('last_asked_at')->limit($limit)->get()
            ->map(fn (object $u): array => ['id' => (int) $u->id, 'question' => (string) $u->question, 'times' => (int) $u->times, 'last_asked_at' => (string) $u->last_asked_at])->all());
    }

    public function dismiss(int $id): void
    {
        DB::connection(Connections::cell())->table('ailly_unanswered')->where('tenant_id', $this->tenants->id())->where('id', $id)->update(['resolved_at' => now(), 'updated_at' => now()]);
    }

    /** @return list<\stdClass> */
    private function rows(): array
    {
        return $this->all ??= array_values(DB::connection(Connections::cell())->table('ailly_answers')->where('tenant_id', $this->tenants->id())
            ->orderBy('id')->get()->all());
    }

    /** @return list<string> the meaningful words, normalised and sorted */
    public static function words(string $text): array
    {
        $words = array_values(array_unique(array_filter(explode(' ', Assistant::normalise($text)), fn (string $w): bool => $w !== '' && ! in_array($w, self::STOP, true))));
        sort($words);

        return $words;
    }

    /**
     * Same question when most meaningful words are shared (word overlap 60% or more, with at least two shared).
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function similar(array $a, array $b): bool
    {
        $shared = count(array_intersect($a, $b));
        $union = count(array_unique([...$a, ...$b]));

        return $shared >= 2 && $union > 0 && $shared / $union >= 0.6;
    }
}
