<?php

declare(strict_types=1);

namespace Modules\Team;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Team\Jobs\SendMentionMail;
use Modules\Team\Models\Channel;
use Modules\Team\Models\Member;
use Modules\Team\Models\Message;

/**
 * Team chat (CH1, specs/team/01). Every brokerage has:
 *  - "Everyone": all active people in the office;
 *  - "Announcements": everyone reads, only the owner and staff post (the broker's voice);
 *  - groups the owner or staff make (an office, a team, a committee), with chosen members;
 *  - direct chats between two people, private to those two.
 * Messages are encrypted at rest. The author can edit or take back a message for 15 minutes; the owner can hide
 * any message in a shared channel. A hidden message keeps its row and an audit entry, so nothing is truly deleted.
 * An @mention emails the person if they have not read it within 10 minutes.
 */
final class Chat
{
    public const KINDS_ALLOWED = [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant];

    public const EDIT_MINUTES = 15;

    public const MAX_LENGTH = 4000;

    public static function allowed(User $user): bool
    {
        return in_array($user->kind, self::KINDS_ALLOWED, true) && $user->status === UserStatus::Active;
    }

    public static function leads(User $user): bool
    {
        return in_array($user->kind, [UserKind::Owner, UserKind::Staff], true);
    }

    /**
     * The person's channels, newest activity first, with unread counts. Creates Everyone and Announcements the
     * first time anyone opens chat.
     *
     * @return list<array{ulid: string, kind: string, name: string, unread: int, can_post: bool, last_message_at: string|null}>
     */
    public function channelsFor(User $user): array
    {
        $this->ensureDefaults();
        $mine = Member::query()->where('user_id', $user->id)->get()->keyBy('channel_id');
        $channels = Channel::query()->whereIn('kind', ['everyone', 'announcements'])->orWhereIn('id', $mine->keys())->get();
        $out = [];
        foreach ($channels as $c) {
            $read = (int) ($mine->get($c->id)->last_read_id ?? 0);
            $unread = Message::query()->where('channel_id', $c->id)->where('id', '>', $read)->where('user_id', '!=', $user->id)->whereNull('hidden_at')->count();
            $out[] = ['ulid' => $c->ulid, 'kind' => $c->kind, 'name' => $c->kind === 'direct' ? $this->directName($c, $user) : $c->name, 'unread' => $unread,
                'can_post' => $this->canPost($user, $c), 'last_message_at' => $c->last_message_at?->toIso8601String()];
        }
        usort($out, fn (array $a, array $b): int => [self::rank($a['kind']), $b['last_message_at'] ?? ''] <=> [self::rank($b['kind']), $a['last_message_at'] ?? '']);

        return $out;
    }

    public function unreadTotal(User $user): int
    {
        return array_sum(array_column($this->channelsFor($user), 'unread'));
    }

    public function canSee(User $user, Channel $channel): bool
    {
        return self::allowed($user) && (in_array($channel->kind, ['everyone', 'announcements'], true)
            || Member::query()->where('channel_id', $channel->id)->where('user_id', $user->id)->exists());
    }

    public function canPost(User $user, Channel $channel): bool
    {
        return $this->canSee($user, $channel) && ($channel->kind !== 'announcements' || self::leads($user));
    }

