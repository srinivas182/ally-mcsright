<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Replies;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Ads\Meta\MetaApi;
use Modules\Ads\Social\SocialPost;
use Modules\Ads\Social\SocialTarget;
use Modules\Ads\Studio\StudioPlans;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Connect\Models\Connection;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\ConnectionData;
use Modules\Core\Connect\ConnectorKit;
use Modules\Core\Connect\MetaPageSubscriptions;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Enums\Source;
use Modules\Crm\FairHousing;
use Modules\Crm\Leads\LeadIntake;
use Modules\Crm\Models\LeadSource;
use RuntimeException;
use Throwable;

/**
 * Replies to comments and messages on the agent's Facebook Pages and Instagram (specs/grow/13, SA5c).
 *
 * - Off until the agent turns it on. Then Meta sends each new comment and message on their Pages and the Instagram
 *   accounts linked to them; Studio drafts a reply.
 * - "Approve first" (the default): every draft waits for the agent's OK. "Auto": thanks and simple questions about a
 *   post go out on their own; everything else still waits.
 * - Sensitive ones (complaints, legal, money, offers, private details, anything about protected groups) are never
 *   answered automatically: they go to the agent. Spam is ignored. Every reply passes the Fair Housing check.
 * - Someone who shares an email or phone number in a message becomes a lead, assigned to the agent and credited to
 *   the post. Texting consent is never assumed from a message.
 * - Messenger and Instagram only allow replies to a message within 24 hours; later ones are marked expired.
 */
final class StudioReplies
{
    public const FEATURE = 'ads.studio_reply';

    public const SOURCE = 'Facebook and Instagram messages';

    /** Intents "Auto" may answer on its own. */
    public const AUTO = ['praise', 'question'];

    private const SENSITIVE = '/\b(lawyer|attorney|sue|lawsuit|discriminat\w*|fair housing|scam\w*|fraud|refund|harass\w*|complain\w*|offer|counter|earnest|escrow|inspection|ssn|social security|bank account)\b/i';

    public function __construct(private readonly ConnectorKit $kit, private readonly TenantContext $tenants) {}

    /** @return array{on: bool, mode: string} */
    public function settings(User $user): array
    {
        $r = DB::connection(Connections::cell())->table('studio_reply_settings')->where('tenant_id', $this->tenants->id())->where('user_id', $user->id)->first();

        return ['on' => (bool) ($r->on ?? false), 'mode' => ($r->mode ?? 'approve') === 'auto' ? 'auto' : 'approve'];
    }

    /** Turns replies on (Meta starts sending comments and messages for the person's Pages) or off. */
    public function save(User $user, bool $on, string $mode): void
    {
        if ($on && ! app(StudioPlans::class)->has($user, 'replies')) {
            throw ValidationException::withMessages(['replies' => 'Replies to comments and messages come with Ally Studio packages that include them.']);
        }
        if ($on) {
            $connections = $this->connections($user);
            if ($connections === []) {
                throw ValidationException::withMessages(['replies' => 'Connect your Facebook Page first (Apps → Facebook Pages and Instagram).']);
            }
            foreach ($connections as $c) {
                $tokens = $this->tokens($c);
                foreach ((array) ($c->settings['pages'] ?? []) as $p) {
                    $id = is_array($p) ? (string) ($p['id'] ?? '') : '';
                    if ($id === '' || ! isset($tokens[$id]) || ! MetaPageSubscriptions::add($id, $tokens[$id], ['feed', 'messages'])) {
                        throw ValidationException::withMessages(['replies' => 'Facebook didn\'t allow replies for your Page yet. Connect Facebook Pages again and allow comments and messages.']);
                    }
                    $this->kit->routeTo($c, $id);
                }
                foreach ((array) ($c->settings['instagram'] ?? []) as $ig) {
                    if (is_array($ig) && is_string($ig['id'] ?? null)) {
                        $this->kit->routeTo($c, $ig['id']);
                    }
                }
            }
        }
        DB::connection(Connections::cell())->table('studio_reply_settings')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'user_id' => $user->id],
            ['on' => $on, 'mode' => $mode === 'auto' ? 'auto' : 'approve', 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('studio.replies', metadata: ['on' => $on, 'mode' => $mode], severity: 'notice');
    }

