<?php

declare(strict_types=1);

namespace Modules\Learn;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Audit\AuditLogger;
use Modules\Learn\Models\RolePlaySession;

/**
 * The AI role-play academy (LN3, specs/learn/03). The agent practises a real conversation with an AI playing a
 * made-up client, by typing or by speaking (the browser turns speech into text; no audio is sent to us). Then they
 * get feedback against a short checklist that is the same for everyone (listened, asked good questions, handled
 * the concern, booked a next step, stayed within the rules) with what went well, what to try next, and one better
 * line to say. Also:
 *  - a daily drill: one five-minute scenario a day, with a streak;
 *  - rehearsal for a real appointment: the agent describes it in a few words (no client names needed) and the AI
 *    plays that client;
 *  - coaching cards from the agent's own call notes or a deal they just closed.
 * The client is always fictional; the AI never gives legal advice or asks for anyone's personal details.
 */
final class RolePlay
{
    public const TURN = 'learn.roleplay';

    public const FEEDBACK = 'learn.roleplay_feedback';

    public const COACH = 'learn.coach';

    public const MAX_TURNS = 16;

    /** key => [title, skill, who the client is and what they want, the client's first line, what the agent should achieve] */
    public const SCENARIOS = [
        'listing_commission' => ['Seller wants a lower commission', 'listings',
            'A homeowner in their 60s selling the family home. Two other agents offered to list for less. Polite but firm, asks "why should I pay you more?"',
            'Thanks for coming. I have to be honest, another agent said she would do it for one percent less. Why would I pay you more?',
            'Show the value of the service with specifics, ask what matters most to them, and agree a next step without running down other agents.'],
        'expired' => ['Expired listing owner', 'prospecting',
            'An owner whose listing just expired after 120 days without an offer. Frustrated with agents and getting many calls.',
            'Let me guess, you saw my listing expired. You are the fifth agent to call today.',
            'Acknowledge the frustration, ask why they think it did not sell, and offer a short meeting to look at a new plan.'],
        'fsbo' => ['For sale by owner', 'prospecting',
            'A couple selling their house themselves to save the commission. Confident, a little suspicious of agents.',
            'We are selling it ourselves, thanks. We do not need an agent.',
            'Respect their choice, offer real help (pricing, paperwork, safety), and ask permission to stay in touch.'],
        'first_time_buyer' => ['Nervous first-time buyer', 'buyers',
            'A first-time buyer in their late 20s, worried about interest rates and not sure they can afford it.',
            'I really want to buy, but with rates where they are I am scared I am making a huge mistake.',
            'Listen, avoid promises about rates or prices, suggest speaking with a lender, and set up a buyer consultation.'],
        'buyer_agreement' => ['Buyer will not sign the agreement', 'buyers',
            'A buyer who wants to tour a home this weekend but does not want to sign a written buyer agreement.',
            'Can we just go see the house on Saturday? I do not want to sign anything yet.',
            'Explain simply that a written agreement is needed before touring, what it covers, that terms are negotiable, and keep them comfortable.'],
        'open_house' => ['Open house visitor', 'prospecting',
            'A visitor at an open house who lives nearby and says they are "just looking".',
            'Just looking, I live around the corner. Nice kitchen though.',
            'Start a friendly conversation, find out their plans without pressure, and offer something useful (a home value or market update).'],
        'price_reduction' => ['Asking a seller to lower the price', 'listings',
            'A seller whose home has had showings for six weeks but no offers. Emotionally attached to the price.',
            'I know what you are going to say. But I am not giving my house away.',
            'Use the showing feedback and recent sales calmly, let them decide, and agree what happens if nothing changes in two weeks.'],
        'inspection_repairs' => ['Upset buyer after the inspection', 'contracts',
            'A buyer upset after the home inspection found an old roof and some electrical issues.',
            'The inspection was a disaster. Should we just walk away? I feel like the seller hid this.',
            'Calm them, go through options (ask for repairs, a credit, or end the contract within the deadline), and point them to the inspector and the contract dates.'],
    ];

    public const RUBRIC = ['listened' => 'Listened and showed you understood', 'questions' => 'Asked good questions', 'concern' => 'Handled the main concern',
        'next_step' => 'Agreed a clear next step', 'rules' => 'Stayed within the rules (no promises, no fair housing issues, no legal advice)'];

    private const CLIENT_SCHEMA = ['type' => 'object', 'required' => ['reply'], 'properties' => ['reply' => ['type' => 'string'], 'ended' => ['type' => 'boolean']]];

