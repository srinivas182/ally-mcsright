<?php

declare(strict_types=1);

namespace Modules\Crm;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\AI\Data\RetrievedRecord;
use Modules\AI\Models\AiBatch;
use Modules\AI\Support\JsonShape;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\ContactBrief;
use Modules\Crm\Models\Memory;
use Modules\Crm\Models\Stage;
use Modules\Crm\Models\Task;

/**
 * AI contact briefs (CRM additions). Built only from this contact's own records, only for someone
 * who may see the contact, through the AI gateway (redaction, output policy, credits). Afterwards
 * the rules are enforced in code, not left to the model: the draft channel must be allowed by
 * consent and Do Not Call, and drafts with Fair Housing risk wording are removed.
 */
final class ContactBriefs
{
    public const FEATURE = 'crm.contact_brief';

    private const SCHEMA = [
        'type' => 'object',
        'required' => ['who', 'wants', 'status', 'unknowns', 'next_step', 'next_step_reason', 'draft_channel', 'draft', 'sources'],
        'properties' => [
            'who' => ['type' => 'string'], 'wants' => ['type' => 'string'], 'status' => ['type' => 'string'],
            'unknowns' => ['type' => 'array', 'items' => ['type' => 'string']],
            'next_step' => ['type' => 'string'], 'next_step_reason' => ['type' => 'string'],
            'draft_channel' => ['type' => 'string'], 'draft' => ['type' => 'string'],
            'sources' => ['type' => 'array', 'items' => ['type' => 'string']],
            'personal_touch' => ['type' => 'string'],
        ],
    ];

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly ContactVisibility $visibility,
        private readonly Consent $consent,
        private readonly TenantContext $tenants,
    ) {}

    public function generate(Contact $contact, User $user): ContactBrief
    {
        if (! $this->visibility->canSee($user, $contact)) {
            throw ValidationException::withMessages(['contact' => 'Contact not found.']);
        }

        $result = $this->gateway->run($this->task($contact, $user, 'crm-brief:'.$contact->ulid.':'.CarbonImmutable::now()->format('YmdHisv'), true));

        return $this->store($contact, (array) $result->json, $result->requestUlid);
    }

    /**
     * Nightly refresh at batch price for contacts whose brief is out of date or who had activity
     * in the last day. Results arrive through AiBatchCompleted (see storeBatchResult).
     */
    public function refreshInBatch(int $limit = 500): ?AiBatch
    {
        $since = CarbonImmutable::now()->subDay();
        $contacts = Contact::query()->whereNotNull('owner_user_id')
            ->where(fn ($q) => $q->where('last_activity_at', '>=', $since)
                ->orWhereIn('id', ContactBrief::query()->where('stale', true)->select('contact_id')))
            ->limit($limit)->get();

        $tasks = [];
        foreach ($contacts as $contact) {
            $owner = User::query()->find($contact->owner_user_id);
            if ($owner instanceof User && $owner->isActive()) {
                $tasks[] = $this->task($contact, $owner, 'crm-brief-batch:'.$contact->ulid.':'.$since->format('Ymd'), false);
            }
        }

        return $tasks === [] ? null : $this->gateway->batch($tasks);
    }

    /**
     * Stores one batch answer (plain text holding JSON) after the same checks as a live brief.
     */
    public function storeBatchResult(string $idempotencyKey, string $text): ?ContactBrief
    {
        if (preg_match('/^crm-brief-batch:([0-9A-HJKMNP-TV-Z]{26}):/', $idempotencyKey, $m) !== 1) {
            return null;
        }
        $contact = Contact::query()->where('ulid', $m[1])->first();
        $json = json_decode(trim((string) preg_replace('/^```(?:json)?|```$/m', '', $text)), true);
        if ($contact === null || ! is_array($json) || app(JsonShape::class)->validate($json, self::SCHEMA) !== []) {
            return null;
        }

        return $this->store($contact, $json, null);
    }

    private function task(Contact $contact, User $user, string $key, bool $structured): AiTask
    {
        return new AiTask(
            self::FEATURE,
            'crm.contact_brief',
            $key,
            inputs: [
                'language' => $user->locale === 'es' ? 'Spanish' : 'English',
                'allowed_channels' => implode(' or ', $this->allowedChannels($contact)),
                'agent_name' => $user->first_name,
                'brokerage' => (string) $this->tenants->current()->name,
                'today' => CarbonImmutable::now()->toFormattedDateString(),
            ],
            context: $this->records($contact),
            outputSchema: $structured ? self::SCHEMA : null,
            userId: $user->id,
        );
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function store(Contact $contact, array $json, ?string $requestUlid): ContactBrief
    {
        $content = $this->enforceRules($json, $this->allowedChannels($contact), $this->records($contact));

        return ContactBrief::query()->updateOrCreate(['contact_id' => $contact->id], [
            'content' => $content, 'stale' => false, 'ai_request_ulid' => $requestUlid, 'generated_at' => CarbonImmutable::now(),
        ]);
    }

    public function markStale(int $contactId): void
    {
        ContactBrief::query()->where('contact_id', $contactId)->update(['stale' => true]);
    }

    public function feedback(ContactBrief $brief, bool $helpful): void
    {
        $brief->increment($helpful ? 'helpful' : 'not_helpful');
    }

    /**
     * Channels consent allows, best first.
     *
     * @return list<string>
     */
    public function allowedChannels(Contact $contact): array
    {
        $channels = [];
        if ($this->consent->canText($contact)) {
            $channels[] = 'text';
        }
        if ($contact->primary_email !== null) {
            $channels[] = 'email';
        }
        if ($this->consent->canCall($contact)) {
            $channels[] = 'call';
        }

        return $channels === [] ? ['note'] : $channels;
    }

    /**
     * @return list<RetrievedRecord>
     */
    private function records(Contact $contact): array
    {
        $stage = $contact->stage_id !== null ? Stage::query()->whereKey($contact->stage_id)->value('name') : null;
        $records = [RetrievedRecord::fromModel($contact, [
            'name' => $contact->name,
            'stage' => $contact->lifecycle->value.($stage !== null ? " ({$stage})" : ''),
            'source' => str_replace('_', ' ', $contact->source->value),
            'origin' => $contact->origin->value,
            'city' => $contact->city,
            'tags' => $contact->tags()->pluck('name')->implode(', '),
            'added' => $contact->created_at?->toDateString(),
            'imported' => $contact->import_id !== null ? 'yes' : 'no',
            'last_contact' => $contact->last_activity_at?->toDateString() ?? 'never',
            'texting_consent' => $contact->sms_opt_in ? 'yes' : 'no',
            'do_not_call' => $contact->do_not_call ? 'yes' : 'no',
        ])];

        foreach (Activity::query()->where('contact_id', $contact->id)->where('type', '!=', 'system')->orderByDesc('occurred_at')->limit(30)->get() as $activity) {
            $records[] = RetrievedRecord::fromModel($activity, [
                'type' => $activity->type, 'when' => $activity->occurred_at->toDateString(),
                'outcome' => $activity->outcome, 'text' => mb_substr((string) $activity->body, 0, 2000),
            ]);
        }

        foreach (Memory::query()->where('contact_id', $contact->id)->limit(10)->get() as $memory) {
            $records[] = RetrievedRecord::fromModel($memory, ['personal_detail' => $memory->detail]);
        }

        foreach (Task::query()->where('contact_id', $contact->id)->whereNull('completed_at')->orderBy('due_at')->limit(10)->get() as $task) {
            $records[] = RetrievedRecord::fromModel($task, ['open_task' => $task->title, 'due' => $task->due_at->toDateString()]);
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $brief
     * @param  list<string>  $channels
     * @param  list<RetrievedRecord>  $records
     * @return array<string, mixed>
     */
    private function enforceRules(array $brief, array $channels, array $records): array
    {
        $channel = (string) ($brief['draft_channel'] ?? '');
        $notes = [];

        if (! in_array($channel, $channels, true)) {
            $notes[] = $channel === 'text' ? 'Texting is not allowed for this contact, so no text was drafted.' : 'Draft adjusted to an allowed channel.';
            $brief['draft'] = $channels[0] === 'note' ? '' : (string) ($brief['draft'] ?? '');
            $channel = $channels[0];
        }

        if (FairHousing::flags((string) ($brief['draft'] ?? '')) !== []) {
            $brief['draft'] = '';
            $notes[] = 'The draft was removed because its wording could raise Fair Housing concerns. Please write your own.';
        }

        $known = array_map(fn (RetrievedRecord $r): string => $r->id, $records);
        $brief['sources'] = array_values(array_intersect((array) ($brief['sources'] ?? []), $known));
        $brief['draft_channel'] = $channel;
        $brief['allowed_channels'] = $channels;
        $brief['notes'] = $notes;
        $brief['unknowns'] = array_slice(array_values((array) ($brief['unknowns'] ?? [])), 0, 3);
        $touch = trim((string) ($brief['personal_touch'] ?? ''));
        $brief['personal_touch'] = $touch !== '' && ! app(PersonalMemory::class)->isSensitive($touch) ? mb_substr($touch, 0, 200) : '';

        return $brief;
    }
}
