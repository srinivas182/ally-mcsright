<?php

declare(strict_types=1);

namespace Modules\Crm;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Tags;
use Modules\Crm\Actions\Tasks;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;

/**
 * Speak or type an update; the AI proposes a note, call outcome, stage, tags and follow-up task;
 * the agent edits and confirms; only then is anything changed. Also parses spoken quick adds.
 */
final class VoiceUpdates
{
    public const TRANSCRIBE = 'crm.voice';

    public const LOG = 'crm.voice_log';

    public const QUICK_ADD = 'crm.quick_add';

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly Timeline $timeline,
        private readonly Tasks $tasks,
        private readonly Tags $tags,
        private readonly ManageContacts $contacts,
        private readonly ContactVisibility $visibility,
    ) {}

    /**
     * @return array{note: string, call_outcome: string|null, lifecycle: string|null, tags: list<string>, task_title: string, task_due: string|null}
     */
    public function propose(Contact $contact, string $text, User $user): array
    {
        $result = $this->gateway->run(new AiTask(self::LOG, 'crm.voice_log', 'crm-voice-log:'.$contact->ulid.':'.CarbonImmutable::now()->format('YmdHisv'),
            inputs: ['contact_name' => $contact->name, 'text' => mb_substr(trim($text), 0, 4000)],
            outputSchema: ['type' => 'object', 'required' => ['note'], 'properties' => ['note' => ['type' => 'string']]], userId: $user->id));

        return self::clean((array) $result->json);
    }

    /**
     * @param  array<string, mixed>  $proposal  as reviewed (and possibly edited) by the agent
     */
    public function apply(Contact $contact, array $proposal, User $user): void
    {
        $p = self::clean($proposal);

        DB::transaction(function () use ($contact, $p, $user): void {
            if ($p['call_outcome'] !== null) {
                $this->timeline->log($contact, ['type' => 'call', 'outcome' => $p['call_outcome'], 'body' => $p['note']], $user);
            } elseif ($p['note'] !== '') {
                $this->timeline->log($contact, ['type' => 'note', 'body' => $p['note']], $user);
            }
            if ($p['lifecycle'] !== null && $this->visibility->canManage($user, $contact)) {
                $this->contacts->update($contact, ['lifecycle' => $p['lifecycle']], $user);
            }
            foreach ($p['tags'] as $tag) {
                $this->tags->attach($contact, $tag);
            }
            if ($p['task_title'] !== '') {
                $this->tasks->create($contact, ['title' => $p['task_title'], 'due' => $p['task_due'] ?? 'tomorrow'], $user);
            }
        });
    }

    /**
     * @return array{first_name: string, last_name: string, email: string, phone: string, source: string, note: string}
     */
    public function parseQuickAdd(string $text, User $user): array
    {
        $result = $this->gateway->run(new AiTask(self::QUICK_ADD, 'crm.quick_add', 'crm-quick-add:'.$user->id.':'.CarbonImmutable::now()->format('YmdHisv'),
            inputs: ['text' => mb_substr(trim($text), 0, 1000)],
            outputSchema: ['type' => 'object', 'required' => ['first_name'], 'properties' => ['first_name' => ['type' => 'string']]], userId: $user->id));
        $json = (array) $result->json;

        return [
            'first_name' => mb_substr(trim((string) ($json['first_name'] ?? '')), 0, 80),
            'last_name' => mb_substr(trim((string) ($json['last_name'] ?? '')), 0, 80),
            'email' => mb_substr(trim((string) ($json['email'] ?? '')), 0, 254),
            'phone' => mb_substr(trim((string) ($json['phone'] ?? '')), 0, 30),
            'source' => in_array($json['source'] ?? '', ['open_house', 'referral', 'sphere', 'website', 'other', 'manual'], true) ? (string) $json['source'] : 'manual',
            'note' => mb_substr(trim((string) ($json['note'] ?? '')), 0, 2000),
        ];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array{note: string, call_outcome: string|null, lifecycle: string|null, tags: list<string>, task_title: string, task_due: string|null}
     */
    private static function clean(array $p): array
    {
        return [
            'note' => mb_substr(trim((string) ($p['note'] ?? '')), 0, 5000),
            'call_outcome' => in_array($p['call_outcome'] ?? null, Activity::CALL_OUTCOMES, true) ? (string) $p['call_outcome'] : null,
            'lifecycle' => in_array($p['lifecycle'] ?? null, ['lead', 'active', 'under_contract', 'past_client', 'sphere'], true) ? (string) $p['lifecycle'] : null,
            'tags' => array_values(array_filter(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 40), array_slice((array) ($p['tags'] ?? []), 0, 5)))),
            'task_title' => mb_substr(trim((string) ($p['task_title'] ?? '')), 0, 200),
            'task_due' => in_array($p['task_due'] ?? null, ['today', 'tomorrow', 'next_week'], true) ? (string) $p['task_due'] : null,
        ];
    }
}
