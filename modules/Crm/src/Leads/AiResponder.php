<?php

declare(strict_types=1);

namespace Modules\Crm\Leads;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Consent;
use Modules\Crm\CrmAccess;
use Modules\Crm\FairHousing;
use Modules\Crm\Inbox\Inbox;
use Modules\Crm\Inbox\SendGuard;
use Modules\Crm\Jobs\RespondToLead;
use Modules\Crm\Models\AiThread;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\LeadSource;
use Modules\Crm\Models\Message;

/**
 * AI first responder (specs/grow/06): answers every new lead within a minute, qualifies them, and hands them to a
 * person the moment they ask for one, raise money or legal questions, sound unhappy, or are ready. It says it is
 * an AI assistant, texts only with written consent (else emails), passes every send through the inbox checks,
 * and steps back for good as soon as the agent writes.
 */
final class AiResponder
{
    public const FEATURE = 'crm.ai_responder';

    public const QUALIFY = ['intent', 'timeline', 'area', 'budget', 'preapproved', 'has_agent', 'showing'];

    /** Words that always mean "a person, please". */
    private const HUMAN = '/\b(real person|human|talk to (an? )?(agent|someone|person)|speak (to|with)|call me|phone me|not a (bot|robot)|are you (a )?(bot|robot|ai))\b/i';

    public function __construct(private readonly TenantContext $tenants, private readonly Timeline $timeline) {}

    /**
     * @return array{enabled: bool, nurture_enabled: bool, assistant_name: string, tone: string, max_messages: int, sources: list<string>|null, knowledge: string}
     */
    public function settings(): array
    {
        $r = DB::connection(Connections::cell())->table('crm_ai_responder_settings')->where('tenant_id', $this->tenants->id())->first();

        return ['enabled' => (bool) ($r->enabled ?? false), 'nurture_enabled' => (bool) ($r->nurture_enabled ?? false), 'assistant_name' => (string) ($r->assistant_name ?? 'Ally'), 'tone' => (string) ($r->tone ?? 'friendly'),
            'max_messages' => (int) ($r->max_messages ?? 6), 'sources' => isset($r->sources) ? array_values((array) json_decode((string) $r->sources, true)) : null,
            'knowledge' => (string) ($r->knowledge ?? '')];
    }

    /**
     * @param  array<string, mixed>  $in
     */
    public function saveSettings(array $in, User $by): void
    {
        if (! CrmAccess::manager($by)) {
            throw ValidationException::withMessages(['ai' => 'Only owners and staff can change the AI assistant.']);
        }
        $name = trim(strip_tags((string) ($in['assistant_name'] ?? 'Ally')));
        $max = (int) ($in['max_messages'] ?? 6);
        $knowledge = trim(strip_tags((string) ($in['knowledge'] ?? '')));
        $errors = [];
        if ($name === '' || mb_strlen($name) > 40) {
            $errors['assistant_name'] = 'Give the assistant a short name.';
        }
        if ($max < 1 || $max > 20) {
            $errors['max_messages'] = 'Allow between 1 and 20 messages per lead.';
        }
        if (mb_strlen($knowledge) > 5000) {
            $errors['knowledge'] = 'Keep the brokerage facts under 5,000 characters.';
        } elseif (($flags = FairHousing::flags($knowledge)) !== []) {
            $errors['knowledge'] = 'Fair Housing: reword "'.implode('", "', $flags).'".';
        }
        $sources = isset($in['sources']) && is_array($in['sources']) ? array_values(array_intersect(array_map('strval', $in['sources']), LeadSource::query()->pluck('ulid')->all())) : null;
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        DB::connection(Connections::cell())->table('crm_ai_responder_settings')->updateOrInsert(['tenant_id' => $this->tenants->id()], [
            'enabled' => (bool) ($in['enabled'] ?? false), 'nurture_enabled' => (bool) ($in['nurture_enabled'] ?? false), 'assistant_name' => $name, 'tone' => ($in['tone'] ?? '') === 'professional' ? 'professional' : 'friendly',
            'max_messages' => $max, 'sources' => $sources !== null ? json_encode($sources) : null, 'knowledge' => $knowledge !== '' ? $knowledge : null,
            'updated_at' => now(), 'created_at' => now(),
        ]);
        app(AuditLogger::class)->record('crm.ai_responder.saved', metadata: ['enabled' => (bool) ($in['enabled'] ?? false), 'nurture' => (bool) ($in['nurture_enabled'] ?? false), 'max_messages' => $max], severity: 'notice');
    }

