<?php

declare(strict_types=1);

namespace Modules\Engage;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Tasks;
use Modules\Crm\Consent;
use Modules\Crm\Enums\Lifecycle;
use Modules\Crm\Inbox\Inbox;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\ConsentEvent;
use Modules\Crm\Models\Contact;
use Modules\Engage\Models\CheckIn;
use Throwable;

/**
 * The database assistant by email (EN1, D23, specs/engage/01). Each week it drafts a few short, personal check-in
 * emails to the agent's past clients and sphere who have not heard from them in 90 days. The agent reads, edits and
 * sends each one from their Inbox (one click); nothing is sent without them. Replies come back to RightAlly through
 * the conversation's own reply address (we never read anyone's mailbox). Each reply is read by AI for what the person
 * wants (buy, sell, refer, a question, not now, stop emailing) and the agent gets a short brief, a task and a
 * suggested reply with one or two qualifying questions, which they can edit and send. "Stop emailing" is recorded as
 * an email opt-out straight away. Email only: texts and AI calls need the person's written consent and are not done here.
 */
final class DatabaseAssistant
{
    public const DRAFT = 'engage.checkin_draft';

    public const READ = 'engage.checkin_reply';

    public const INTENTS = ['buy' => 'Thinking of buying', 'sell' => 'Thinking of selling', 'refer' => 'Has someone to refer', 'question' => 'Asked a question',
        'not_now' => 'Not right now', 'unsubscribe' => 'Asked not to be emailed', 'other' => 'Just replied'];

    private const DRAFT_SCHEMA = ['type' => 'object', 'required' => ['subject', 'body'], 'properties' => ['subject' => ['type' => 'string'], 'body' => ['type' => 'string']]];

    private const READ_SCHEMA = ['type' => 'object', 'required' => ['intent', 'brief', 'reply'], 'properties' => ['intent' => ['type' => 'string'], 'brief' => ['type' => 'string'],
        'reply' => ['type' => 'string']]];

    public function __construct(private readonly AiGateway $gateway, private readonly TenantContext $tenants) {}

    /** @return array{active: bool, weekly: int, audience: string, about: string|null} */
    public function settings(User $user): array
    {
        $row = DB::connection(Connections::cell())->table('engage_assistants')->where('tenant_id', $this->tenants->id())->where('user_id', $user->id)->first();

        return ['active' => (bool) ($row->active ?? false), 'weekly' => (int) ($row->weekly ?? 5), 'audience' => (string) ($row->audience ?? 'past_clients'),
            'about' => isset($row->about) ? (string) $row->about : null];
    }

    public function save(User $user, bool $active, int $weekly, string $audience, ?string $about): void
    {
        if (! in_array($audience, ['past_clients', 'sphere', 'both'], true)) {
            throw ValidationException::withMessages(['audience' => 'Choose who to keep in touch with.']);
        }
        DB::connection(Connections::cell())->table('engage_assistants')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'user_id' => $user->id],
            ['active' => $active, 'weekly' => max(1, min(20, $weekly)), 'audience' => $audience, 'about' => $about !== null ? mb_substr(trim(strip_tags($about)), 0, 300) : null,
                'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('engage.assistant.saved', metadata: ['active' => $active, 'weekly' => $weekly, 'audience' => $audience]);
    }

    /** Drafts this week's check-ins (run on Mondays, or by the agent). Returns how many were drafted. */
    public function draftWeek(User $user): int
    {
        $s = $this->settings($user);
        $open = CheckIn::query()->where('user_id', $user->id)->where('status', 'draft')->count();
        $room = max(0, $s['weekly'] - $open);
        $n = 0;
        foreach ($room > 0 ? $this->candidates($user, $s['audience'], $room) : [] as $contact) {
            try {
                $this->draftFor($contact, $user, $s['about']);
                $n++;
            } catch (Throwable $e) {
                report($e); // no credits or AI unavailable: stop for this week
                break;
            }
        }
        DB::connection(Connections::cell())->table('engage_assistants')->where('tenant_id', $this->tenants->id())->where('user_id', $user->id)->update(['last_run_at' => now()]);

        return $n;
    }

