<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Throwable;

/**
 * Watchtower's switches (OB1), set by HQ in HQ › Watchtower. Every part can be turned off on its own: problem emails,
 * the daily report, the AI's explanations (and how many a day), browser errors and slow pages. Errors are always
 * recorded on the Watchtower page; the switches only decide what else happens. Sending errors to Sentry (or Bugsnag,
 * Honeybadger) is its own Off/Test/Live switch in HQ › Outside services.
 */
final class Settings
{
    public const DEFAULTS = ['recipients' => [], 'alerts' => true, 'digest' => true, 'ai' => true, 'ai_daily_cap' => 30, 'browser_errors' => true, 'slow_pages' => true];

    /** @var array<string, mixed>|null read at most once a minute per worker (long-running servers see changes within a minute) */
    private static ?array $cache = null;

    private static int $cachedAt = 0;

    public static function db(): ConnectionInterface
    {
        return DB::connection(Connections::control());
    }

    /** @return array{recipients: list<string>, alerts: bool, digest: bool, ai: bool, ai_daily_cap: int, browser_errors: bool, slow_pages: bool} */
    public static function all(): array
    {
        if (self::$cache === null || time() - self::$cachedAt > 60) {
            self::$cachedAt = time();
            try {
                $row = self::db()->table('watchtower_settings')->first();
            } catch (Throwable) {
                $row = null; // before the tables exist (a fresh install): the defaults
            }
            $saved = $row === null ? [] : ['recipients' => json_decode((string) ($row->recipients ?? '[]'), true), 'alerts' => (bool) $row->alerts, 'digest' => (bool) $row->digest,
                'ai' => (bool) $row->ai, 'ai_daily_cap' => (int) $row->ai_daily_cap, 'browser_errors' => (bool) $row->browser_errors, 'slow_pages' => (bool) $row->slow_pages];
            self::$cache = $saved + self::DEFAULTS;
        }
        $s = self::$cache;
        $emails = is_array($s['recipients']) && $s['recipients'] !== [] ? $s['recipients'] : self::fallbackRecipients();

        return ['recipients' => array_values(array_filter($emails, 'is_string')), 'alerts' => (bool) $s['alerts'], 'digest' => (bool) $s['digest'], 'ai' => (bool) $s['ai'],
            'ai_daily_cap' => (int) $s['ai_daily_cap'], 'browser_errors' => (bool) $s['browser_errors'], 'slow_pages' => (bool) $s['slow_pages']];
    }

    public static function on(string $switch): bool
    {
        return (bool) (self::all()[$switch] ?? false);
    }

    /**
     * HQ saves the switches and who gets the emails (up to 5 addresses).
     *
     * @param  array{recipients: list<string>, alerts: bool, digest: bool, ai: bool, ai_daily_cap: int, browser_errors: bool, slow_pages: bool}  $values
     */
    public static function save(array $values, ?int $by): void
    {
        $row = ['recipients' => json_encode(array_values(array_unique(array_map(fn (string $e): string => mb_strtolower(trim($e)), $values['recipients'])))),
            'alerts' => $values['alerts'], 'digest' => $values['digest'], 'ai' => $values['ai'], 'ai_daily_cap' => max(0, min(500, $values['ai_daily_cap'])),
            'browser_errors' => $values['browser_errors'], 'slow_pages' => $values['slow_pages'], 'updated_by' => $by, 'updated_at' => now()];
        self::write($row);
        app(AuditLogger::class)->record('watchtower.settings', metadata: array_diff_key($row, ['updated_at' => 1, 'updated_by' => 1]), severity: 'notice');
    }

    /** The uptime checker's secret webhook address token; empty until HQ makes one. */
    public static function uptimeToken(): string
    {
        try {
            $saved = self::db()->table('watchtower_settings')->value('uptime_token');

            return is_string($saved) && $saved !== '' ? Crypt::decryptString($saved) : '';
        } catch (Throwable) {
            return '';
        }
    }

    /** A new uptime address: the old one stops working at once. */
    public static function rotateUptimeToken(?int $by): string
    {
        $token = Str::random(40);
        self::write(['uptime_token' => Crypt::encryptString($token), 'updated_by' => $by, 'updated_at' => now()]);
        app(AuditLogger::class)->record('watchtower.uptime_address', severity: 'notice');

        return $token;
    }

    public static function forget(): void
    {
        self::$cache = null;
    }

    /**
     * Until HQ names people: the addresses in WATCHTOWER_EMAILS on the server (comma-separated), if any.
     *
     * @return list<string>
     */
    private static function fallbackRecipients(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config('core.watchtower.emails', ''))), fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false));
    }

    /** @param array<string, mixed> $values */
    private static function write(array $values): void
    {
        $table = self::db()->table('watchtower_settings');
        $table->clone()->exists() ? $table->clone()->update($values) : $table->clone()->insert($values + ['created_at' => now()]);
        self::$cache = null;
    }
}