    /**
     * A new lead was routed: queue the first reply (the job runs within seconds).
     *
     * $handoff (from the website assistant, specs/websites/14): skip (they booked a time, the booking confirms it),
     * origin "website_chat" (the first text picks up the chat) and assistant (the name they were chatting with).
     *
     * @param  array{skip?: bool, origin?: string, assistant?: string}  $handoff
     */
    public function leadReceived(Lead $lead, array $handoff = []): void
    {
        $s = $this->settings();
        $contact = $lead->contact;
        if (($handoff['skip'] ?? false) || ! $s['enabled'] || $contact === null || ! $lead->new_contact || $lead->status === 'pond' || $lead->source === 'sign') {
            return; // returning contacts have a relationship with their agent: a person answers
        }
        $source = $lead->source_id !== null ? LeadSource::query()->find($lead->source_id) : null;
        if ($s['sources'] !== null && ($source === null || ! in_array($source->ulid, $s['sources'], true))) {
            return;
        }
        $name = mb_substr(trim(strip_tags((string) ($handoff['assistant'] ?? ''))), 0, 40);
        AiThread::query()->firstOrCreate(['lead_id' => $lead->id], ['contact_id' => $contact->id, 'status' => 'active',
            'origin' => ($handoff['origin'] ?? null) === 'website_chat' ? 'website_chat' : null, 'assistant_name' => $name !== '' ? $name : null]);
        RespondToLead::dispatch($lead->id);
    }

    /** Their reply arrived. True when the assistant is handling it. */
    public function onReply(Contact $contact, string $channel): bool
    {
        $thread = AiThread::query()->where('contact_id', $contact->id)->where('status', 'active')->latest('id')->first();
        if ($thread === null) {
            return false;
        }
        $thread->forceFill(['last_reply_at' => CarbonImmutable::now(), 'channel' => $thread->channel ?? $channel])->save();
        RespondToLead::dispatch($thread->lead_id);

        return true;
    }

    public function stop(Contact $contact, string $reason): void
    {
        foreach (AiThread::query()->where('contact_id', $contact->id)->where('status', 'active')->get() as $thread) {
            $thread->forceFill(['status' => $reason === 'agent_replied' ? 'handed_off' : 'stopped', 'handoff_reason' => $reason, 'handed_off_at' => CarbonImmutable::now()])->save();
            $this->timeline->system($contact, $reason === 'agent_replied' ? 'AI assistant stepped back: the agent is talking to them' : 'AI assistant stopped ('.str_replace('_', ' ', $reason).')');
        }
    }