    /**
     * Past clients or sphere with an email, owned by the agent, not contacted in 90 days, not emailed by the assistant
     * in 90 days, and not unsubscribed.
     *
     * @return list<Contact>
     */
    public function candidates(User $user, string $audience, int $limit): array
    {
        $life = match ($audience) {
            'sphere' => [Lifecycle::Sphere->value],
            'both' => [Lifecycle::PastClient->value, Lifecycle::Sphere->value],
            default => [Lifecycle::PastClient->value],
        };
        $since = CarbonImmutable::now()->subDays(90);
        $recent = CheckIn::query()->where('created_at', '>=', $since)->pluck('contact_id')->all();
        $out = [];
        $rows = Contact::query()->where('owner_user_id', $user->id)->whereIn('lifecycle', $life)->whereNotNull('primary_email')->whereNotIn('id', $recent)
            ->where(fn ($q) => $q->whereNull('last_activity_at')->orWhere('last_activity_at', '<', $since))->orderBy('last_activity_at')->limit($limit * 3)->get();
        foreach ($rows as $c) {
            $last = ConsentEvent::query()->where('contact_id', $c->id)->where('channel', 'email')->latest('id')->value('action');
            if ($last !== 'opt_out') {
                $out[] = $c;
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function draftFor(Contact $contact, User $agent, ?string $about): CheckIn
    {
        $facts = ['first_name' => $contact->first_name, 'city' => $contact->city, 'relationship' => $contact->lifecycle === Lifecycle::PastClient ? 'past client' : 'someone I know',
            'last_in_touch' => $contact->last_activity_at?->diffForHumans(['parts' => 1]) ?? 'a while ago', 'agent_first_name' => $agent->first_name,
            'season' => CarbonImmutable::now()->format('F')];
        $result = $this->gateway->run(new AiTask(self::DRAFT, 'engage.checkin_draft', 'checkin:'.$contact->ulid.':'.CarbonImmutable::today()->format('oW'),
            inputs: ['facts' => (string) json_encode($facts)], untrustedContent: $about !== null && $about !== '' ? [$about] : [], outputSchema: self::DRAFT_SCHEMA, userId: $agent->id));
        $subject = mb_substr(trim(strip_tags((string) ($result->json['subject'] ?? ''))), 0, 150);
        $body = mb_substr(trim((string) ($result->json['body'] ?? '')), 0, 2000);
        if ($subject === '' || $body === '') {
            throw new \RuntimeException('Empty draft.');
        }

        return CheckIn::query()->create(['user_id' => $agent->id, 'contact_id' => $contact->id, 'status' => 'draft', 'subject' => $subject, 'body' => $body]);
    }

    /** The agent sends a check-in (as written, or as they changed it) from their Inbox. */
    public function send(CheckIn $c, User $by, ?string $subject = null, ?string $body = null): void
    {
        abort_unless($c->user_id === $by->id && $c->status === 'draft', 404);
        $contact = Contact::query()->whereKey($c->contact_id)->firstOrFail();
        $subject = $subject !== null && trim($subject) !== '' ? $subject : $c->subject;
        $body = $body !== null && trim($body) !== '' ? $body : $c->body;
        app(Inbox::class)->send($contact, 'email', $body, $subject, $by, aiDrafted: true); // every Inbox check applies (unsubscribes, Fair Housing…)
        $c->update(['status' => 'sent', 'sent_at' => CarbonImmutable::now(), 'subject' => mb_substr($subject, 0, 200), 'body' => $body]);
    }

    public function skip(CheckIn $c, User $by): void
    {
        abort_unless($c->user_id === $by->id && $c->status === 'draft', 404);
        $c->update(['status' => 'skipped']);
    }

    /** Called when an email comes in from a contact: was it an answer to a check-in? */
    public function onActivity(int $contactId, int $activityId): void
    {
        $c = CheckIn::query()->where('contact_id', $contactId)->where('status', 'sent')->where('sent_at', '>=', CarbonImmutable::now()->subDays(60))->latest('id')->first();
        $a = Activity::query()->whereKey($activityId)->first();
        if ($c === null || $a === null || $a->type !== 'email' || $a->direction !== 'in') {
            return;
        }
        Jobs\ReadCheckInReply::dispatch($c->id, $activityId);
    }

    /** Reads a reply and tells the agent what the person wants, with a task and a suggested reply. */
    public function readReply(CheckIn $c, Activity $reply): void
    {
        $agent = User::query()->whereKey($c->user_id)->first();
        $contact = Contact::query()->whereKey($c->contact_id)->first();
        if ($agent === null || $contact === null || $c->status !== 'sent') {
            return;
        }
        $result = $this->gateway->run(new AiTask(self::READ, 'engage.checkin_reply', 'checkin-reply:'.$c->ulid.':'.$reply->id,
            inputs: ['agent_first_name' => $agent->first_name, 'contact_first_name' => $contact->first_name],
            untrustedContent: ['OUR EMAIL: '.$c->body, 'THEIR REPLY: '.mb_substr((string) $reply->body, 0, 4000)], outputSchema: self::READ_SCHEMA, userId: $agent->id));
        $intent = (string) ($result->json['intent'] ?? 'other');
        $intent = isset(self::INTENTS[$intent]) ? $intent : 'other';
        $brief = mb_substr(trim((string) ($result->json['brief'] ?? '')), 0, 400);
        $c->update(['status' => 'replied', 'replied_at' => CarbonImmutable::now(), 'intent' => $intent, 'brief' => $brief,
            'suggested_reply' => $intent === 'unsubscribe' ? null : mb_substr(trim((string) ($result->json['reply'] ?? '')), 0, 2000)]);
        if ($intent === 'unsubscribe') {
            app(Consent::class)->setEmail($contact, false, 'reply', null, ['source' => 'database_assistant_reply']);
        }
        $hot = in_array($intent, ['buy', 'sell', 'refer'], true);
        if ($intent !== 'unsubscribe') {
            app(Tasks::class)->create($contact, ['title' => mb_substr('Reply to '.$contact->first_name.': '.self::INTENTS[$intent], 0, 200), 'type' => 'email', 'due' => 'today',
                'priority' => $hot ? 'high' : 'normal', 'notes' => $brief], $agent);
        }
        $agent->notify(new CheckInReplyMail($contact->name, self::INTENTS[$intent], $brief, '/engage?checkin='.$c->ulid));
        app(AuditLogger::class)->record('engage.checkin.replied', metadata: ['checkin' => $c->ulid, 'intent' => $intent]);
    }
}