    private const FEEDBACK_SCHEMA = ['type' => 'object', 'required' => ['rubric', 'went_well', 'try_next', 'better_line'], 'properties' => [
        'rubric' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['item', 'met'], 'properties' => ['item' => ['type' => 'string'], 'met' => ['type' => 'boolean'],
            'note' => ['type' => 'string']]]],
        'went_well' => ['type' => 'array', 'items' => ['type' => 'string']], 'try_next' => ['type' => 'array', 'items' => ['type' => 'string']], 'better_line' => ['type' => 'string'],
    ]];

    private const COACH_SCHEMA = ['type' => 'object', 'required' => ['went_well', 'try_next', 'next_step'], 'properties' => [
        'went_well' => ['type' => 'array', 'items' => ['type' => 'string']], 'try_next' => ['type' => 'array', 'items' => ['type' => 'string']],
        'next_step' => ['type' => 'string'], 'lesson' => ['type' => 'string'],
    ]];

    public function __construct(private readonly AiGateway $gateway) {}

    /** Starts a practice, a daily drill, or a rehearsal of a real appointment described by the agent. */
    public function start(User $user, string $kind, ?string $scenario, ?string $brief = null): RolePlaySession
    {
        if ($kind === 'daily') {
            $scenario = self::todays($user);
        }
        if ($kind === 'rehearsal') {
            $brief = trim(strip_tags((string) $brief));
            if (mb_strlen($brief) < 15) {
                throw ValidationException::withMessages(['brief' => 'Describe the appointment in a sentence or two, for example "Listing appointment with a retired couple who think their home is worth more than the comps".']);
            }
            $first = 'Hi, thanks for meeting with me. So, where do we start?';
            $skill = null;
        } else {
            if (! in_array($kind, ['practice', 'daily'], true) || ! isset(self::SCENARIOS[(string) $scenario])) {
                throw ValidationException::withMessages(['scenario' => 'Choose a conversation to practise.']);
            }
            [, $skill, , $first] = self::SCENARIOS[(string) $scenario];
            $brief = null;
        }

        return RolePlaySession::query()->create(['user_id' => $user->id, 'kind' => $kind, 'scenario' => $kind === 'rehearsal' ? null : $scenario, 'skill' => $skill,
            'brief' => $brief !== null ? mb_substr($brief, 0, 1000) : null, 'transcript' => [['who' => 'client', 'text' => $first]], 'turns' => 0]);
    }

    /** The agent says something; the AI client answers in character. */
    public function reply(RolePlaySession $s, string $text, User $user): RolePlaySession
    {
        self::own($s, $user);
        $text = trim($text);
        if ($s->finished_at !== null || $s->turns >= self::MAX_TURNS) {
            throw ValidationException::withMessages(['text' => 'This conversation is over. Press "Finish and get feedback".']);
        }
        if ($text === '') {
            throw ValidationException::withMessages(['text' => 'Say or type what you would say.']);
        }
        $transcript = (array) $s->transcript;
        $transcript[] = ['who' => 'agent', 'text' => mb_substr($text, 0, 1500)];
        $result = $this->gateway->run(new AiTask(self::TURN, 'learn.roleplay_client', 'roleplay:'.$s->ulid.':'.count($transcript),
            inputs: ['client' => $this->who($s), 'goal' => $this->goal($s)], untrustedContent: [self::script($transcript)], outputSchema: self::CLIENT_SCHEMA, userId: $user->id));
        $answer = trim((string) ($result->json['reply'] ?? ''));
        $transcript[] = ['who' => 'client', 'text' => mb_substr($answer !== '' ? $answer : 'Sorry, could you say that again?', 0, 1000)];
        $s->update(['transcript' => $transcript, 'turns' => $s->turns + 1]);

        return $s;
    }

    /** Feedback against the same checklist for everyone, with a score out of 100. */
    public function finish(RolePlaySession $s, User $user): RolePlaySession
    {
        self::own($s, $user);
        if ($s->finished_at !== null) {
            return $s;
        }
        if ($s->turns < 2) {
            throw ValidationException::withMessages(['text' => 'Say at least two things before asking for feedback.']);
        }
        $result = $this->gateway->run(new AiTask(self::FEEDBACK, 'learn.roleplay_feedback', 'roleplay-feedback:'.$s->ulid,
            inputs: ['client' => $this->who($s), 'goal' => $this->goal($s), 'rubric' => implode('; ', array_map(fn ($k, $v): string => "{$k}: {$v}", array_keys(self::RUBRIC), self::RUBRIC))],
            untrustedContent: [self::script((array) $s->transcript)], outputSchema: self::FEEDBACK_SCHEMA, userId: $user->id));
        $json = (array) $result->json;
        $given = [];
        foreach ((array) ($json['rubric'] ?? []) as $r) {
            $given[(string) ($r['item'] ?? '')] = ['met' => (bool) ($r['met'] ?? false), 'note' => mb_substr(trim((string) ($r['note'] ?? '')), 0, 300)];
        }
        // The checklist is ours: every item is listed, in our order, and the score is counted in code.
        $rubric = [];
        foreach (self::RUBRIC as $key => $label) {
            $rubric[] = ['item' => $key, 'label' => $label, 'met' => $given[$key]['met'] ?? false, 'note' => $given[$key]['note'] ?? ''];
        }
        $score = (int) round(count(array_filter($rubric, fn (array $r): bool => $r['met'])) * 100 / count(self::RUBRIC));
        $lines = fn (mixed $list): array => array_values(array_filter(array_map(fn ($l): string => mb_substr(trim((string) $l), 0, 300), array_slice((array) $list, 0, 4)),
            fn (string $l): bool => $l !== ''));
        $s->update(['score' => $score, 'finished_at' => CarbonImmutable::now(), 'feedback' => ['rubric' => $rubric, 'went_well' => $lines($json['went_well'] ?? []),
            'try_next' => $lines($json['try_next'] ?? []), 'better_line' => mb_substr(trim((string) ($json['better_line'] ?? '')), 0, 400)]]);
        app(AuditLogger::class)->record('learn.roleplay.finished', metadata: ['kind' => $s->kind, 'scenario' => $s->scenario, 'score' => $score]);

        return $s;
    }

    /** A coaching card from the agent's own call notes, or a debrief of a deal they just finished. */
    public function coach(User $user, string $kind, string $text): RolePlaySession
    {
        if (! in_array($kind, ['call', 'debrief'], true)) {
            abort(404);
        }
        $text = trim($text);
        if (mb_strlen($text) < 40) {
            throw ValidationException::withMessages(['text' => $kind === 'call' ? 'Write a few lines about how the call went.' : 'Answer the questions in a few lines.']);
        }
        $s = RolePlaySession::query()->create(['user_id' => $user->id, 'kind' => $kind, 'transcript' => [['who' => 'agent', 'text' => mb_substr($text, 0, 8000)]], 'turns' => 1]);
        $result = $this->gateway->run(new AiTask(self::COACH, 'learn.coach', 'coach:'.$s->ulid, inputs: ['kind' => $kind === 'call' ? 'a sales call' : 'a deal that just closed'],
            untrustedContent: [mb_substr($text, 0, 8000)], outputSchema: self::COACH_SCHEMA, userId: $user->id));
        $json = (array) $result->json;
        $lines = fn (mixed $list): array => array_values(array_filter(array_map(fn ($l): string => mb_substr(trim((string) $l), 0, 300), array_slice((array) $list, 0, 4)),
            fn (string $l): bool => $l !== ''));
        $s->update(['finished_at' => CarbonImmutable::now(), 'feedback' => ['went_well' => $lines($json['went_well'] ?? []), 'try_next' => $lines($json['try_next'] ?? []),
            'next_step' => mb_substr(trim((string) ($json['next_step'] ?? '')), 0, 300), 'lesson' => mb_substr(trim((string) ($json['lesson'] ?? '')), 0, 400)]]);

        return $s;
    }

    /** Today's drill for this person: the same all day, different from day to day. */
    public static function todays(User $user, ?CarbonImmutable $day = null): string
    {
        $keys = array_keys(self::SCENARIOS);

        return $keys[crc32(($day ?? CarbonImmutable::today())->toDateString().':'.$user->id) % count($keys)];
    }

    /** Days in a row (ending today or yesterday) with a finished daily drill. */
    public static function streak(User $user): int
    {
        $days = RolePlaySession::query()->where('user_id', $user->id)->where('kind', 'daily')->whereNotNull('finished_at')->orderByDesc('finished_at')->limit(400)
            ->get()->map(fn (RolePlaySession $s): string => (string) $s->finished_at?->toDateString())->unique()->values()->all();
        $day = CarbonImmutable::today();
        if (! in_array($day->toDateString(), $days, true)) {
            $day = $day->subDay();
        }
        $n = 0;
        while (in_array($day->toDateString(), $days, true)) {
            $n++;
            $day = $day->subDay();
        }

        return $n;
    }

    /**
     * Average practice score per skill, for the scorecard.
     *
     * @return array<string, int>
     */
    public static function practiceScores(int $userId): array
    {
        $out = [];
        foreach (RolePlaySession::query()->where('user_id', $userId)->whereNotNull('score')->whereNotNull('skill')->get()->groupBy('skill') as $skill => $rows) {
            $out[(string) $skill] = (int) round((float) $rows->avg('score'));
        }

        return $out;
    }

    private function who(RolePlaySession $s): string
    {
        return $s->scenario !== null ? self::SCENARIOS[$s->scenario][2] : 'The client in this appointment, as the agent described it: '.(string) $s->brief;
    }

    private function goal(RolePlaySession $s): string
    {
        return $s->scenario !== null ? self::SCENARIOS[$s->scenario][4] : 'Run the appointment well: understand the client, handle their concerns, and agree the next step.';
    }

    /** @param list<array{who: string, text: string}>|array<int, mixed> $transcript */
    private static function script(array $transcript): string
    {
        return implode("\n", array_map(fn ($t): string => (($t['who'] ?? '') === 'agent' ? 'AGENT: ' : 'CLIENT: ').(string) ($t['text'] ?? ''), $transcript));
    }

    private static function own(RolePlaySession $s, User $user): void
    {
        abort_unless($s->user_id === $user->id, 404);
    }
}