    /**
     * One comment or message from Meta (queued). Kept once; drafted; sent on its own only when allowed.
     *
     * @param  array<string, mixed>  $p  from FacebookLeads::route
     * @return array{created: int, updated: int, skipped: int}
     */
    public function receive(ConnectionData $connection, array $p): array
    {
        $skip = ['created' => 0, 'updated' => 0, 'skipped' => 1];
        $user = User::query()->find($connection->ownerUserId ?? $this->kit->connectedBy($connection));
        $kind = ($p['event'] ?? null) === 'message' ? 'message' : 'comment';
        $id = (string) ($p['id'] ?? '');
        $text = trim((string) ($p['text'] ?? ''));
        $account = (string) ($p['account'] ?? '');
        if ($user === null || ! $this->settings($user)['on'] || $id === '' || $text === '' || ($p['from'] ?? null) === $account || $this->isOwnAccount($connection, (string) ($p['from'] ?? ''))) {
            return $skip; // replies off, an attachment with no text, or the agent's own comment
        }
        if (StudioReply::query()->where('connection_id', $connection->id)->where('kind', $kind)->where('external_id', $id)->exists()) {
            return $skip; // Meta retries
        }
        $post = $this->postOf($connection, (string) ($p['post'] ?? ''));
        $reply = StudioReply::query()->create(['user_id' => $user->id, 'connection_id' => $connection->id, 'channel' => ($p['channel'] ?? 'facebook') === 'instagram' ? 'instagram' : 'facebook',
            'kind' => $kind, 'account' => $account, 'external_id' => $id, 'from_ref' => is_string($p['from'] ?? null) ? $p['from'] : null,
            'from_name' => is_string($p['name'] ?? null) ? $p['name'] : null, 'post_ref' => is_string($p['post'] ?? null) ? $p['post'] : null, 'social_post_id' => $post?->id,
            'text' => mb_substr($text, 0, 2000), 'status' => 'drafting', 'received_at' => CarbonImmutable::now()]);
        $this->lead($reply, $user, $post);
        $this->draft($reply, $user, $post);

        return ['created' => 1, 'updated' => 0, 'skipped' => 0];
    }

    /** Sends the reply (the draft, or the agent's own words). */
    public function send(StudioReply $reply, User $by, ?string $text = null, bool $auto = false): void
    {
        abort_unless($reply->user_id === $by->id, 404);
        if (in_array($reply->status, ['sent', 'skipped', 'ignored', 'expired'], true)) {
            throw ValidationException::withMessages(['reply' => 'This one is already done.']);
        }
        $text = trim((string) ($text ?? $reply->draft));
        if ($text === '' || mb_strlen($text) > 1000) {
            throw ValidationException::withMessages(['reply' => 'Write a reply of up to 1,000 characters.']);
        }
        if (($flags = FairHousing::screen($text, 'ads', 'blocked', 'Studio reply')) !== []) {
            throw ValidationException::withMessages(['reply' => 'Fair Housing: please remove "'.implode('", "', $flags).'". Talk about the home, not who should live there.']);
        }
        if ($reply->kind === 'message' && $reply->received_at->lt(CarbonImmutable::now()->subHours(24))) {
            $reply->forceFill(['status' => 'expired', 'reason' => 'Facebook and Instagram only allow a reply to a message within 24 hours. Answer it in the app.'])->save();
            throw ValidationException::withMessages(['reply' => (string) $reply->reason]);
        }
        $connection = Connection::query()->find($reply->connection_id)?->data() ?? throw ValidationException::withMessages(['reply' => 'Connect Facebook Pages again.']);
        try {
            $ref = $this->deliver($connection, $reply, $text);
        } catch (Throwable $e) {
            $reply->forceFill(['status' => 'failed', 'reason' => mb_substr($e->getMessage(), 0, 200)])->save();
            throw ValidationException::withMessages(['reply' => $e->getMessage()]);
        }
        $reply->forceFill(['status' => 'sent', 'draft' => $text, 'reply_ref' => $ref, 'sent_at' => CarbonImmutable::now(), 'reason' => $auto ? 'Sent automatically' : null])->save();
        app(AuditLogger::class)->record('studio.reply_sent', metadata: ['reply' => $reply->ulid, 'channel' => $reply->channel, 'kind' => $reply->kind, 'auto' => $auto]);
    }

    public function skip(StudioReply $reply, User $by): void
    {
        abort_unless($reply->user_id === $by->id, 404);
        if ($reply->status !== 'sent') {
            $reply->forceFill(['status' => 'skipped'])->save();
        }
    }

