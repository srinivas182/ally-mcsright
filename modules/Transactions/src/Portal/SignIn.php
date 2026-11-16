<?php

declare(strict_types=1);

namespace Modules\Transactions\Portal;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Messaging\MessagingSettings;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Deadlines\Deadlines;
use Modules\Transactions\Models\Deal;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Signing in to the deal portal (specs/ai/02, DQ3). The emailed link alone never signs anyone in:
 *
 * 1. Opening the link changes nothing (so email security scanners cannot use it up). The page asks for the email
 *    address the link was sent to.
 * 2. A 6-digit code goes to that address, or by text to a mobile the client opted in with. It works for 10
 *    minutes; 5 wrong tries (codes or addresses) and the link stops working.
 * 3. The right code signs this browser in for 12 hours, or 30 days when the client remembers the device. Each
 *    sign-in emails the client, with a "this was not me" link that signs out every device and tells the agent.
 *
 * Removing someone, or "this was not me", ends every session and remembered device at once.
 */
final class SignIn
{
    public const SESSION = 'deal_portal';

    public const COOKIE = 'deal_portal_device';

    public const CODE_MINUTES = 10;

    public const MAX_TRIES = 5;

    public const SESSION_HOURS = 12;

    public const DEVICE_DAYS = 30;

    public function __construct(private readonly TenantContext $tenants) {}

    /** The access a sign-in link belongs to, while the link can still be used (nothing is changed). */
    public function pending(string $token): ?\stdClass
    {
        $row = DB::connection(Connections::cell())->table('tx_portal_access')->where('login_hash', hash('sha256', $token))->first();

        return $row === null || $row->revoked_at !== null || $row->login_expires_at === null || CarbonImmutable::parse((string) $row->login_expires_at)->isPast() ? null : $row;
    }

    /** Whether a code can be texted to this person (their own opt-in, a mobile, and texts allowed for the brokerage). */
    public function canText(\stdClass $access): bool
    {
        return $access->sms_consent_at !== null && $access->phone !== null && app(MessagingSettings::class)->smsFor($this->tenants->id());
    }

    /**
     * Step 2: they typed the address the link was sent to; a code goes there (or to their phone).
     *
     * @return string where the code went, for the page ("a•••@example.com", "•••0111")
     */
    public function sendCode(string $token, string $email, string $via): string
    {
        $row = $this->pending($token) ?? throw ValidationException::withMessages(['email' => 'expired']);
        if ((int) $row->code_attempts >= self::MAX_TRIES) {
            $this->lock($row);
        }
        if (! hash_equals((string) $row->email, mb_strtolower(trim($email)))) {
            $this->failed($row, 'email');
            throw ValidationException::withMessages(['email' => 'wrong_email']);
        }
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        DB::connection(Connections::cell())->table('tx_portal_access')->where('id', $row->id)->update(['code_hash' => self::hashCode((int) $row->id, $code),
            'code_expires_at' => CarbonImmutable::now()->addMinutes(self::CODE_MINUTES), 'updated_at' => now()]);
        $deal = Deal::query()->whereKey((int) $row->deal_id)->firstOrFail();
        $locale = Copy::locale((string) $row->locale);
        if ($via === 'sms' && $this->canText($row) && $this->awake()) {
            app(SmsSender::class)->send((string) $row->phone, Copy::t($locale, 'sms.code', ['code' => $code, 'street' => $deal->street]));

            return '•••'.substr((string) $row->phone, -4);
        }
        Notification::route('mail', (string) $row->email)->notify(new PortalCodeMail($locale, $code, $deal->street));

        return self::maskEmail((string) $row->email);
    }

