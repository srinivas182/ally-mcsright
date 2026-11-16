<?php

declare(strict_types=1);

namespace Modules\Transactions\Portal;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Billing\Entitlements;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Forms\Guard;
use Modules\Transactions\Models\Deal;
use Throwable;

/**
 * The client concierge (specs/ai/02, §22): buyers and sellers ask about their own deal in English or Spanish and get
 * an answer from that deal's facts only, with where it came from. Money, wiring and legal questions never reach the
 * AI: they go straight to the agent, as does anything the concierge is not sure about or the client prefers the
 * agent to answer. The agent's reply is emailed to the client.
 */
final class Concierge
{
    public const FEATURE = 'docs.concierge';

    private const MONEY = '/\b(wire|wiring|routing|bank|account number|send (the )?money|transfer|payment instructions|cuenta|transferencia|banco|enviar (el )?dinero|giro)\b/iu';

    private const LEGAL = '/\b(lawyer|legal advice|sue|lawsuit|tax advice|abogado|demanda|asesor[ií]a legal|impuestos)\b/iu';

    private const SOURCES = ['dates', 'progress', 'requests', 'documents', 'glossary', 'deal'];

    public function __construct(private readonly AiGateway $gateway) {}

    /**
     * @return array{ulid: string, answer: string, status: string, sources: list<string>}
     */
    public function ask(Deal $deal, \stdClass $access, string $question): array
    {
        $question = mb_substr(trim($question), 0, 1000);
        if ($question === '' || ! PortalAccess::isClient((string) $access->role)) {
            throw ValidationException::withMessages(['question' => 'Ask a question.']);
        }
        $locale = Copy::locale((string) $access->locale);
        [$answer, $toAgent, $sources] = match (true) {
            preg_match(self::MONEY, $question) === 1 => [Copy::t($locale, 'concierge.money'), true, []],
            preg_match(self::LEGAL, $question) === 1 => [Copy::t($locale, 'concierge.legal'), true, []],
            default => $this->answer($deal, $access, $question, $locale),
        };
        $ulid = (string) Str::ulid();
        DB::connection(Connections::cell())->table('tx_portal_questions')->insert(['ulid' => $ulid, 'tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id,
            'access_id' => $access->id, 'question' => $question, 'answer' => $answer, 'sources' => json_encode($sources), 'status' => $toAgent ? 'to_agent' : 'answered',
            'created_at' => now(), 'updated_at' => now()]);
        if ($toAgent) {
            $this->tellAgent($deal, $access, $question);
        }

        return ['ulid' => $ulid, 'answer' => $answer, 'status' => $toAgent ? 'to_agent' : 'answered', 'sources' => array_map(fn (string $s): string => Copy::t($locale, 'source.'.$s), $sources)];
    }

    /** The client would rather their agent answered. */
    public function toAgent(Deal $deal, \stdClass $access, string $questionUlid): void
    {
        $db = DB::connection(Connections::cell());
        $q = $db->table('tx_portal_questions')->where('ulid', $questionUlid)->where('access_id', $access->id)->first() ?? abort(404);
        if ($q->status !== 'answered') {
            return;
        }
        $db->table('tx_portal_questions')->where('id', $q->id)->update(['status' => 'to_agent', 'updated_at' => now()]);
        $this->tellAgent($deal, $access, (string) $q->question);
    }

    /** The agent answers; the client is emailed in their language setting (the reply is the agent's own words). */
    public function reply(Deal $deal, string $questionUlid, string $reply, User $by): void
    {
        $reply = trim($reply);
        Guard::noWiringInstructions($reply, 'reply');
        if ($reply === '') {
            throw ValidationException::withMessages(['reply' => 'Write a reply.']);
        }
        $db = DB::connection(Connections::cell());
        $q = $db->table('tx_portal_questions')->where('ulid', $questionUlid)->where('deal_id', $deal->id)->first() ?? abort(404);
        $access = $db->table('tx_portal_access')->where('id', $q->access_id)->whereNull('revoked_at')->first()
            ?? throw ValidationException::withMessages(['reply' => 'They no longer have access to this deal.']);
        $db->table('tx_portal_questions')->where('id', $q->id)->update(['status' => 'replied', 'agent_reply' => mb_substr($reply, 0, 3000), 'replied_at' => now(), 'updated_at' => now()]);
        $locale = Copy::locale((string) $access->locale);
        Notification::route('mail', (string) $access->email)->notify(new PortalMail($locale, Copy::t($locale, 'mail.reply_subject', ['street' => $deal->street]),
            ['"'.$q->question.'"', $reply], url('/my-deal/'.$access->ulid), (string) $by->email));
        app(AuditLogger::class)->record('transactions.portal.replied', $deal, metadata: ['to' => AuditLogger::mask((string) $access->email)], label: $deal->street);
    }