    /** The AI's draft; then it waits, goes to the agent, or (Auto, safe ones only) goes out. */
    private function draft(StudioReply $reply, User $user, ?SocialPost $post): void
    {
        $intent = 'other';
        $sensitive = true;
        $draft = '';
        try {
            $r = app(AiGateway::class)->run(new AiTask(self::FEATURE, 'ads.studio_reply', 'studio-reply:'.$reply->ulid,
                inputs: ['kind' => $reply->kind, 'channel' => $reply->channel],
                untrustedContent: $post !== null ? ['The '.$reply->kind.': '.$reply->text, 'The post it is about: '.mb_substr($post->text, 0, 800)] : ['The '.$reply->kind.': '.$reply->text],
                outputSchema: ['type' => 'object', 'properties' => ['intent' => ['type' => 'string'], 'sensitive' => ['type' => 'boolean'], 'reply' => ['type' => 'string']]],
                userId: $user->id));
            $intent = in_array($r->json['intent'] ?? null, ['question', 'interest', 'praise', 'complaint', 'spam', 'other'], true) ? (string) $r->json['intent'] : 'other';
            $sensitive = ($r->json['sensitive'] ?? true) !== false;
            $draft = mb_substr(trim((string) ($r->json['reply'] ?? '')), 0, 1000);
        } catch (Throwable $e) {
            report($e); // no draft: the agent answers it
        }
        if ($intent === 'spam') {
            $reply->forceFill(['intent' => $intent, 'status' => 'ignored', 'reason' => 'Looks like spam'])->save();

            return;
        }
        $why = match (true) {
            $draft === '' => 'Studio couldn\'t draft this one. Please answer it yourself.',
            $intent === 'complaint' || $sensitive || preg_match(self::SENSITIVE, $reply->text) === 1 => 'This one needs you: it may be about money, a deal, a complaint or something personal.',
            FairHousing::screen($draft, 'ads', 'removed', 'Studio reply draft') !== [] => 'The draft didn\'t pass the Fair Housing check. Please answer it yourself.',
            default => null,
        };
        if ($why !== null && FairHousing::screen($draft, 'ads', 'removed', 'Studio reply draft') !== []) {
            $draft = ''; // never offer wording that fails the check
        }
        $reply->forceFill(['intent' => $intent, 'draft' => $draft !== '' ? $draft : null, 'status' => $why !== null ? 'sensitive' : 'awaiting', 'reason' => $why])->save();
        if ($why === null && $this->settings($user)['mode'] === 'auto' && in_array($intent, self::AUTO, true)) {
            try {
                $this->send($reply, $user, null, true);

                return;
            } catch (ValidationException) {
                $reply->refresh(); // stays for the agent, with the reason
            }
        }
        $recent = StudioReply::query()->where('user_id', $user->id)->where('id', '!=', $reply->id)->whereIn('status', ['awaiting', 'sensitive'])
            ->where('created_at', '>=', CarbonImmutable::now()->subMinutes(15))->exists(); // one email for a burst, not one each
        if (! $recent && in_array($reply->status, ['awaiting', 'sensitive', 'failed'], true)) {
            $user->notify(new ReplyWaiting($reply->channel, $reply->kind, $reply->status === 'sensitive'));
        }
    }