    /** The job: write and send the next message, or hand over. */
    public function respond(int $leadId): void
    {
        $thread = AiThread::query()->where('lead_id', $leadId)->with(['lead', 'contact'])->first();
        if ($thread === null || $thread->status !== 'active') {
            return;
        }
        $s = $this->settings();
        $s['assistant_name'] = $thread->assistant_name ?? $s['assistant_name'];
        $lead = $thread->lead;
        $contact = $thread->contact->refresh();
        $agent = $lead->assigned_user_id !== null ? User::query()->find($lead->assigned_user_id) : ($contact->owner_user_id !== null ? User::query()->find($contact->owner_user_id) : null);
        $history = $this->history($contact);
        $last = Message::query()->where('contact_id', $contact->id)->where('direction', 'in')->latest('id')->value('body');

        if (is_string($last) && preg_match(self::HUMAN, $last) === 1) {
            $this->handOff($thread, $agent, 'asked_for_person', null, 'I\'ll get '.($agent->first_name ?? 'an agent').' for you right away.');

            return;
        }
        if ($thread->messages_sent >= $s['max_messages']) {
            $this->handOff($thread, $agent, 'message_limit', null, null);

            return;
        }

        $channel = $this->channel($contact, $thread);
        if ($channel === null) {
            // Nothing can be sent right now (night where they are, or no consent and no email): a person follows up.
            $reasons = array_merge($contact->primary_phone !== null ? app(SendGuard::class)->check($contact, 'sms', 'x') : [], $contact->primary_email !== null ? [] : ['No email address.']);
            $quiet = $contact->primary_phone !== null ? SendGuard::quietHours($contact->primary_phone) : null;
            if ($quiet !== null && $contact->primary_email === null && config('queue.default') !== 'sync') {
                // Night where they are: try again in half an hour (the clock reaches 8am their time).
                RespondToLead::dispatch($leadId)->delay(CarbonImmutable::now()->addMinutes(30));

                return;
            }
            $this->handOff($thread, $agent, 'cannot_message', null, null, implode(' ', $reasons));

            return;
        }

        try {
            $result = app(AiGateway::class)->run(new AiTask(self::FEATURE, 'crm.ai_responder', 'crm-ai-responder:'.$thread->ulid.':'.$thread->messages_sent.':'.Message::query()->where('contact_id', $contact->id)->count(),
                inputs: ['assistant' => $s['assistant_name'], 'agent' => $agent->first_name ?? 'our team', 'brokerage' => $this->tenants->current()->name,
                    'tone' => $s['tone'], 'channel' => $channel === 'sms' ? 'text message' : 'email', 'first_message' => $thread->messages_sent === 0 ? 'yes' : 'no',
                    'continuing' => $thread->origin === 'website_chat' ? 'yes: they were just chatting with you on the website (their inquiry holds the chat); pick up where it left off' : 'no',
                    'first_name' => $contact->first_name, 'interest' => SpeedToLead::summary($lead), 'known' => (string) json_encode($thread->qualification ?? []),
                    'facts' => $s['knowledge'] !== '' ? $s['knowledge'] : 'none provided'],
                untrustedContent: $history !== [] ? $history : ['(they have not written yet; their inquiry: '.($lead->message ?? 'none').')'],
                outputSchema: ['type' => 'object', 'required' => ['reply', 'handoff'], 'properties' => ['reply' => ['type' => 'string'], 'handoff' => ['type' => 'boolean']]],
                userId: $agent?->id));
        } catch (\Throwable $e) {
            report($e);
            $this->handOff($thread, $agent, 'ai_unavailable', null, null);

            return;
        }
        $json = (array) $result->json;
        $reply = trim((string) ($json['reply'] ?? ''));
        $known = array_filter(array_merge($thread->qualification ?? [], array_intersect_key(array_map(static fn ($v): string => mb_substr(trim((string) $v), 0, 120), (array) ($json['qualification'] ?? [])), array_flip(self::QUALIFY))), static fn (string $v): bool => $v !== '');
        $thread->forceFill(['qualification' => $known, 'summary' => mb_substr(trim((string) ($json['summary'] ?? $thread->summary ?? '')), 0, 1000) ?: null,
            'next_message' => mb_substr(trim((string) ($json['agent_next_message'] ?? '')), 0, 600) ?: $thread->next_message])->save();

        $reason = (bool) ($json['handoff'] ?? false) ? (in_array($json['handoff_reason'] ?? '', ['qualified', 'asked_for_person', 'negotiation', 'legal_or_financial', 'unhappy', 'other'], true) ? (string) $json['handoff_reason'] : 'other') : null;
        if ($reply === '' || FairHousing::flags($reply) !== []) {
            $this->handOff($thread, $agent, $reply === '' ? ($reason ?? 'other') : 'fair_housing_check', null, null);

            return;
        }
        $reply = $this->disclose($reply, $thread, $s['assistant_name'], $agent, $channel);
        $blocked = [];
        $sent = app(Inbox::class)->sendAutomated($contact, $channel, $reply, $channel === 'email' ? 'Your inquiry'.($lead->property !== null ? ' about '.$lead->property : '') : null,
            $s['assistant_name'].' for '.($agent->name ?? $this->tenants->current()->name), $blocked);
        if ($sent === null) {
            $this->handOff($thread, $agent, 'cannot_message', null, null, implode(' ', $blocked));

            return;
        }
        $thread->forceFill(['messages_sent' => $thread->messages_sent + 1, 'channel' => $channel])->save();
        app(LeadScoring::class)->rescoreContact($contact->id);
        if ($reason !== null) {
            $this->handOff($thread, $agent, $reason, null, null);
        }
    }

