<?php

declare(strict_types=1);

namespace Modules\Transactions\Policies;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Forms\Guard;
use Modules\Transactions\Reading\DocIq;
use Modules\Transactions\Timeline\StatePacks;
use Throwable;

/**
 * Compliance Knowledge, the answers (specs/ai/05): agents ask in plain English or Spanish and get an answer only from
 * the brokerage's own documents and RightAlly's state rule packs, with the passage quoted. Anything without a source,
 * and anything high-stakes (legal, discrimination, commission disputes), goes to the broker, who replies; questions
 * the manual could not answer are kept as its gaps.
 */
final class PolicyAnswers
{
    public const FEATURE = 'docs.policy_answer';

    private const HIGH_STAKES = '/\b(lawsuit|sue|sued|attorney|lawyer|legal advice|discriminat|fair housing complaint|commission dispute|arbitration|license (complaint|investigation)|demanda|abogado|discrimina)/iu';

    private const STOP = ['the', 'and', 'for', 'are', 'can', 'what', 'when', 'how', 'who', 'does', 'our', 'with', 'that', 'this', 'have', 'need', 'from', 'into', 'about', 'should',
        'que', 'como', 'cuando', 'para', 'los', 'las', 'una', 'del', 'con', 'por'];

    public function __construct(private readonly AiGateway $gateway) {}