    /** Someone who shares an email or phone number in a message becomes a lead (no texting consent assumed). */
    private function lead(StudioReply $reply, User $user, ?SocialPost $post): void
    {
        if ($reply->kind !== 'message') {
            return; // contact details in a public comment aren't taken
        }
        $email = preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $reply->text, $m) === 1 ? $m[0] : '';
        $phone = preg_match('/(?:\+?1[\s.\-]?)?\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]?\d{4}/', $reply->text, $n) === 1 ? $n[0] : '';
        if ($email === '' && $phone === '') {
            return;
        }
        $name = trim((string) $reply->from_name) !== '' ? (string) $reply->from_name : $this->profileName($reply);
        $source = LeadSource::query()->where('name', self::SOURCE)->where('source', Source::Facebook->value)->first()
            ?? LeadSource::query()->create(['name' => self::SOURCE, 'kind' => 'api', 'source' => Source::Facebook, 'token' => Str::random(40), 'created_by' => $user->id]);
        try {
            $lead = app(LeadIntake::class)->receive($source, ['external_id' => 'studio-'.$reply->channel.'-'.mb_substr($reply->external_id, 0, 150), 'name' => $name,
                'email' => $email, 'phone' => $phone, 'source' => Source::Facebook->value,
                'message' => 'Messaged on '.($reply->channel === 'instagram' ? 'Instagram' : 'Facebook').': '.mb_substr($reply->text, 0, 1500),
                'utm_source' => $reply->channel, 'utm_medium' => 'social_message', 'utm_campaign' => $post !== null ? 'rightally-'.strtolower($post->ulid) : null], null, $user);
            $reply->forceFill(['lead_id' => $lead->id])->save();
        } catch (ValidationException) {
            // no name to go with it: the agent sees the message and can add them
        }
    }

    /** Who sent a message: Meta shares their name with the Page they wrote to (else a plain label). */
    private function profileName(StudioReply $reply): string
    {
        $fallback = ($reply->channel === 'instagram' ? 'Instagram' : 'Facebook').' contact';
        $connection = Connection::query()->find($reply->connection_id)?->data();
        $page = $connection !== null ? ($reply->channel === 'instagram' ? $this->pageOf($connection, $reply->account) : $reply->account) : '';
        $token = $connection !== null ? ($this->tokens($connection)[$page] ?? null) : null;
        if ($token === null || $reply->from_ref === null) {
            return $fallback;
        }
        try {
            $r = Http::timeout(10)->get(MetaApi::GRAPH.$reply->from_ref, ['fields' => $reply->channel === 'instagram' ? 'name,username' : 'first_name,last_name', 'access_token' => $token]);
            $name = trim($reply->channel === 'instagram' ? (string) ($r->json('name') ?: $r->json('username')) : $r->json('first_name').' '.$r->json('last_name'));
        } catch (Throwable) {
            $name = '';
        }
        if ($name !== '') {
            $reply->forceFill(['from_name' => mb_substr($name, 0, 120)])->save();
        }

        return $name !== '' ? $name : $fallback;
    }

    /** @return string the id Meta gave the reply */
    private function deliver(ConnectionData $connection, StudioReply $reply, string $text): string
    {
        $page = $reply->channel === 'instagram' ? $this->pageOf($connection, $reply->account) : $reply->account;
        $token = $this->tokens($connection)[$page] ?? throw new RuntimeException('Connect Facebook Pages again: RightAlly can\'t reach this Page.');
        $r = match (true) {
            $reply->kind === 'message' => Http::asJson()->timeout(20)->post(MetaApi::GRAPH.$page.'/messages?access_token='.urlencode($token), [
                'recipient' => ['id' => (string) $reply->from_ref], 'messaging_type' => 'RESPONSE', 'message' => ['text' => $text]]),
            $reply->channel === 'instagram' => Http::asForm()->timeout(20)->post(MetaApi::GRAPH.$reply->external_id.'/replies', ['message' => $text, 'access_token' => $token]),
            default => Http::asForm()->timeout(20)->post(MetaApi::GRAPH.$reply->external_id.'/comments', ['message' => $text, 'access_token' => $token]),
        };
        if (! $r->successful()) {
            throw new RuntimeException(mb_substr('Facebook: '.(string) ($r->json('error.error_user_msg') ?? $r->json('error.message') ?? 'the reply was not accepted.'), 0, 200));
        }

        return (string) ($r->json('id') ?? $r->json('message_id') ?? '');
    }

    /** @return list<ConnectionData> the person's own Facebook Pages connections */
    private function connections(User $user): array
    {
        $out = [];
        foreach (Connection::query()->where('app_key', 'facebook_pages')->whereIn('status', ['connected', 'error'])
            ->where(fn ($q) => $q->where('owner_user_id', $user->id)->orWhere(fn ($b) => $b->whereNull('owner_user_id')->where('connected_by', $user->id)))->get() as $c) {
            $out[] = $c->data();
        }

        return $out;
    }

    /** @return array<string, string> Page id => Page token */
    private function tokens(ConnectionData $connection): array
    {
        $t = json_decode((string) ($connection->credentials['pages'] ?? '{}'), true);

        return is_array($t) ? array_map('strval', array_filter($t, 'is_string')) : [];
    }

    private function pageOf(ConnectionData $connection, string $instagram): string
    {
        foreach ((array) ($connection->settings['instagram'] ?? []) as $ig) {
            if (is_array($ig) && ($ig['id'] ?? null) === $instagram) {
                return (string) ($ig['page'] ?? '');
            }
        }

        return '';
    }

    private function isOwnAccount(ConnectionData $connection, string $from): bool
    {
        return $from !== '' && (in_array($from, array_map('strval', array_column((array) ($connection->settings['pages'] ?? []), 'id')), true)
            || in_array($from, array_map('strval', array_column((array) ($connection->settings['instagram'] ?? []), 'id')), true));
    }

    /** The Studio or Social post a comment is on (so a lead is credited to it). */
    private function postOf(ConnectionData $connection, string $ref): ?SocialPost
    {
        if ($ref === '') {
            return null;
        }
        $t = SocialTarget::query()->where('connection_id', $connection->id)->where('external_id', $ref)->first();

        return $t !== null ? SocialPost::query()->find($t->post_id) : null;
    }
}