    /**
     * Messages after an id (for the 10-second refresh), oldest first, and marks them read.
     *
     * @return list<array{id: int, author: string, author_id: int, mine: bool, body: string|null, hidden: bool, edited: bool, at: string, can_change: bool, can_hide: bool}>
     */
    public function messages(Channel $channel, User $user, int $after = 0, int $limit = 100): array
    {
        abort_unless($this->canSee($user, $channel), 403);
        $rows = Message::query()->where('channel_id', $channel->id)->where('id', '>', $after)->orderByDesc('id')->limit($limit)->get()->reverse()->values();
        $names = User::query()->whereIn('id', $rows->pluck('user_id')->unique())->get()->mapWithKeys(fn (User $u): array => [$u->id => (string) $u->name]);
        if ($rows->isNotEmpty()) {
            $this->markRead($channel, $user, (int) $rows->last()->id);
        }

        return array_values($rows->map(fn (Message $m): array => ['id' => $m->id, 'author' => (string) ($names[$m->user_id] ?? 'Former member'), 'author_id' => $m->user_id,
            'mine' => $m->user_id === $user->id, 'body' => $m->hidden_at === null ? $m->body : null, 'hidden' => $m->hidden_at !== null, 'edited' => $m->edited_at !== null,
            'at' => $m->created_at->toIso8601String(), 'can_change' => $this->canChange($m, $user), 'can_hide' => $this->canHide($m, $channel, $user)])->all());
    }

    /**
     * @param  list<int>  $mentions  people the writer picked from the @ list
     */
    public function post(Channel $channel, string $body, User $by, array $mentions = []): Message
    {
        if (! $this->canPost($by, $channel)) {
            throw ValidationException::withMessages(['body' => $channel->kind === 'announcements' ? 'Only the broker and staff post announcements.' : 'You are not in this chat.']);
        }
        $body = trim($body);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Write a message first.']);
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw ValidationException::withMessages(['body' => 'Keep messages under '.self::MAX_LENGTH.' characters.']);
        }
        $valid = $this->memberIds($channel);
        $mentions = array_values(array_unique(array_filter(array_map('intval', $mentions), fn (int $id): bool => $id !== $by->id && in_array($id, $valid, true))));
        $m = Message::query()->create(['channel_id' => $channel->id, 'user_id' => $by->id, 'body' => $body, 'mentions' => $mentions !== [] ? $mentions : null]);
        $channel->forceFill(['last_message_at' => CarbonImmutable::now()])->save();
        $this->markRead($channel, $by, $m->id);
        foreach ($mentions as $id) {
            SendMentionMail::dispatch($m->id, $id)->delay(now()->addMinutes(10));
        }

