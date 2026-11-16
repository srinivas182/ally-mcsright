<?php

declare(strict_types=1);

namespace Modules\Core\Notices;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Connections;

/**
 * The notification centre (specs/core/10, N1): one inbox of alerts per person, with preferences per kind of alert,
 * quiet hours and an optional daily digest.
 */
final class Notices
{
    /** Opening fewer than this share of a kind's alerts, over at least NOISY_MIN alerts in 30 days, makes it "noisy". */
    public const NOISY_SHARE = 0.1;

    public const NOISY_MIN = 8;

    public function __construct(private readonly NoticeTypes $types) {}

    /**
     * @return array{in_app: bool, email: bool}
     */
    public function pref(User $user, string $type): array
    {
        $row = DB::connection(Connections::cell())->table('notice_prefs')->where('user_id', $user->id)->where('type', $type)->first();

        return ['in_app' => $row === null || (bool) $row->in_app, 'email' => $row === null || (bool) $row->email];
    }

    public function setPref(User $user, string $type, bool $inApp, bool $email): void
    {
        DB::connection(Connections::cell())->table('notice_prefs')->updateOrInsert(['user_id' => $user->id, 'type' => mb_substr($type, 0, 80)],
            ['tenant_id' => $user->tenant_id, 'in_app' => $inApp, 'email' => $email]);
    }

    /**
     * @return array{quiet_start: int|null, quiet_end: int|null, digest: bool}
     */
    public function settings(User $user): array
    {
        $row = DB::connection(Connections::cell())->table('notice_settings')->where('user_id', $user->id)->first();

        return ['quiet_start' => $row?->quiet_start !== null ? (int) $row->quiet_start : null, 'quiet_end' => $row?->quiet_end !== null ? (int) $row->quiet_end : null,
            'digest' => (bool) ($row->digest ?? false)];
    }

    public function saveSettings(User $user, ?int $quietStart, ?int $quietEnd, bool $digest): void
    {
        $valid = fn (?int $h): ?int => $h !== null && $h >= 0 && $h <= 23 ? $h : null;
        $start = $valid($quietStart);
        $end = $valid($quietEnd);
        if ($start === null || $end === null || $start === $end) {
            $start = $end = null;
        }
        DB::connection(Connections::cell())->table('notice_settings')->updateOrInsert(['user_id' => $user->id],
            ['tenant_id' => $user->tenant_id, 'quiet_start' => $start, 'quiet_end' => $end, 'digest' => $digest]);
    }

    /** Whether it is quiet hours for this person right now (in their time zone). */
    public function quiet(User $user, ?CarbonImmutable $at = null): bool
    {
        $s = $this->settings($user);
        if ($s['quiet_start'] === null || $s['quiet_end'] === null) {
            return false;
        }
        $hour = ($at ?? CarbonImmutable::now())->setTimezone($user->timezone ?: 'America/Chicago')->hour;

        return $s['quiet_start'] < $s['quiet_end'] ? $hour >= $s['quiet_start'] && $hour < $s['quiet_end'] : $hour >= $s['quiet_start'] || $hour < $s['quiet_end'];
    }

    /**
     * Records an alert in the person's inbox (unless they turned that kind off in the app).
     */
    public function add(User $user, string $type, string $title, ?string $body = null, ?string $url = null, bool $urgent = false, bool $emailPending = false): ?string
    {
        if (! $this->pref($user, $type)['in_app'] && ! $emailPending) {
            return null;
        }
        $ulid = (string) Str::ulid();
        DB::connection(Connections::cell())->table('user_notices')->insert(['ulid' => $ulid, 'tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'type' => mb_substr($type, 0, 80),
            'title' => mb_substr($title, 0, 200), 'body' => $body !== null ? mb_substr($body, 0, 500) : null, 'url' => $url !== null ? mb_substr($url, 0, 500) : null,
            'urgent' => $urgent, 'email_pending' => $emailPending, 'read_at' => $this->pref($user, $type)['in_app'] ? null : CarbonImmutable::now(), 'created_at' => CarbonImmutable::now()]);

        return $ulid;
    }

    public function unread(User $user): int
    {
        return $this->visible($user)->whereNull('read_at')->count();
    }

    /**
     * @return list<array{ulid: string, type: string, label: string, title: string, body: string|null, url: string|null, urgent: bool, read: bool, at: string}>
     */
    public function latest(User $user, int $limit = 50, bool $unreadOnly = false): array
    {
        return array_values($this->visible($user)->when($unreadOnly, fn ($q) => $q->whereNull('read_at'))->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($n): array => ['ulid' => (string) $n->ulid, 'type' => (string) $n->type, 'label' => $this->types->label((string) $n->type), 'title' => (string) $n->title,
                'body' => $n->body !== null ? (string) $n->body : null, 'url' => $n->url !== null ? (string) $n->url : null, 'urgent' => (bool) $n->urgent,
                'read' => $n->read_at !== null, 'at' => CarbonImmutable::parse((string) $n->created_at)->toIso8601String()])->all());
    }

    public function markRead(User $user, ?string $ulid = null): void
    {
        DB::connection(Connections::cell())->table('user_notices')->where('user_id', $user->id)->whereNull('read_at')
            ->when($ulid !== null, fn ($q) => $q->where('ulid', $ulid))->update(['read_at' => CarbonImmutable::now()]);
    }

    public function snooze(User $user, string $ulid, int $hours): void
    {
        DB::connection(Connections::cell())->table('user_notices')->where('user_id', $user->id)->where('ulid', $ulid)
            ->update(['snoozed_until' => CarbonImmutable::now()->addHours(max(1, min($hours, 24 * 14))), 'read_at' => null]);
    }

    /**
     * Kinds of alert this person almost never opens (the weekly "noisy alerts" tip).
     *
     * @return list<array{type: string, label: string, sent: int, opened: int}>
     */
    public function noisy(User $user): array
    {
        $rows = DB::connection(Connections::cell())->table('user_notices')->where('user_id', $user->id)->where('created_at', '>=', CarbonImmutable::now()->subDays(30))
            ->where('urgent', false)->selectRaw('type, count(*) as n, sum(case when read_at is not null then 1 else 0 end) as opened')->groupBy('type')->get();
        $out = [];
        foreach ($rows as $r) {
            if ((int) $r->n >= self::NOISY_MIN && (int) $r->opened / (int) $r->n < self::NOISY_SHARE && $this->pref($user, (string) $r->type)['email']) {
                $out[] = ['type' => (string) $r->type, 'label' => $this->types->label((string) $r->type), 'sent' => (int) $r->n, 'opened' => (int) $r->opened];
            }
        }

        return $out;
    }

    /**
     * Alerts held for quiet hours or the digest that are ready to go now, grouped by person.
     *
     * @return array<int, list<\stdClass>>
     */
    public function pendingEmail(): array
    {
        $out = [];
        foreach (DB::connection(Connections::cell())->table('user_notices')->where('email_pending', true)->orderBy('id')->get() as $n) {
            $out[(int) $n->user_id][] = $n;
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     */
    public function emailed(array $ids): void
    {
        DB::connection(Connections::cell())->table('user_notices')->whereIn('id', $ids)->update(['email_pending' => false]);
    }

    /**
     * @return Builder
     */
    private function visible(User $user)
    {
        return DB::connection(Connections::cell())->table('user_notices')->where('user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', CarbonImmutable::now()));
    }
}