    /**
     * @return array{ulid: string, status: string, answer: string, sources: list<array{title: string, page: int, quote: string}>}
     */
    public function ask(User $user, string $question): array
    {
        $question = mb_substr(trim($question), 0, 1000);
        if (mb_strlen($question) < 5) {
            throw ValidationException::withMessages(['question' => 'Ask a question.']);
        }
        $result = preg_match(self::HIGH_STAKES, $question) === 1
            ? ['found' => false, 'answer' => 'This needs your broker (and possibly an attorney). Your question has been passed to your broker.', 'sources' => []]
            : $this->answer($user, $question);
        $status = $result['found'] ? 'answered' : 'to_broker';
        $answer = $result['found'] ? $result['answer'] : ($result['answer'] !== '' ? $result['answer'] : 'Your brokerage\'s documents do not answer this. Your question has been passed to your broker.');
        $ulid = (string) Str::ulid();
        DB::connection(Connections::cell())->table('tx_policy_questions')->insert(['ulid' => $ulid, 'tenant_id' => app(TenantContext::class)->id(),
            'user_id' => $user->id, 'question' => $question, 'answer' => $answer, 'sources' => json_encode($result['sources']), 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        if ($status === 'to_broker') {
            Notification::send(self::brokers(), new PolicyQuestionMail($user->name, $question));
        }

        return ['ulid' => $ulid, 'status' => $status, 'answer' => $answer, 'sources' => $result['sources']];
    }

    /**
     * Answers from the sources without recording anything (the assistant's read-only lookup).
     *
     * @return array{found: bool, answer: string, sources: list<array{title: string, page: int, quote: string}>}
     */
    public function answer(User $user, string $question): array
    {
        $passages = self::search($question);
        if ($passages === []) {
            return ['found' => false, 'answer' => '', 'sources' => []];
        }
        try {
            $r = $this->gateway->run(new AiTask(self::FEATURE, 'docs.policy_answer', 'policy-answer:'.$user->id.':'.CarbonImmutable::now()->format('YmdHisv'),
                untrustedContent: [...array_map(fn (array $p, int $i): string => '[S'.($i + 1).'] '.$p['title'].', page '.$p['page'].":\n".$p['text'], $passages, array_keys($passages)),
                    'QUESTION: '.$question],
                outputSchema: ['type' => 'object', 'required' => ['found', 'answer'], 'properties' => ['found' => ['type' => 'boolean'], 'answer' => ['type' => 'string'], 'citations' => ['type' => 'array']]],
                userId: $user->id));
        } catch (Throwable $e) {
            report($e);

            return ['found' => false, 'answer' => '', 'sources' => []];
        }
        $answer = mb_substr(trim((string) ($r->json['answer'] ?? '')), 0, 2000);
        $sources = [];
        foreach (array_slice((array) ($r->json['citations'] ?? []), 0, 5) as $c) {
            $c = (array) $c;
            $n = (int) preg_replace('/\D/', '', (string) ($c['source'] ?? '')) - 1;
            $quote = trim((string) ($c['quote'] ?? ''));
            if (isset($passages[$n]) && DocIq::onPage($quote, $passages[$n]['text'])) {
                $sources[] = ['title' => $passages[$n]['title'], 'page' => $passages[$n]['page'], 'quote' => mb_substr($quote, 0, 300)];
            }
        }
        try {
            Guard::noWiringInstructions($answer, 'answer');
        } catch (ValidationException) {
            return ['found' => false, 'answer' => '', 'sources' => []];
        }
        if (! (bool) ($r->json['found'] ?? false) || $answer === '' || $sources === []) {
            return ['found' => false, 'answer' => '', 'sources' => []]; // never an answer without a quoted source
        }

        return ['found' => true, 'answer' => $answer, 'sources' => $sources];
    }

    public function reply(string $ulid, string $reply, User $by): void
    {
        $reply = trim($reply);
        Guard::noWiringInstructions($reply, 'reply');
        if ($reply === '') {
            throw ValidationException::withMessages(['reply' => 'Write a reply.']);
        }
        $db = DB::connection(Connections::cell());
        $q = $db->table('tx_policy_questions')->where('ulid', $ulid)->first() ?? abort(404);
        $db->table('tx_policy_questions')->where('id', $q->id)->update(['status' => 'replied', 'broker_reply' => mb_substr($reply, 0, 3000), 'replied_by' => $by->id, 'replied_at' => now(), 'updated_at' => now()]);
        User::query()->whereKey((int) $q->user_id)->first()?->notify(new PolicyReplyMail((string) $q->question, $reply, $by->name));
        app(AuditLogger::class)->record('transactions.policy.replied', metadata: ['question' => mb_substr((string) $q->question, 0, 200)], severity: 'info');
    }

    /** The broker added the answer to the manual: the gap is closed. */
    public function closeGap(string $ulid, User $by): void
    {
        DB::connection(Connections::cell())->table('tx_policy_questions')->where('ulid', $ulid)->update(['gap_closed_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @return list<array{ulid: string, question: string, answer: string|null, status: string, reply: string|null, sources: list<array<string, mixed>>, at: string, who?: string}>
     */
    public static function questions(?User $only = null, bool $openGapsOnly = false): array
    {
        return array_values(DB::connection(Connections::cell())->table('tx_policy_questions')->when($only !== null, fn ($q) => $q->where('user_id', $only?->id))
            ->when($openGapsOnly, fn ($q) => $q->whereIn('status', ['to_broker', 'replied'])->whereNull('gap_closed_at'))->orderByDesc('id')->limit(50)->get()
            ->map(fn ($q): array => ['ulid' => (string) $q->ulid, 'question' => (string) $q->question, 'answer' => $q->answer, 'status' => (string) $q->status, 'reply' => $q->broker_reply,
                'sources' => array_values((array) json_decode((string) ($q->sources ?? '[]'), true)), 'at' => CarbonImmutable::parse((string) $q->created_at)->toDateString(),
                'who' => (string) (User::query()->whereKey((int) $q->user_id)->first()->name ?? '')])->all());
    }

    /**
     * The passages most likely to answer: the brokerage's documents and the state rule packs, by shared words.
     *
     * @return list<array{title: string, page: int, text: string}>
     */
    public static function search(string $question, int $limit = 6): array
    {
        $words = self::words($question);
        if ($words === []) {
            return [];
        }
        $candidates = DB::connection(Connections::cell())->table('tx_policy_chunks')->join('tx_policy_docs', 'tx_policy_docs.id', '=', 'tx_policy_chunks.doc_id')
            ->where('tx_policy_docs.status', 'active')->get(['tx_policy_chunks.text', 'tx_policy_chunks.page', 'tx_policy_docs.title'])
            ->map(fn ($c): array => ['title' => (string) $c->title, 'page' => (int) $c->page, 'text' => (string) $c->text])->all();
        foreach (StatePacks::summaries() as $state => $text) {
            $candidates[] = ['title' => 'RightAlly state rules: '.$state, 'page' => 1, 'text' => $text];
        }
        $n = max(1, count($candidates));
        $df = [];
        foreach ($candidates as $c) {
            foreach (array_unique(self::words($c['text'])) as $w) {
                $df[$w] = ($df[$w] ?? 0) + 1;
            }
        }
        $scored = [];
        foreach ($candidates as $c) {
            $tf = array_count_values(self::words($c['text']));
            $score = 0.0;
            foreach ($words as $w) {
                if (isset($tf[$w])) {
                    $score += (1 + log($tf[$w])) * log(1 + $n / ($df[$w] ?? 1));
                }
            }
            if ($score > 0) {
                $scored[] = $c + ['score' => $score];
            }
        }
        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(fn (array $c): array => ['title' => $c['title'], 'page' => $c['page'], 'text' => $c['text']], array_slice($scored, 0, $limit));
    }

    /**
     * @return Collection<int, User>
     */
    private static function brokers(): Collection
    {
        return User::query()->whereIn('kind', [UserKind::Owner, UserKind::Staff])->where('status', UserStatus::Active)->get()
            ->filter(fn (User $u): bool => $u->isKind(UserKind::Owner) || $u->can('transactions.review'))->values();
    }

    /**
     * @return list<string>
     */
    private static function words(string $s): array
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($s), $m);

        return array_values(array_map(fn (string $w): string => preg_replace('/(ies|es|s)$/u', '', $w) ?? $w, array_filter($m[0], fn (string $w): bool => ! in_array($w, self::STOP, true))));
    }
}