        return $m;
    }

    public function edit(Message $m, string $body, User $by): void
    {
        if (! $this->canChange($m, $by)) {
            throw ValidationException::withMessages(['body' => 'Messages can be changed for '.self::EDIT_MINUTES.' minutes after sending.']);
        }
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > self::MAX_LENGTH) {
            throw ValidationException::withMessages(['body' => 'Write a message first.']);
        }
        $m->forceFill(['body' => $body, 'edited_at' => CarbonImmutable::now()])->save();
    }

    /** Takes a message back (its author, within 15 minutes) or hides it (the owner or staff, in a shared channel). */
    public function hide(Message $m, User $by): void
    {
        $channel = Channel::query()->whereKey($m->channel_id)->firstOrFail();
        if (! $this->canChange($m, $by) && ! $this->canHide($m, $channel, $by)) {
            abort(403);
        }
        $m->forceFill(['hidden_at' => CarbonImmutable::now(), 'hidden_by' => $by->id])->save();
        app(AuditLogger::class)->record('team.chat.message_hidden', metadata: ['channel' => $channel->ulid, 'message' => $m->id, 'author' => $m->user_id,
            'by_author' => $m->user_id === $by->id], severity: $m->user_id === $by->id ? 'info' : 'notice');
    }

    /** Opens (or finds) the direct chat between two people. */
    public function direct(User $me, User $other): Channel
    {
        if ($me->id === $other->id || ! self::allowed($other) || ! self::allowed($me)) {
            throw ValidationException::withMessages(['user' => 'You can chat with active people in your office.']);
        }
        $key = min($me->id, $other->id).':'.max($me->id, $other->id);
        $c = Channel::query()->firstOrCreate(['direct_key' => $key], ['kind' => 'direct', 'name' => 'Direct', 'created_by' => $me->id]);
        foreach ([$me->id, $other->id] as $id) {
            Member::query()->firstOrCreate(['channel_id' => $c->id, 'user_id' => $id]);
        }

        return $c;
    }

    /**
     * A group for an office, team or committee (owner and staff).
     *
     * @param  list<int>  $userIds
     */
    public function group(string $name, array $userIds, User $by): Channel
    {
        abort_unless(self::leads($by), 403);
        $name = trim(strip_tags($name));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Name the group, for example "Downtown office".']);
        }
        $c = Channel::query()->create(['kind' => 'group', 'name' => mb_substr($name, 0, 80), 'created_by' => $by->id]);
        $this->setMembers($c, $userIds, $by);
        app(AuditLogger::class)->record('team.chat.group_created', metadata: ['channel' => $c->ulid, 'name' => $c->name]);

        return $c;
    }

    /** @param list<int> $userIds */
    public function setMembers(Channel $c, array $userIds, User $by): void
    {
        abort_unless(self::leads($by) && $c->kind === 'group', 403);
        $ids = User::query()->whereIn('id', array_merge(array_map('intval', $userIds), [$by->id]))->get()->filter(fn (User $u): bool => self::allowed($u))->pluck('id')->all();
        Member::query()->where('channel_id', $c->id)->whereNotIn('user_id', $ids)->delete();
        foreach ($ids as $id) {
            Member::query()->firstOrCreate(['channel_id' => $c->id, 'user_id' => $id]);
        }
    }

    /**
     * People who can be @mentioned in a channel.
     *
     * @return list<int>
     */
    public function memberIds(Channel $channel): array
    {
        if (in_array($channel->kind, ['everyone', 'announcements'], true)) {
            return array_values(self::people()->pluck('id')->map(fn ($id): int => (int) $id)->all());
        }

        return array_values(Member::query()->where('channel_id', $channel->id)->pluck('user_id')->map(fn ($id): int => (int) $id)->all());
    }

    /** @return Builder<User> */
    public static function people(): Builder
    {
        return User::query()->whereIn('kind', array_map(fn (UserKind $k): string => $k->value, self::KINDS_ALLOWED))->where('status', UserStatus::Active->value);
    }

    public function markRead(Channel $channel, User $user, int $upTo): void
    {
        $m = Member::query()->firstOrCreate(['channel_id' => $channel->id, 'user_id' => $user->id]);
        if ($upTo > $m->last_read_id) {
            $m->forceFill(['last_read_id' => $upTo])->save();
        }
    }

    public function hasRead(int $channelId, int $userId, int $messageId): bool
    {
        return Member::query()->where('channel_id', $channelId)->where('user_id', $userId)->where('last_read_id', '>=', $messageId)->exists();
    }

    private function canChange(Message $m, User $by): bool
    {
        return $m->user_id === $by->id && $m->hidden_at === null && $m->created_at->greaterThan(CarbonImmutable::now()->subMinutes(self::EDIT_MINUTES));
    }

    private function canHide(Message $m, Channel $c, User $by): bool
    {
        return $m->hidden_at === null && $c->kind !== 'direct' && self::leads($by);
    }

    private function ensureDefaults(): void
    {
        if (! Channel::query()->where('kind', 'everyone')->exists()) {
            Channel::query()->create(['kind' => 'everyone', 'name' => 'Everyone']);
        }
        if (! Channel::query()->where('kind', 'announcements')->exists()) {
            Channel::query()->create(['kind' => 'announcements', 'name' => 'Announcements']);
        }
    }

    private function directName(Channel $c, User $me): string
    {
        $other = Member::query()->where('channel_id', $c->id)->where('user_id', '!=', $me->id)->value('user_id');
        $u = $other !== null ? User::query()->whereKey((int) $other)->first() : null;

        return $u !== null ? (string) $u->name : 'Former member';
    }

    private static function rank(string $kind): int
    {
        return match ($kind) {
            'announcements' => 0,
            'everyone' => 1,
            'group' => 2,
            default => 3,
        };
    }
}
