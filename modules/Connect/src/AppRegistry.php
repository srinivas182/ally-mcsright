<?php

declare(strict_types=1);

namespace Modules\Connect;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Connect\OAuth\Providers;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\App;
use Modules\Core\Support\Connections;
use Throwable;

/**
 * HQ's switch for every app (specs/connect/00). An app is **ready** for brokers and agents when it is built, HQ has
 * enabled it, and its setup is complete (a shared sign-in app, or the RightAlly-level keys it needs). Built-in apps
 * (CSV, webhooks, Zapier, lead API and email) are on unless HQ switches them off; every other app starts off.
 * Switching an app off pauses it everywhere: no new connections, no syncs, no deliveries (saved data is kept).
 */
final class AppRegistry
{
    private const CACHE = 'hq-connect-apps';

    /** @return array<string, array{enabled: bool, credentials: string|null, secret_expires_on: string|null, updated_at: string|null}> */
    private function rows(): array
    {
        try {
            return Cache::remember(self::CACHE, now()->addMinutes(5), fn (): array => DB::connection(Connections::control())->table('hq_connect_apps')->get()
                ->mapWithKeys(fn (object $r): array => [(string) $r->app_key => ['enabled' => (bool) $r->enabled, 'credentials' => $r->credentials !== null ? (string) $r->credentials : null,
                    'secret_expires_on' => $r->secret_expires_on !== null ? (string) $r->secret_expires_on : null, 'updated_at' => $r->updated_at !== null ? (string) $r->updated_at : null]])->all());
        } catch (Throwable) {
            return [];
        }
    }

    public function enabled(App $app): bool
    {
        return $this->rows()[$app->key]['enabled'] ?? $app->builtIn();
    }

    public function setupComplete(App $app): bool
    {
        return match ($app->setup['needs'] ?? 'none') {
            'provider' => Providers::configured((string) ($app->setup['provider'] ?? $app->oauth['provider'] ?? '')),
            'keys' => array_diff(array_column($app->setup['fields'] ?? [], 'key'), array_keys(array_filter($this->secrets($app), fn (string $v): bool => $v !== ''))) === [],
            default => true,
        };
    }

    public function ready(App $app): bool
    {
        return $app->available() && $this->enabled($app) && $this->setupComplete($app);
    }

    /** @return array<string, string> the app's RightAlly-level keys (for its driver only; never sent to a browser) */
    public function secrets(App $app): array
    {
        $raw = $this->rows()[$app->key]['credentials'] ?? null;
        try {
            $decoded = $raw !== null ? json_decode(Crypt::decryptString($raw), true) : [];
        } catch (Throwable) {
            $decoded = [];
        }

        return is_array($decoded) ? array_map('strval', array_filter($decoded, 'is_scalar')) : [];
    }

    /**
     * What HQ sees for an app (keys only say whether they are set).
     *
     * @return array{enabled: bool, ready: bool, setup_complete: bool, keys_set: array<string, bool>, secret_expires_on: string|null, updated_at: string|null}
     */
    public function status(App $app): array
    {
        $secrets = $this->secrets($app);
        $row = $this->rows()[$app->key] ?? null;

        return ['enabled' => $this->enabled($app), 'ready' => $this->ready($app), 'setup_complete' => $this->setupComplete($app),
            'keys_set' => array_map(fn (array $f): bool => ($secrets[$f['key']] ?? '') !== '', array_column($app->setup['fields'] ?? [], null, 'key')),
            'secret_expires_on' => $row['secret_expires_on'] ?? null, 'updated_at' => $row['updated_at'] ?? null];
    }

    /**
     * HQ switches an app on or off and saves its keys (blank boxes keep what is saved).
     *
     * @param  array<string, mixed>  $keys
     */
    public function save(App $app, bool $enabled, array $keys, ?string $expiresOn, User $by): void
    {
        if ($enabled && ! $app->available()) {
            throw ValidationException::withMessages(['enabled' => $app->name.' isn\'t built yet, so it can\'t be switched on.']);
        }
        $secrets = $this->secrets($app);
        foreach ($app->setup['fields'] ?? [] as $f) {
            $v = is_scalar($keys[$f['key']] ?? null) ? trim((string) $keys[$f['key']]) : '';
            if ($v !== '') {
                $secrets[$f['key']] = mb_substr($v, 0, 1000);
            }
        }
        $old = $this->rows()[$app->key] ?? null;
        DB::connection(Connections::control())->table('hq_connect_apps')->updateOrInsert(['app_key' => $app->key], [
            'enabled' => $enabled, 'credentials' => $secrets !== [] ? Crypt::encryptString((string) json_encode($secrets)) : null,
            'secret_expires_on' => $expiresOn !== null && $expiresOn !== '' ? CarbonImmutable::parse($expiresOn)->toDateString() : null,
            'updated_by' => $by->id, 'updated_at' => now(), 'created_at' => now()]);
        Cache::forget(self::CACHE);
        if ($enabled && ! $this->setupComplete($app)) {
            app(AuditLogger::class)->record('connect.hq_app_saved', metadata: ['app' => $app->key, 'enabled' => true, 'setup_complete' => false], severity: 'warning');

            return;
        }
        app(AuditLogger::class)->record('connect.hq_app_saved', metadata: ['app' => $app->key, 'enabled' => $enabled, 'was_enabled' => $old['enabled'] ?? null,
            'keys_changed' => array_keys(array_filter($keys, fn ($v): bool => is_scalar($v) && trim((string) $v) !== ''))], severity: 'warning');
    }
}