    /**
     * Step 3: the code. Signs this browser in; returns the remembered-device cookie when asked for.
     *
     * @return array{access: \stdClass, cookie: Cookie|null}
     */
    public function verify(Request $request, string $token, string $code, bool $remember): array
    {
        $row = $this->pending($token) ?? throw ValidationException::withMessages(['code' => 'expired']);
        if ((int) $row->code_attempts >= self::MAX_TRIES) {
            $this->lock($row);
        }
        if ($row->code_hash === null || $row->code_expires_at === null || CarbonImmutable::parse((string) $row->code_expires_at)->isPast()) {
            throw ValidationException::withMessages(['code' => 'code_expired']);
        }
        if (! hash_equals((string) $row->code_hash, self::hashCode((int) $row->id, preg_replace('/\D/', '', $code) ?? ''))) {
            $left = self::MAX_TRIES - $this->failed($row, 'code');
            throw ValidationException::withMessages(['code' => $left <= 0 ? 'code_locked' : 'code_wrong:'.$left]);
        }
        $db = DB::connection(Connections::cell());
        $db->table('tx_portal_access')->where('id', $row->id)->update(['login_hash' => null, 'login_expires_at' => null, 'code_hash' => null, 'code_expires_at' => null,
            'code_attempts' => 0, 'last_seen_at' => now(), 'updated_at' => now()]);
        $label = self::device((string) $request->userAgent());
        $report = Str::random(48);
        $cookie = null;
        $until = CarbonImmutable::now()->addHours(self::SESSION_HOURS);
        $deviceToken = Str::random(48);
        $deviceId = $db->table('tx_portal_devices')->insertGetId(['tenant_id' => $row->tenant_id, 'access_id' => $row->id, 'token_hash' => hash('sha256', $deviceToken),
            'report_hash' => hash('sha256', $report), 'label' => $label, 'expires_at' => $remember ? CarbonImmutable::now()->addDays(self::DEVICE_DAYS) : $until,
            'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        if ($remember) {
            $tokens = array_slice(array_filter(explode('.', self::cookieValue($request))), -4);
            $cookie = cookie(self::COOKIE, implode('.', [...$tokens, $deviceToken]), self::DEVICE_DAYS * 24 * 60, '/my-deal', null, $request->isSecure(), true, false, 'lax');
        }
        $this->remember($request, (int) $row->id, (int) $deviceId, $remember ? CarbonImmutable::now()->addDays(self::DEVICE_DAYS) : $until);
        $deal = Deal::query()->whereKey((int) $row->deal_id)->firstOrFail();
        $locale = Copy::locale((string) $row->locale);
        $tz = $this->tenants->current()->timezone !== '' ? $this->tenants->current()->timezone : 'America/Chicago';
        Notification::route('mail', (string) $row->email)->notify(new PortalMail($locale, Copy::t($locale, 'mail.new_device_subject'),
            [Copy::t($locale, 'mail.new_device_intro', ['street' => $deal->street, 'device' => $label, 'time' => CarbonImmutable::now($tz)->format('M j, g:i A T')])],
            url('/my-deal/not-me/'.$report), null, 'mail.not_me'));
        app(AuditLogger::class)->record('transactions.portal.signed_in', $deal, metadata: ['who' => AuditLogger::mask((string) $row->email), 'device' => $label, 'remembered' => $remember], label: $deal->street);

        return ['access' => $row, 'cookie' => $cookie];
    }

    /** Signs a remembered browser back in to every deal it remembers (no link needed). */
    public function fromDevice(Request $request): void
    {
        $tokens = array_filter(explode('.', self::cookieValue($request)));
        if ($tokens === []) {
            return;
        }
        $db = DB::connection(Connections::cell());
        $devices = $db->table('tx_portal_devices')->whereIn('token_hash', array_map(fn (string $t): string => hash('sha256', $t), $tokens))
            ->where('tenant_id', $this->tenants->id())->whereNull('revoked_at')->where('expires_at', '>', now())->get();
        foreach ($devices as $d) {
            $access = $db->table('tx_portal_access')->where('id', $d->access_id)->whereNull('revoked_at')->first();
            if ($access === null || ($access->sessions_after !== null && CarbonImmutable::parse((string) $d->created_at)->lt(CarbonImmutable::parse((string) $access->sessions_after)))) {
                continue;
            }
            if (! $this->has($request, (int) $access->id)) {
                $this->remember($request, (int) $access->id, (int) $d->id, CarbonImmutable::parse((string) $d->expires_at));
            }
            $db->table('tx_portal_devices')->where('id', $d->id)->update(['last_seen_at' => now()]);
        }
    }

    /** Whether this browser is signed in to this access right now. */
    public function allowed(Request $request, \stdClass $access): bool
    {
        $entry = collect($this->entries($request))->firstWhere('a', (int) $access->id);
        if ($entry === null || $entry['until'] < CarbonImmutable::now()->getTimestamp() || $access->revoked_at !== null) {
            return false;
        }
        if ($access->sessions_after !== null && $entry['at'] < CarbonImmutable::parse((string) $access->sessions_after)->getTimestamp()) {
            return false;
        }

        return DB::connection(Connections::cell())->table('tx_portal_devices')->where('id', $entry['d'])->whereNull('revoked_at')->exists();
    }

    /**
     * The accesses this browser is signed in to.
     *
     * @return list<int>
     */
    public function signedIn(Request $request): array
    {
        return array_values(array_map(fn (array $e): int => $e['a'], array_filter($this->entries($request), fn (array $e): bool => $e['until'] >= CarbonImmutable::now()->getTimestamp())));
    }

    /** Signs every browser out of this access (removed by the agent, or "this was not me"). */
    public function everywhere(\stdClass $access): void
    {
        $db = DB::connection(Connections::cell());
        $db->table('tx_portal_devices')->where('access_id', $access->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        $db->table('tx_portal_access')->where('id', $access->id)->update(['sessions_after' => now(), 'login_hash' => null, 'login_expires_at' => null, 'code_hash' => null,
            'code_expires_at' => null, 'updated_at' => now()]);
    }

    /** The access behind a "this was not me" link, if it is real. */
    public function reported(string $token): ?\stdClass
    {
        $db = DB::connection(Connections::cell());
        $device = $db->table('tx_portal_devices')->where('report_hash', hash('sha256', $token))->first();

        return $device === null ? null : $db->table('tx_portal_access')->where('id', $device->access_id)->first();
    }

    /** "This was not me": everything is signed out and the agent is told. */
    public function notMe(string $token): bool
    {
        $access = $this->reported($token);
        if ($access === null) {
            return false;
        }
        $this->everywhere($access);
        $deal = Deal::query()->whereKey((int) $access->deal_id)->first();
        if ($deal !== null) {
            User::query()->whereKey($deal->agent_user_id)->first()?->notify(new PortalSecurityMail((string) $access->name, $deal->street, '/deals/'.$deal->ulid));
            app(AuditLogger::class)->record('transactions.portal.not_me', $deal, metadata: ['who' => AuditLogger::mask((string) $access->email)], severity: 'warning', label: $deal->street);
        }

        return true;
    }

    public function signOut(Request $request): void
    {
        $ids = array_map(fn (array $e): int => $e['d'], $this->entries($request));
        DB::connection(Connections::cell())->table('tx_portal_devices')->whereIn('id', $ids)->update(['revoked_at' => now(), 'updated_at' => now()]);
        $request->session()->forget(self::SESSION);
        $request->session()->regenerate();
    }

    public static function maskEmail(string $email): string
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($user, 0, 1).'•••@'.$domain;
    }

    /** "Chrome on Windows", "Safari on iPhone" … */
    public static function device(string $agent): string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge', str_contains($agent, 'Firefox/') => 'Firefox', str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari', default => 'A browser',
        };
        $os = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone', str_contains($agent, 'iPad') => 'iPad', str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows', str_contains($agent, 'Mac OS') => 'Mac', str_contains($agent, 'Linux') => 'Linux', default => 'an unknown device',
        };

        return $browser.' on '.$os;
    }

    private static function cookieValue(Request $request): string
    {
        $v = $request->cookie(self::COOKIE);

        return is_string($v) ? $v : '';
    }

    private static function hashCode(int $accessId, string $code): string
    {
        return hash_hmac('sha256', $accessId.'|'.$code, (string) config('app.key'));
    }

    /** Counts a wrong try; returns the tries so far. Five ends the link. */
    private function failed(\stdClass $row, string $what): int
    {
        $db = DB::connection(Connections::cell());
        $db->table('tx_portal_access')->where('id', $row->id)->increment('code_attempts');
        $tries = (int) $row->code_attempts + 1;
        if ($tries >= self::MAX_TRIES) {
            $db->table('tx_portal_access')->where('id', $row->id)->update(['login_hash' => null, 'login_expires_at' => null, 'code_hash' => null, 'code_expires_at' => null,
                'code_attempts' => 0, 'updated_at' => now()]);
            $deal = Deal::query()->whereKey((int) $row->deal_id)->first();
            if ($deal !== null) {
                app(AuditLogger::class)->record('transactions.portal.locked', $deal, metadata: ['who' => AuditLogger::mask((string) $row->email), 'last' => $what], severity: 'warning', label: $deal->street);
            }
        }

        return $tries;
    }

    private function lock(\stdClass $row): never
    {
        $this->failed($row, 'locked');

        throw ValidationException::withMessages(['code' => 'code_locked']);
    }

    private function awake(): bool
    {
        $tenant = $this->tenants->current();
        $hour = CarbonImmutable::now($tenant->timezone !== '' ? $tenant->timezone : 'America/Chicago')->hour;

        return $hour >= Deadlines::QUIET_UNTIL && $hour < Deadlines::QUIET_FROM;
    }

    private function remember(Request $request, int $accessId, int $deviceId, CarbonImmutable $until): void
    {
        $entries = array_values(array_filter($this->entries($request), fn (array $e): bool => $e['a'] !== $accessId));
        $entries[] = ['a' => $accessId, 'd' => $deviceId, 'at' => CarbonImmutable::now()->getTimestamp(), 'until' => $until->getTimestamp()];
        $request->session()->put(self::SESSION, $entries);
    }

    private function has(Request $request, int $accessId): bool
    {
        return collect($this->entries($request))->contains(fn (array $e): bool => $e['a'] === $accessId && $e['until'] >= CarbonImmutable::now()->getTimestamp());
    }

    /**
     * @return list<array{a: int, d: int, at: int, until: int}>
     */
    private function entries(Request $request): array
    {
        $out = [];
        foreach ((array) $request->session()->get(self::SESSION, []) as $e) {
            if (is_array($e) && isset($e['a'], $e['d'], $e['at'], $e['until'])) {
                $out[] = ['a' => (int) $e['a'], 'd' => (int) $e['d'], 'at' => (int) $e['at'], 'until' => (int) $e['until']];
            }
        }

        return $out;
    }
}