    /**
     * @return array{status: string, reason: string|null, summary: string|null, next_message: string|null, qualification: array<string, string>, messages: int}|null
     */
    public function brief(Contact $contact): ?array
    {
        $t = AiThread::query()->where('contact_id', $contact->id)->latest('id')->first();

        return $t === null ? null : ['status' => $t->status, 'reason' => $t->handoff_reason, 'summary' => $t->summary, 'next_message' => $t->next_message,
            'qualification' => $t->qualification ?? [], 'messages' => $t->messages_sent];
    }

    private function handOff(AiThread $thread, ?User $agent, string $reason, ?string $summary, ?string $goodbye, string $why = ''): void
    {
        if ($goodbye !== null) {
            $channel = $this->channel($thread->contact, $thread);
            if ($channel !== null) {
                $blocked = [];
                app(Inbox::class)->sendAutomated($thread->contact, $channel, $goodbye, $channel === 'email' ? 'Your inquiry' : null, $this->settings()['assistant_name'], $blocked);
            }
        }
        $thread->forceFill(['status' => 'handed_off', 'handoff_reason' => $reason, 'handed_off_at' => CarbonImmutable::now(), 'summary' => $summary ?? $thread->summary])->save();
        $labels = ['qualified' => 'ready to talk', 'asked_for_person' => 'asked for a person', 'negotiation' => 'asked about price or terms', 'legal_or_financial' => 'asked a legal or financial question',
            'unhappy' => 'sounded unhappy', 'message_limit' => 'reached the message limit', 'cannot_message' => 'cannot be messaged automatically', 'ai_unavailable' => 'the AI assistant was unavailable',
            'fair_housing_check' => 'a reply needed a person to word it', 'other' => 'needs a person'];
        $label = $labels[$reason] ?? 'needs a person';
        $contact = $thread->contact;
        $this->timeline->system($contact, "AI assistant handed over: {$label}".($why !== '' ? " ({$why})" : ''));
        // The agent now owes them a reply: the missed-reply nudge applies.
        $conversation = Conversation::query()->where('contact_id', $contact->id)->first();
        $conversation?->forceFill(['needs_reply_since' => CarbonImmutable::now(), 'nudged_at' => null, 'escalated_at' => null])->save();
        if ($agent !== null) {
            $q = $thread->qualification ?? [];
            $line = trim(($thread->summary ?? SpeedToLead::summary($thread->lead)).($q !== [] ? ' — '.implode('; ', array_map(static fn ($k, $v): string => str_replace('_', ' ', $k).": {$v}", array_keys($q), $q)) : ''));
            $agent->notify(new LeadAlert("{$contact->name}: {$label}", $line, app(SpeedToLead::class)->link('/inbox?c='.(app(Inbox::class)->conversationFor($contact)->ulid)), 'Take over'));
        }
    }

    private function channel(Contact $contact, AiThread $thread): ?string
    {
        $guard = app(SendGuard::class);
        $sms = $contact->primary_phone !== null && app(Consent::class)->canAutoText($contact) && $guard->check($contact, 'sms', 'x') === [];
        $email = $contact->primary_email !== null && $guard->check($contact, 'email', 'x') === [];
        if ($thread->channel === 'email' && $email) {
            return 'email';
        }

        return $sms ? 'sms' : ($email ? 'email' : null);
    }

    private function disclose(string $reply, AiThread $thread, string $name, ?User $agent, string $channel): string
    {
        if ($thread->messages_sent === 0 && stripos($reply, 'AI') === false && stripos($reply, 'assistant') === false) {
            $reply = $thread->origin === 'website_chat'
                ? "Hi, it's {$name}, the AI assistant from the ".$this->tenants->current()->name.' website chat. '.$reply
                : "Hi, this is {$name}, ".($agent !== null ? "{$agent->first_name}'s" : 'the').' AI assistant at '.$this->tenants->current()->name.'. '.$reply;
        }

        return mb_substr($reply, 0, $channel === 'sms' ? 480 : 4000);
    }

    /**
     * @return list<string>
     */
    private function history(Contact $contact): array
    {
        return array_values(Message::query()->where('contact_id', $contact->id)->latest('id')->limit(14)->get()->reverse()
            ->map(fn (Message $m): string => ($m->direction === 'in' ? $contact->first_name : ($m->user_id === null ? 'Assistant' : 'Agent')).': '.mb_substr($m->body, 0, 1000))->all());
    }
}
