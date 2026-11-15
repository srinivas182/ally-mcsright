<?php

declare(strict_types=1);

namespace Modules\Connect\OAuth;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Throwable;

/**
 * RightAlly's Google and Microsoft sign-in apps, set by HQ on the Connect setup screen (specs/connect/02). They are
 * used for one-click connect and for "Sign in with Google/Microsoft". What HQ saves wins over the server's
 * environment; the secret is encrypted, write-only and never shown again.
 */
final class ClientSettings
{
    private const CACHE = 'hq-oauth-clients';

    /** Puts the saved clients into config (services.google, services.microsoft) for this request. */
    public static function apply(): void
    {
        try {
            /** @var array<string, array{client_id: string, client_secret: string}> $rows */
            $rows = Cache::remember(self::CACHE, now()->addMinutes(5), fn (): array => DB::connection(Connections::control())->table('hq_oauth_clients')->get()
                ->mapWithKeys(fn (object $r): array => [(string) $r->provider => ['client_id' => (string) $r->client_id, 'client_secret' => (string) $r->client_secret]])->all());
        } catch (Throwable) {
            return; // before migrations, or the control database is unreachable: the environment's settings stand
        }
        foreach ($rows as $provider => $r) {
            try {
                config(["services.{$provider}.client_id" => $r['client_id'], "services.{$provider}.client_secret" => Crypt::decryptString($r['client_secret'])]);
            } catch (Throwable) {
                // a secret that can't be read is ignored (the screen shows it as not set up)
            }
        }
    }

    public static function save(string $provider, string $clientId, string $secret, ?string $expiresOn, User $by): void
    {
        $clientId = trim($clientId);
        $secret = trim($secret);
        $errors = [];
        if (! isset(Providers::ALL[$provider])) {
            $errors['provider'] = 'Choose Google or Microsoft.';
        }
        if ($provider === 'google' && preg_match('/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/', $clientId) !== 1) {
            $errors['client_id'] = 'A Google client ID ends in .apps.googleusercontent.com.';
        }
        if ($provider === 'microsoft' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $clientId) !== 1) {
            $errors['client_id'] = 'Use the Application (client) ID from the app\'s Overview page (it looks like 1a2b3c4d-…).';
        }
        $existing = DB::connection(Connections::control())->table('hq_oauth_clients')->where('provider', $provider)->first();
        if ($secret === '' && $existing === null) {
            $errors['client_secret'] = 'Paste the client secret.';
        }
        if ($secret !== '' && mb_strlen($secret) < 16) {
            $errors['client_secret'] = 'That secret looks too short. For Microsoft, copy the secret\'s Value, not its ID.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        DB::connection(Connections::control())->table('hq_oauth_clients')->updateOrInsert(['provider' => $provider], [
            'client_id' => $clientId, 'client_secret' => $secret !== '' ? Crypt::encryptString($secret) : (string) ($existing->client_secret ?? ''),
            'secret_expires_on' => $expiresOn !== null && $expiresOn !== '' ? CarbonImmutable::parse($expiresOn)->toDateString() : null,
            'updated_by' => $by->id, 'updated_at' => now(), 'created_at' => $existing->created_at ?? now()]);
        Cache::forget(self::CACHE);
        self::apply();
        app(AuditLogger::class)->record('connect.hq_oauth_client_saved', metadata: ['provider' => $provider, 'secret_changed' => $secret !== ''], severity: 'warning');
    }

    /** @return array{set: bool, from: string|null, client_id: string|null, secret_expires_on: string|null, updated_at: string|null} */
    public static function status(string $provider): array
    {
        $row = DB::connection(Connections::control())->table('hq_oauth_clients')->where('provider', $provider)->first();
        $env = (string) config("services.{$provider}.client_id") !== '';

        return ['set' => $row !== null || $env, 'from' => $row !== null ? 'hq' : ($env ? 'server' : null),
            'client_id' => $row !== null ? (string) $row->client_id : null, 'secret_expires_on' => $row->secret_expires_on ?? null, 'updated_at' => $row->updated_at ?? null];
    }
}
