<?php

declare(strict_types=1);

namespace Modules\Crm\Inbox;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Messaging\MessagingSettings;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Consent;
use Modules\Crm\Events\ActivityLogged;
use Modules\Crm\FairHousing;
use Modules\Crm\Leads\LeadAlert;
use Modules\Crm\Leads\PortalEmail;
use Modules\Crm\Leads\SpeedToLead;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Conversation;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Message;

/**
 * The unified inbox (specs/grow/05): texts and emails both ways, one conversation per contact. Every send passes
 * the SendGuard; every reply stops follow-up plans, marks the lead engaged and alerts the agent.
 */
final class Inbox
{
    public const DRAFT = 'crm.reply_draft';

    private const STOP = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'REVOKE', 'OPTOUT'];

    private const START = ['START', 'UNSTOP', 'YES'];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly SendGuard $guard,
        private readonly MessagingSettings $messaging,
        private readonly Timeline $timeline,
        private readonly Consent $consent,
    ) {}

    public function conversationFor(Contact $contact): Conversation
    {
        $existing = Conversation::query()->where('contact_id', $contact->id)->first();
        if ($existing !== null) {
            return $existing;
        }
        $token = Str::random(40);
        DB::connection(Connections::control())->table('crm_inbound_addresses')->insert(['token' => $token, 'tenant_id' => $this->tenants->id(), 'kind' => 'reply', 'created_at' => now(), 'updated_at' => now()]);

        return Conversation::query()->create(['contact_id' => $contact->id, 'assigned_user_id' => $contact->owner_user_id, 'reply_token' => $token]);
    }

    /**
     * @return list<string>
     */
    public function check(Contact $contact, string $channel, string $body): array
    {
        return $this->guard->check($contact, $channel, $body);
    }

    public function send(Contact $contact, string $channel, string $body, ?string $subject, User $by, bool $aiDrafted = false): Message
    {
        $body = trim($body);
        $subject = $subject !== null ? mb_substr(trim(strip_tags($subject)), 0, 200) : null;
        $reasons = $this->guard->check($contact, $channel, $body);
        if ($channel === 'email' && ($subject === null || $subject === '')) {
            $reasons[] = 'Add a subject.';
        }
        if (mb_strlen($body) > ($channel === 'sms' ? 1000 : 20000)) {
            $reasons[] = $channel === 'sms' ? 'Keep texts under 1,000 characters.' : 'The email is too long.';
        }
        if ($reasons !== []) {
            throw ValidationException::withMessages(['body' => $reasons]);
        }

        $conversation = $this->conversationFor($contact);
        $status = 'sent';
        $error = null;
        $providerId = null;
        try {
            if ($channel === 'sms') {
                $from = $this->messaging->numberFor($this->tenants->id());
                $providerId = app(SmsSender::class)->deliver($from, (string) $contact->primary_phone, $body);
                $this->messaging->remember((string) $contact->primary_phone, $from, $this->tenants->id());
            } else {
                $mail = new MessageMail((string) $subject, $body, $by->name.' · '.$this->tenants->current()->name, self::replyAddress($conversation));
                Mail::to((string) $contact->primary_email, $contact->name)->send($mail);
            }
        } catch (\Throwable $e) {
            report($e);
            $status = 'failed';
            $error = mb_substr($e->getMessage(), 0, 200);
        }

        $message = Message::query()->create(['conversation_id' => $conversation->id, 'contact_id' => $contact->id, 'channel' => $channel, 'direction' => 'out',
            'user_id' => $by->id, 'subject' => $channel === 'email' ? $subject : null, 'body' => $body, 'status' => $status, 'provider_id' => $providerId, 'error' => $error, 'ai_drafted' => $aiDrafted]);

        if ($status === 'sent') {
            $conversation->forceFill(['last_message_at' => $message->created_at, 'last_direction' => 'out', 'preview' => mb_substr($body, 0, 160), 'unread' => false,
                'needs_reply_since' => null, 'nudged_at' => null, 'escalated_at' => null, 'assigned_user_id' => $conversation->assigned_user_id ?? $by->id])->save();
            // On the timeline as an outgoing text or email: this answers open leads (speed to lead).
            $this->timeline->log($contact, ['type' => $channel === 'sms' ? 'text' : 'email', 'direction' => 'out', 'subject' => $subject, 'body' => $body], $by);
        } else {
            throw ValidationException::withMessages(['body' => ['The message could not be sent. Please try again in a moment.']]);
        }

        return $message;
    }

    /**
     * A text arrived. Returns the reply to send straight back (sign codes), if any.
     */
    public function receiveSms(string $from, string $body, ?string $providerId): ?string
    {
        $body = mb_substr(trim($body), 0, 2000);
        if ($providerId !== null && Message::query()->where('provider_id', $providerId)->exists()) {
            return null; // Twilio retried
        }
        $keyword = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $body));
        $contact = Contact::query()->where('primary_phone', $from)->orderBy('id')->first();

        if ($contact !== null && in_array($keyword, self::STOP, true)) {
            $this->consent->optOutSms($contact, 'sms_keyword');
            $this->timeline->system($contact, 'Replied '.$keyword.': texts stopped everywhere');
            $this->store($contact, 'sms', $body, null, $providerId, false);

            return null; // the carrier/Twilio sends the opt-out confirmation
        }
        if ($contact !== null && in_array($keyword, self::START, true) && $contact->sms_opt_out_at !== null) {
            $this->consent->optInSms($contact, 'sms_keyword', 'Replied '.$keyword.' by text to resume messages.');
            $this->timeline->system($contact, 'Replied '.$keyword.': texts resumed');
        }

        $sign = app(SignCodes::class)->match($keyword);
        if ($sign !== null) {
            return app(SignCodes::class)->texted($sign, $from, $contact, $body, $providerId);
        }
        if ($contact === null) {
            return null; // unknown sender who did not text a sign code: nothing to file it under
        }
        $this->store($contact, 'sms', $body, null, $providerId, true);

        return null;
    }

    /** An email reply arrived at a conversation's reply address. */
    public function receiveEmail(Conversation $conversation, string $from, string $subject, string $text, ?string $messageId): void
    {
        if ($messageId !== null && $messageId !== '' && Message::query()->where('provider_id', $messageId)->exists()) {
            return;
        }
        $contact = $conversation->contact;
        $body = self::withoutQuote($text);
        $address = preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $from, $m) === 1 ? mb_strtolower($m[0]) : '';
        if ($address !== '' && $address !== $contact->primary_email) {
            $body = "(From {$address})\n".$body;
        }
        $this->store($contact, 'email', $body, mb_substr($subject, 0, 200), $messageId, true);
    }

    public function store(Contact $contact, string $channel, string $body, ?string $subject, ?string $providerId, bool $alert): Message
    {
        $conversation = $this->conversationFor($contact);
        $message = Message::query()->create(['conversation_id' => $conversation->id, 'contact_id' => $contact->id, 'channel' => $channel, 'direction' => 'in',
            'subject' => $subject, 'body' => $body, 'status' => 'received', 'provider_id' => $providerId !== null && $providerId !== '' ? mb_substr($providerId, 0, 120) : null]);
        $conversation->forceFill(['last_message_at' => $message->created_at, 'last_direction' => 'in', 'preview' => mb_substr($body, 0, 160), 'unread' => true,
            'needs_reply_since' => $conversation->needs_reply_since ?? $message->created_at, 'assigned_user_id' => $conversation->assigned_user_id ?? $contact->owner_user_id])->save();

        // Their reply stops follow-up plans (ActivityLogged listener) and marks their leads engaged.
        $activity = Activity::query()->create(['contact_id' => $contact->id, 'type' => $channel === 'sms' ? 'text' : 'email', 'direction' => 'in',
            'subject' => $subject, 'body' => $body, 'user_id' => null, 'occurred_at' => CarbonImmutable::now()]);
        ActivityLogged::dispatch($contact->tenant_id, $contact->id, $activity->id, $activity->type);
        Lead::query()->where('contact_id', $contact->id)->whereNull('engaged_at')->update(['engaged_at' => CarbonImmutable::now()]);

        $agentId = $conversation->assigned_user_id;
        $agent = $alert && $agentId !== null ? User::query()->find($agentId) : null;
        if ($agent !== null) {
            $agent->notify(new LeadAlert(($channel === 'sms' ? 'New text from ' : 'New email from ').$contact->name, '"'.mb_substr($body, 0, 300).'"',
                app(SpeedToLead::class)->link('/inbox?c='.$conversation->ulid), 'Reply'));
        }

        return $message;
    }

    /**
     * An AI-drafted reply in the agent's voice; the agent edits and sends it (AI never sends on its own here).
     *
     * @return array{draft: string, warnings: list<string>}
     */
    public function draft(Conversation $conversation, string $channel, User $by): array
    {
        $contact = $conversation->contact;
        $lines = Message::query()->where('conversation_id', $conversation->id)->latest('id')->limit(12)->get()->reverse()
            ->map(fn (Message $m): string => ($m->direction === 'in' ? $contact->first_name : 'Agent').' ('.$m->channel.'): '.mb_substr($m->body, 0, 1200))->values()->all();
        $lead = Lead::query()->where('contact_id', $contact->id)->latest('id')->first();
        $result = app(AiGateway::class)->run(new AiTask(self::DRAFT, 'crm.reply_draft', 'crm-reply:'.$conversation->ulid.':'.CarbonImmutable::now()->format('YmdHisv'),
            inputs: ['agent' => $by->first_name, 'brokerage' => $this->tenants->current()->name, 'first_name' => $contact->first_name, 'channel' => $channel === 'sms' ? 'text message' : 'email',
                'interest' => $lead !== null ? SpeedToLead::summary($lead) : 'unknown'],
            untrustedContent: $lines !== [] ? array_values($lines) : ['(no messages yet)'],
            outputSchema: ['type' => 'object', 'required' => ['draft'], 'properties' => ['draft' => ['type' => 'string']]], userId: $by->id));
        $draft = mb_substr(trim((string) ($result->json['draft'] ?? '')), 0, $channel === 'sms' ? 600 : 4000);

        return ['draft' => $draft, 'warnings' => FairHousing::flags($draft)];
    }

    public function markRead(Conversation $conversation): void
    {
        if ($conversation->unread) {
            $conversation->forceFill(['unread' => false])->save();
        }
    }

    /**
     * Replies waiting too long (every minute, waking hours): a nudge to the agent after 15 minutes, the broker's
     * risk register after an hour.
     *
     * @return array{nudged: int, escalated: int}
     */
    public function sweep(): array
    {
        $tz = (string) ($this->tenants->current()->getAttribute('timezone') ?: 'America/Chicago');
        $local = CarbonImmutable::now()->setTimezone($tz);
        if ($local->hour < 8 || $local->hour >= 21) {
            return ['nudged' => 0, 'escalated' => 0];
        }
        $dayStart = $local->setTime(8, 0)->utc();
        $out = ['nudged' => 0, 'escalated' => 0];
        foreach (Conversation::query()->whereNotNull('needs_reply_since')->whereNull('escalated_at')->with('contact')->get() as $c) {
            $since = $c->needs_reply_since !== null && $c->needs_reply_since->gt($dayStart) ? $c->needs_reply_since : $dayStart;
            $waited = (int) $since->diffInMinutes(CarbonImmutable::now());
            if ($waited >= 60) {
                $c->forceFill(['escalated_at' => CarbonImmutable::now()])->save();
                $out['escalated']++;
            } elseif ($waited >= 15 && $c->nudged_at === null) {
                $c->forceFill(['nudged_at' => CarbonImmutable::now()])->save();
                $agent = $c->assigned_user_id !== null ? User::query()->find($c->assigned_user_id) : null;
                if ($agent !== null) {
                    $url = app(SpeedToLead::class)->link('/inbox?c='.$c->ulid);
                    $agent->notify(new LeadAlert("{$c->contact->name} is waiting for your reply", "{$c->contact->name} wrote {$waited} minutes ago and has not had an answer.", $url, 'Reply now'));
                    $this->textAgent($agent, "RightAlly: {$c->contact->first_name} has been waiting {$waited} min for your reply: {$url}");
                }
                $out['nudged']++;
            }
        }

        return $out;
    }

    public static function replyAddress(Conversation $conversation): string
    {
        return 'reply-'.$conversation->reply_token.'@'.config('crm.inbound_email_domain', 'inbound.rightally.app');
    }

    /** Drops the quoted earlier message from an email reply. */
    public static function withoutQuote(string $text): string
    {
        $text = str_replace("\r\n", "\n", PortalEmail::plain($text));
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^\s*(On .{4,200} wrote:|-{2,}\s*Original Message\s*-{2,}|From: .+|Sent from my )/i', $line) === 1) {
                break;
            }
            if (str_starts_with(ltrim($line), '>')) {
                continue;
            }
            $lines[] = $line;
        }

        return mb_substr(trim(implode("\n", $lines)), 0, 20000) ?: '(empty reply)';
    }

    private function textAgent(User $agent, string $text): void
    {
        $consent = DB::connection(Connections::cell())->table('crm_lead_alert_prefs')->where('tenant_id', $this->tenants->id())->where('user_id', $agent->id)->value('texts_consent_at');
        if ($consent === null || $agent->phone === null || $agent->phone === '' || ! $this->messaging->smsFor($this->tenants->id())) {
            return;
        }
        try {
            app(SmsSender::class)->send($agent->phone, $text);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
