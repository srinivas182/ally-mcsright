<?php

declare(strict_types=1);

namespace Modules\Crm\Voice;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Crm\Actions\Tasks;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Consent;
use Modules\Crm\Models\Contact;

/**
 * The agent's hand-off note from a call's transcript (VO1, specs/grow/14): a short summary, the next step, when to
 * follow up, and whether they asked for a person or for no more calls. The transcript is untrusted data: anything
 * said on the call that sounds like an instruction is ignored.
 */
final class CallNotes
{
    public const FEATURE = 'crm.call_summary';

    public function __construct(private readonly AiGateway $gateway) {}

    /**
     * @param  'ai_call'|'recorded'  $kind
     * @return array{summary: string, next_step: string, follow_up: string, wants_agent: bool, stop_calls: bool}
     */
    public function write(Contact $contact, string $transcript, string $kind, ?int $userId = null): array
    {
        $result = $this->gateway->run(new AiTask(self::FEATURE, 'crm.call_summary', 'crm-call-summary:'.$contact->ulid.':'.hash('xxh128', $transcript).':'.CarbonImmutable::now()->format('YmdHi'),
            inputs: ['contact_name' => trim($contact->first_name.' '.$contact->last_name), 'kind' => $kind === 'ai_call' ? 'an AI assistant call to a new lead' : 'a recorded call with their agent'],
            untrustedContent: [mb_substr($transcript, 0, 40_000)],
            outputSchema: ['type' => 'object', 'required' => ['summary'], 'properties' => ['summary' => ['type' => 'string']]], userId: $userId));

        return self::clean((array) $result->json);
    }

    /**
     * Puts the notes where the agent works: on the contact's timeline, a follow-up task when one was agreed, Do Not
     * Call when they asked for no more calls, and an email to the agent.
     *
     * @param  array{summary: string, next_step: string, follow_up: string, wants_agent: bool, stop_calls: bool}  $notes
     */
    public function deliver(Contact $contact, array $notes, ?User $agent, string $headline, ?int $seconds = null, bool $logAsCall = false): void
    {
        $body = $notes['summary'].($notes['next_step'] !== '' ? "\nNext step: ".$notes['next_step'] : '');
        if ($logAsCall && $agent !== null) {
            app(Timeline::class)->log($contact, ['type' => 'call', 'outcome' => 'connected', 'subject' => $headline, 'body' => $body,
                'duration_minutes' => $seconds !== null ? (int) ceil($seconds / 60) : null], $agent);
        } else {
            app(Timeline::class)->system($contact, $headline.': '.$body);
        }
        if ($notes['stop_calls'] && ! $contact->do_not_call) {
            app(Consent::class)->setDoNotCall($contact, true, null, ['source' => 'agent', 'ref' => 'call_notes']);
            app(Timeline::class)->system($contact, 'They asked on the call not to be called again. Do Not Call is on.');
        }
        if ($agent === null) {
            return;
        }
        if ($notes['follow_up'] !== 'none' && ! $notes['stop_calls']) {
            app(Tasks::class)->create($contact, ['title' => $notes['next_step'] !== '' ? $notes['next_step'] : 'Call '.$contact->first_name.' back', 'due' => $notes['follow_up']], $agent);
        }
        $agent->notify(new CallNotesMail(trim($contact->first_name.' '.$contact->last_name), $headline, $notes['summary'], $notes['next_step'], url('/contacts/'.$contact->ulid)));
    }

    /**
     * @param  array<mixed>  $j
     * @return array{summary: string, next_step: string, follow_up: string, wants_agent: bool, stop_calls: bool}
     */
    public static function clean(array $j): array
    {
        $follow = (string) ($j['follow_up'] ?? 'none');

        return ['summary' => mb_substr(trim((string) ($j['summary'] ?? '')), 0, 1200), 'next_step' => mb_substr(trim((string) ($j['next_step'] ?? '')), 0, 200),
            'follow_up' => in_array($follow, ['today', 'tomorrow', 'next_week'], true) ? $follow : 'none',
            'wants_agent' => ($j['wants_agent'] ?? false) === true, 'stop_calls' => ($j['stop_calls'] ?? false) === true];
    }
}
