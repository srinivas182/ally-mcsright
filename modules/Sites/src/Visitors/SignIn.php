<?php

declare(strict_types=1);

namespace Modules\Sites\Visitors;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Mail\SignInCodeMail;
use Modules\Sites\Models\Site;

/**
 * Passwordless sign-in for website visitors (specs/websites/07): a 6-digit code by email, valid 15 minutes, five
 * tries, stored only as a keyed hash. Sending is rate limited per address and per network.
 */
final class SignIn
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** Sends a code; quietly does nothing when limits are reached (the visitor sees the same message either way). */
    public function start(Site $site, string $email, string $ip): bool
    {
        $email = mb_strtolower(trim($email));
        $tenant = $this->tenants->id();
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || ! RateLimiter::attempt("site-code:{$tenant}:".hash('sha256', $email), 5, fn (): bool => true, 900)
            || ! RateLimiter::attempt("site-code-ip:{$tenant}:{$ip}", 20, fn (): bool => true, 3600)) {
            return false;
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $db = DB::connection(Connections::cell())->table('site_login_codes');
        $db->where('tenant_id', $tenant)->where('email', $email)->delete();
        $db->insert(['tenant_id' => $tenant, 'email' => $email, 'code_hash' => self::hash($email, $code), 'attempts' => 0,
            'expires_at' => CarbonImmutable::now()->addMinutes(15), 'created_at' => CarbonImmutable::now()]);
        Mail::to($email)->send(new SignInCodeMail($site->displayName(), $code));

        return true;
    }

    public function verify(string $email, string $code): bool
    {
        $email = mb_strtolower(trim($email));
        $db = DB::connection(Connections::cell())->table('site_login_codes');
        $row = $db->where('tenant_id', $this->tenants->id())->where('email', $email)->where('expires_at', '>', CarbonImmutable::now())->orderByDesc('id')->first();
        if ($row === null || (int) $row->attempts >= 5) {
            return false;
        }
        if (! hash_equals((string) $row->code_hash, self::hash($email, preg_replace('/\D/', '', $code) ?? ''))) {
            DB::connection(Connections::cell())->table('site_login_codes')->where('id', $row->id)->increment('attempts');

            return false;
        }
        DB::connection(Connections::cell())->table('site_login_codes')->where('tenant_id', $this->tenants->id())->where('email', $email)->delete();

        return true;
    }

    private static function hash(string $email, string $code): string
    {
        return hash_hmac('sha256', $email.'|'.$code, (string) config('app.key'));
    }
}