    /**
     * Questions on the deal for the agent: open ones first.
     *
     * @return list<array{ulid: string, who: string, question: string, answer: string|null, status: string, reply: string|null, at: string}>
     */
    public static function forDeal(Deal $deal): array
    {
        return array_values(DB::connection(Connections::cell())->table('tx_portal_questions')->join('tx_portal_access', 'tx_portal_access.id', '=', 'tx_portal_questions.access_id')
            ->where('tx_portal_questions.deal_id', $deal->id)->orderByRaw("case when tx_portal_questions.status = 'to_agent' then 0 else 1 end")->orderByDesc('tx_portal_questions.id')->limit(20)
            ->get(['tx_portal_questions.*', 'tx_portal_access.name'])
            ->map(fn ($q): array => ['ulid' => (string) $q->ulid, 'who' => (string) $q->name, 'question' => (string) $q->question, 'answer' => $q->answer !== null ? (string) $q->answer : null,
                'status' => (string) $q->status, 'reply' => $q->agent_reply !== null ? (string) $q->agent_reply : null, 'at' => CarbonImmutable::parse((string) $q->created_at)->toDateString()])->all());
    }

    /**
     * @return array{0: string, 1: bool, 2: list<string>}
     */
    private function answer(Deal $deal, \stdClass $access, string $question, string $locale): array
    {
        $agent = User::query()->whereKey($deal->agent_user_id)->first();
        if ($agent === null || ! app(Entitlements::class)->allows($agent, 'crm.ai')) {
            return [Copy::t($locale, 'concierge.unknown'), true, []];
        }
        $facts = ['address' => $deal->street.', '.$deal->city.', '.$deal->state_code, 'side' => $access->role, 'status' => $deal->status,
            'financing' => $deal->financing, 'type_of_home' => $deal->property_type,
            'progress' => array_map(fn (array $s): string => $s['key'].': '.$s['state'], PortalView::stages($deal)),
            'dates' => array_map(fn (array $d): string => $d['label'].' '.$d['date'].($d['done'] ? ' (done)' : ''), PortalView::dates($deal, (string) $access->role, 'en')),
            'waiting_on_the_client' => array_column(PortalView::requests($deal, $access), 'what'),
            'documents_shared' => array_column(PortalView::documents($deal, (string) $access->role), 'name')];
        try {
            $r = $this->gateway->run(new AiTask(self::FEATURE, 'transactions.portal_concierge', 'concierge:'.$access->ulid.':'.CarbonImmutable::now()->format('YmdHisv'),
                inputs: ['language' => $locale === 'es' ? 'Spanish' : 'English', 'today' => CarbonImmutable::today()->format('l Y-m-d'), 'facts' => (string) json_encode($facts)],
                untrustedContent: [$question],
                outputSchema: ['type' => 'object', 'required' => ['answer'], 'properties' => ['answer' => ['type' => 'string'], 'to_agent' => ['type' => 'boolean'], 'sources' => ['type' => 'array']]],
                userId: $agent->id));
        } catch (Throwable $e) {
            report($e);

            return [Copy::t($locale, 'concierge.unknown'), true, []];
        }
        $j = (array) $r->json;
        $answer = mb_substr(trim((string) ($j['answer'] ?? '')), 0, 1500);
        $toAgent = (bool) ($j['to_agent'] ?? false);
        try {
            Guard::noWiringInstructions($answer, 'answer');
        } catch (ValidationException) {
            return [Copy::t($locale, 'concierge.money'), true, []];
        }
        if ($answer === '' || preg_match('/\$\s?\d|\d{3,}\s?(dollars|dólares)/iu', $answer) === 1) { // no amounts from the AI
            return [Copy::t($locale, 'concierge.unknown'), true, []];
        }
        $sources = array_values(array_intersect(self::SOURCES, array_map('strval', (array) ($j['sources'] ?? []))));

        return [$toAgent ? $answer.' '.Copy::t($locale, 'sent_to_agent') : $answer, $toAgent, $sources];
    }

    private function tellAgent(Deal $deal, \stdClass $access, string $question): void
    {
        User::query()->whereKey($deal->agent_user_id)->first()?->notify(new PortalQuestionMail((string) $access->name, $deal->street, $question, '/deals/'.$deal->ulid));
    }
}
