<?php

declare(strict_types=1);

namespace Modules\Billing\Affiliates;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use stdClass;

/**
 * RightAlly's own referral programme (RF1, specs/billing/08).
 *
 * - People (coaches, trainers, influencers) get a link and earn a share (20% to start) of what a brokerage they refer
 *   pays in its first year, as cash paid by HQ.
 * - A brokerage that refers another earns a share (20% to start) of the same, as credit on its own RightAlly bill.
 * - HQ changes both shares in HQ › Referral programme (PB3); a change applies to payments from then on.
 * - Rewards wait 30 days after the new brokerage's first payment (the refund window), then become approved, or void
 *   if it cancelled. HQ pays approved rewards and marks them paid. Nothing pays itself.
 * - Fraud checks: no rewards for referring yourself (same email, same company email domain, or your own brokerage).
 *
 * This is about brokerages joining RightAlly. RightAlly never takes a share of agent-to-agent referral fees.
 */
final class Affiliates
{
    public const DEFAULT_PERCENT = 20;

    public const HOLD_DAYS = 30;

    public const COOKIE = 'ra_ref';

    public const COOKIE_DAYS = 60;

    /** Free email providers: a shared domain here doesn't mean the same company. */
    private const PUBLIC_DOMAINS = ['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'icloud.com', 'aol.com', 'live.com', 'msn.com', 'me.com', 'protonmail.com'];

    /**
     * Adds a person (coach, influencer). Returns their dashboard token, shown once.
     *
     * @return array{code: string, token: string}
     */
    public function addPerson(string $name, string $email, ?int $byUserId): array
    {
        $email = mb_strtolower(trim($email));
        if (DB::connection(Connections::control())->table('affiliates')->where('kind', 'person')->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'This person already has a referral link.']);
        }
        $token = Str::random(40);
        $code = $this->newCode($name);
        DB::connection(Connections::control())->table('affiliates')->insert(['ulid' => (string) Str::ulid(), 'kind' => 'person', 'code' => $code,
            'name' => mb_substr(trim($name), 0, 160), 'email' => $email, 'token_hash' => hash('sha256', $token), 'status' => 'active',
            'created_by' => $byUserId, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('billing.affiliate.added', metadata: ['code' => $code], severity: 'notice');

        return ['code' => $code, 'token' => $token];
    }

    /** The brokerage's own referral link, made the first time it is asked for. */
    public function forBrokerage(Tenant $tenant, string $ownerEmail): stdClass
    {
        $db = DB::connection(Connections::control())->table('affiliates');
        $row = $db->clone()->where('tenant_id', $tenant->id)->first();
        if ($row === null) {
            $db->clone()->insertOrIgnore(['ulid' => (string) Str::ulid(), 'kind' => 'brokerage', 'code' => $this->newCode($tenant->name), 'name' => mb_substr($tenant->name, 0, 160),
                'email' => mb_strtolower($ownerEmail), 'tenant_id' => $tenant->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $row = $db->clone()->where('tenant_id', $tenant->id)->first();
        }

        return $row ?? throw new \RuntimeException('Referral link could not be made.');
    }

    public function find(string $code): ?stdClass
    {
        return DB::connection(Connections::control())->table('affiliates')->where('code', mb_strtolower($code))->where('status', 'active')->first();
    }

    public function byToken(string $token): ?stdClass
    {
        return DB::connection(Connections::control())->table('affiliates')->where('kind', 'person')->where('token_hash', hash('sha256', $token))->first();
    }

    /** Counts a visit to a referral link (a daily count only; nothing about the visitor is kept). */
    public function click(stdClass $affiliate): void
    {
        $db = DB::connection(Connections::control())->table('affiliate_clicks');
        $key = ['affiliate_id' => $affiliate->id, 'day' => CarbonImmutable::today()->toDateString()];
        $db->clone()->insertOrIgnore($key + ['clicks' => 0]);
        $db->clone()->where($key)->increment('clicks');
    }

    /** A signup referred by $code went live as $tenant: the referral is recorded, unless it is a self-referral. */
    public function attribute(?string $code, Tenant $tenant, string $ownerEmail): void
    {
        $affiliate = $code !== null ? $this->find($code) : null;
        if ($affiliate === null) {
            return;
        }
        $why = $this->selfReferral($affiliate, $tenant, $ownerEmail);
        DB::connection(Connections::control())->table('affiliate_referrals')->insertOrIgnore(['affiliate_id' => $affiliate->id, 'tenant_id' => $tenant->id,
            'status' => $why === null ? 'pending' : 'void', 'reward_kind' => $affiliate->kind === 'brokerage' ? 'credit' : 'cash', 'note' => $why,
            'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('billing.affiliate.referred', metadata: ['code' => $affiliate->code, 'tenant' => $tenant->id, 'void' => $why], severity: 'notice');
    }

    /**
     * The shares HQ set: what a person and a brokerage earn of a referred brokerage's first-year payments.
     *
     * @return array{person: int, brokerage: int}
     */
    public static function rewards(): array
    {
        $row = DB::connection(Connections::control())->table('credit_settings')->first();

        return ['person' => (int) ($row->referral_person_pct ?? self::DEFAULT_PERCENT), 'brokerage' => (int) ($row->referral_brokerage_pct ?? self::DEFAULT_PERCENT)];
    }

    /** HQ changes the shares (0–50%). */
    public function saveRewards(int $person, int $brokerage, ?int $byUserId): void
    {
        if ($person < 0 || $person > 50 || $brokerage < 0 || $brokerage > 50) {
            throw ValidationException::withMessages(['person' => 'Choose a share from 0% to 50%.']);
        }
        $db = DB::connection(Connections::control())->table('credit_settings');
        $values = ['referral_person_pct' => $person, 'referral_brokerage_pct' => $brokerage, 'updated_by' => $byUserId, 'updated_at' => now()];
        $db->clone()->exists() ? $db->clone()->update($values) : $db->clone()->insert($values + ['created_at' => now()]);
        app(AuditLogger::class)->record('billing.affiliate.rewards', metadata: ['person_pct' => $person, 'brokerage_pct' => $brokerage], severity: 'warning');
    }

    /** The referred brokerage paid: the reward grows (first year only). */
    public function paid(int $tenantId, int $amountCents): void
    {
        $db = DB::connection(Connections::control())->table('affiliate_referrals');
        $r = $db->clone()->where('tenant_id', $tenantId)->whereIn('status', ['pending', 'approved'])->first();
        if ($r === null || $amountCents <= 0) {
            return;
        }
        $first = $r->first_paid_at !== null ? CarbonImmutable::parse((string) $r->first_paid_at) : CarbonImmutable::now();
        if ($first->addYear()->isPast()) {
            return; // only the first year counts
        }
        $paid = (int) $r->paid_cents + $amountCents;
        $shares = self::rewards();
        $reward = (int) $r->reward_cents + intdiv($amountCents * ($r->reward_kind === 'cash' ? $shares['person'] : $shares['brokerage']), 100);
        $db->clone()->where('id', $r->id)->update(['paid_cents' => $paid, 'reward_cents' => $reward, 'first_paid_at' => $first, 'updated_at' => now()]);
    }

    /**
     * Daily: rewards past the 30-day hold become approved, or void if the brokerage has already left.
     *
     * @return array{approved: int, void: int}
     */
    public function settle(): array
    {
        $out = ['approved' => 0, 'void' => 0];
        $db = DB::connection(Connections::control());
        foreach ($db->table('affiliate_referrals')->where('status', 'pending')->whereNotNull('first_paid_at')->where('first_paid_at', '<=', CarbonImmutable::now()->subDays(self::HOLD_DAYS))->get() as $r) {
            $active = $db->table('billing_tenant_subscriptions')->where('tenant_id', $r->tenant_id)->whereIn('status', ['active', 'grace'])->exists();
            $db->table('affiliate_referrals')->where('id', $r->id)->update(['status' => $active ? 'approved' : 'void', 'note' => $active ? null : 'Cancelled within 30 days',
                'settled_at' => now(), 'updated_at' => now()]);
            $out[$active ? 'approved' : 'void']++;
        }

        return $out;
    }

    /** HQ paid an approved reward (cash sent, or the credit applied). */
    public function markPaid(int $referralId, string $note, ?int $byUserId): void
    {
        $n = DB::connection(Connections::control())->table('affiliate_referrals')->where('id', $referralId)->where('status', 'approved')
            ->update(['status' => 'paid', 'note' => mb_substr(trim($note), 0, 255), 'updated_at' => now()]);
        if ($n === 0) {
            throw ValidationException::withMessages(['referral' => 'Only approved rewards can be marked paid.']);
        }
        app(AuditLogger::class)->record('billing.affiliate.paid', metadata: ['referral' => $referralId, 'by' => $byUserId], severity: 'warning');
    }

    /**
     * What an affiliate sees: clicks, brokerages that joined, and rewards (names of brokerages are not shown to people).
     *
     * @return array{clicks_30d: int, joined: int, pending_cents: int, approved_cents: int, paid_cents: int, referrals: list<array{brokerage: string, status: string, reward_cents: int, joined: string}>}
     */
    public function stats(stdClass $affiliate, bool $showNames): array
    {
        $db = DB::connection(Connections::control());
        $refs = $db->table('affiliate_referrals')->where('affiliate_id', $affiliate->id)->orderByDesc('id')->get();
        $names = $showNames ? Tenant::query()->whereIn('id', $refs->pluck('tenant_id')->all())->pluck('name', 'id') : collect();
        $sum = fn (string $status): int => (int) $refs->where('status', $status)->sum('reward_cents');

        return [
            'clicks_30d' => (int) $db->table('affiliate_clicks')->where('affiliate_id', $affiliate->id)->where('day', '>=', CarbonImmutable::today()->subDays(30)->toDateString())->sum('clicks'),
            'joined' => $refs->where('status', '!=', 'void')->count(),
            'pending_cents' => $sum('pending'), 'approved_cents' => $sum('approved'), 'paid_cents' => $sum('paid'),
            'referrals' => array_values($refs->map(fn ($r): array => ['brokerage' => (string) ($names[$r->tenant_id] ?? 'A brokerage'), 'status' => (string) $r->status,
                'reward_cents' => (int) $r->reward_cents, 'joined' => substr((string) $r->created_at, 0, 10)])->all()),
        ];
    }

    private function selfReferral(stdClass $affiliate, Tenant $tenant, string $ownerEmail): ?string
    {
        $owner = mb_strtolower(trim($ownerEmail));
        $domain = static fn (string $e): string => (string) substr((string) strrchr($e, '@'), 1);
        if ((int) ($affiliate->tenant_id ?? 0) === $tenant->id) {
            return 'Self-referral: the same brokerage';
        }
        if ((string) $affiliate->email === $owner) {
            return 'Self-referral: the same email address';
        }
        $d = $domain((string) $affiliate->email);
        if ($d !== '' && $d === $domain($owner) && ! in_array($d, self::PUBLIC_DOMAINS, true)) {
            return 'Self-referral: the same company email domain';
        }

        return null;
    }

    private function newCode(string $name): string
    {
        $base = trim(substr((string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower(Str::ascii($name))), 0, 16)) ?: 'partner';
        $db = DB::connection(Connections::control())->table('affiliates');
        $code = $base;
        while ($db->clone()->where('code', $code)->exists()) {
            $code = $base.random_int(10, 9999);
        }

        return $code;
    }
}
