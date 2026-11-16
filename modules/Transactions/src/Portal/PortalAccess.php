<?php

declare(strict_types=1);

namespace Modules\Transactions\Portal;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\DealIq\DealAssistant;
use Modules\Transactions\Models\Deal;

/**
 * Who can open a deal's portal (specs/ai/02, §§6, 22): the agent invites the buyers or sellers and the other side by
 * email. There is no password: each sign-in is a single-use link (7 days from an invite, 30 minutes when asked
 * for again), stored only as a hash. The agent can remove someone at any time.
 */
final class PortalAccess
{
    public const CLIENT_ROLES = ['buyer', 'seller'];

    public const INVITE_DAYS = 7;

    public const LINK_MINUTES = 30;

    public function __construct(private readonly TenantContext $tenants) {}

    public static function isClient(string $role): bool
    {
        return in_array($role, self::CLIENT_ROLES, true);
    }

    /** Invites (or re-invites) someone to the deal's portal and emails them a sign-in link. */
    public function invite(Deal $deal, string $role, string $name, string $email, string $locale, User $by): string
    {
        $email = mb_strtolower(trim($email));
        if (! in_array($role, DealAssistant::ROLES, true) || trim($name) === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => 'Give their role, name and email address.']);
        }
        if ($deal->status === 'fell_through') {
            throw ValidationException::withMessages(['email' => 'This deal fell through.']);
        }
        $db = DB::connection(Connections::cell());
        $row = $db->table('tx_portal_access')->where('deal_id', $deal->id)->where('email', $email)->first();
        $values = ['role' => $role, 'name' => mb_substr(trim($name), 0, 160), 'locale' => Copy::locale($locale), 'revoked_at' => null, 'updated_at' => now()];
        if ($row === null) {
            $id = $db->table('tx_portal_access')->insertGetId($values + ['ulid' => (string) Str::ulid(), 'tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id,
                'email' => $email, 'invited_by' => $by->id, 'created_at' => now()]);
        } else {
            $db->table('tx_portal_access')->where('id', $row->id)->update($values);
            $id = (int) $row->id;
        }
        $token = $this->newLink($id, CarbonImmutable::now()->addDays(self::INVITE_DAYS));
        $locale = Copy::locale($locale);
        Notification::route('mail', $email)->notify(new PortalMail($locale, Copy::t($locale, 'mail.invite_subject', ['street' => $deal->street]),
            [Copy::t($locale, 'mail.invite_intro', ['agent' => $by->name, 'street' => $deal->street])], url('/my-deal/in/'.$token), (string) $by->email));
        app(AuditLogger::class)->record('transactions.portal.invited', $deal, metadata: ['role' => $role, 'to' => AuditLogger::mask($email)], label: $deal->street);

        return $token;
    }

    /**
     * Someone asks for a sign-in link. Every deal they are on gets one; nothing tells the asker whether the address
     * is known.
     */
    public function requestLink(string $email): int
    {
        $email = mb_strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 0;
        }
        $rows = DB::connection(Connections::cell())->table('tx_portal_access')->where('tenant_id', $this->tenants->id())->where('email', $email)->whereNull('revoked_at')->get();
        foreach ($rows as $row) {
            $deal = Deal::query()->whereKey((int) $row->deal_id)->first();
            if ($deal === null) {
                continue;
            }
            $token = $this->newLink((int) $row->id, CarbonImmutable::now()->addMinutes(self::LINK_MINUTES));
            $locale = Copy::locale((string) $row->locale);
            Notification::route('mail', $email)->notify(new PortalMail($locale, Copy::t($locale, 'mail.link_subject'),
                [Copy::t($locale, 'mail.link_intro', ['street' => $deal->street])], url('/my-deal/in/'.$token)));
        }

        return $rows->count();
    }

    /** Uses a sign-in link once; null when it was used, has expired or was removed. */
    public function login(string $token): ?\stdClass
    {
        $db = DB::connection(Connections::cell());
        $row = $db->table('tx_portal_access')->where('login_hash', hash('sha256', $token))->first();
        if ($row === null || $row->revoked_at !== null || $row->login_expires_at === null || CarbonImmutable::parse((string) $row->login_expires_at)->isPast()) {
            return null;
        }
        $db->table('tx_portal_access')->where('id', $row->id)->update(['login_hash' => null, 'login_expires_at' => null, 'last_seen_at' => now(), 'updated_at' => now()]);

        return $row;
    }

    /** The access behind a portal page, if it is still allowed. */
    public function find(string $ulid): ?\stdClass
    {
        return DB::connection(Connections::cell())->table('tx_portal_access')->where('ulid', $ulid)->whereNull('revoked_at')->first();
    }

    public function revoke(Deal $deal, string $ulid, User $by): void
    {
        $db = DB::connection(Connections::cell());
        $row = $db->table('tx_portal_access')->where('deal_id', $deal->id)->where('ulid', $ulid)->first() ?? abort(404);
        $db->table('tx_portal_access')->where('id', $row->id)->update(['revoked_at' => now(), 'login_hash' => null, 'login_expires_at' => null, 'updated_at' => now()]);
        app(AuditLogger::class)->record('transactions.portal.removed', $deal, metadata: ['role' => $row->role, 'to' => AuditLogger::mask((string) $row->email)], label: $deal->street);
    }

    /**
     * The client's own choices: language, and texts only with their opt-in (recorded with the time).
     */
    public function preferences(\stdClass $access, string $locale, ?string $phone, bool $sms): void
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
        if ($sms && strlen($digits) < 10) {
            throw ValidationException::withMessages(['phone' => 'Give a mobile number to get texts.']);
        }
        $e164 = $digits === '' ? null : (strlen($digits) === 10 ? '+1'.$digits : '+'.$digits);
        DB::connection(Connections::cell())->table('tx_portal_access')->where('id', $access->id)->update(['locale' => Copy::locale($locale), 'phone' => $e164,
            'sms_consent_at' => $sms ? ($access->sms_consent_at ?? now()) : null, 'updated_at' => now()]);
        $deal = Deal::query()->whereKey((int) $access->deal_id)->first();
        if ($deal !== null && ($sms xor $access->sms_consent_at !== null)) {
            app(AuditLogger::class)->record($sms ? 'transactions.portal.sms_opt_in' : 'transactions.portal.sms_opt_out', $deal,
                metadata: ['who' => AuditLogger::mask((string) $access->email)], label: $deal->street);
        }
    }

    /**
     * @return list<array{ulid: string, role: string, name: string, email: string, locale: string, texts: bool, last_seen: string|null, active: bool}>
     */
    public function forDeal(Deal $deal): array
    {
        return array_values(DB::connection(Connections::cell())->table('tx_portal_access')->where('deal_id', $deal->id)->orderBy('id')->get()
            ->map(fn ($r): array => ['ulid' => (string) $r->ulid, 'role' => (string) $r->role, 'name' => (string) $r->name, 'email' => (string) $r->email,
                'locale' => (string) $r->locale, 'texts' => $r->sms_consent_at !== null,
                'last_seen' => $r->last_seen_at !== null ? CarbonImmutable::parse((string) $r->last_seen_at)->toDateString() : null, 'active' => $r->revoked_at === null])->all());
    }

    private function newLink(int $accessId, CarbonImmutable $expires): string
    {
        $token = Str::random(48);
        DB::connection(Connections::cell())->table('tx_portal_access')->where('id', $accessId)->update(['login_hash' => hash('sha256', $token), 'login_expires_at' => $expires, 'updated_at' => now()]);

        return $token;
    }
}
