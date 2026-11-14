<?php

declare(strict_types=1);

namespace Modules\Sites;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;

/**
 * The Mapbox tokens website maps use (specs/websites/06). HQ sets them once for the platform; both are stored
 * encrypted and never shown again.
 *
 * - The public token (pk.) is used for static map images on listing pages, and for the interactive map when no
 *   secret is set.
 * - The secret token (sk., with the tokens:write scope) never leaves the server. It mints a temporary token for
 *   visitors that expires within an hour, so a token copied from a website stops working by itself.
 */
final class Maps
{
    /** Scopes a visitor's map needs (Mapbox's default public scopes). The secret token must include them. */
    public const VISITOR_SCOPES = ['styles:tiles', 'styles:read', 'fonts:read', 'datasets:read', 'vision:read'];

    private const CACHE_KEY = 'mapbox:visitor-token';

    private ?string $token = null;

    private bool $loaded = false;

    public function token(): ?string
    {
        if (! $this->loaded) {
            $this->loaded = true;
            $stored = DB::connection(Connections::control())->table('hq_site_branding')->value('mapbox_token');
            try {
                $this->token = is_string($stored) && $stored !== '' ? Crypt::decryptString($stored) : null;
            } catch (\Throwable) {
                $this->token = null;
            }
            $this->token ??= is_string(config('services.mapbox.public_token')) && config('services.mapbox.public_token') !== '' ? (string) config('services.mapbox.public_token') : null;
        }

        return $this->token;
    }

    /**
     * The token a website visitor's map uses: a one-hour temporary token when HQ has set the secret token
     * (shared by all visitors for up to 50 minutes), otherwise the public token.
     */
    public function visitorToken(): ?string
    {
        $secret = $this->secret();
        if ($secret === null) {
            return $this->token();
        }
        $cached = Cache::get(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $minted = $this->mint($secret, 60);
        if ($minted !== null) {
            Cache::put(self::CACHE_KEY, $minted, now()->addMinutes(50));

            return $minted;
        }

        return $this->token(); // Mapbox unreachable: fall back rather than break the map
    }

    public function hasSecret(): bool
    {
        return $this->secret() !== null;
    }

    /** Saves (or removes, with null) the secret token after checking it can mint a visitor token. */
    public function saveSecret(?string $secret, User $by): void
    {
        $secret = $secret !== null ? trim($secret) : null;
        if ($secret !== null && $secret !== '') {
            if (preg_match('/^sk\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+$/', $secret) !== 1 || self::username($secret) === null) {
                throw ValidationException::withMessages(['mapbox_secret' => 'Paste a Mapbox secret token (starts with sk.) that has the tokens:write scope.']);
            }
            if ($this->mint($secret, 5) === null) {
                throw ValidationException::withMessages(['mapbox_secret' => 'Mapbox did not accept that token. Give it the tokens:write scope plus styles:tiles, styles:read, fonts:read, datasets:read and vision:read.']);
            }
        }
        $value = $secret === null || $secret === '' ? null : Crypt::encryptString($secret);
        $db = DB::connection(Connections::control());
        $db->table('hq_site_branding')->exists()
            ? $db->table('hq_site_branding')->update(['mapbox_secret' => $value, 'updated_by' => $by->id, 'updated_at' => now()])
            : $db->table('hq_site_branding')->insert(['mapbox_secret' => $value, 'powered_enabled' => true, 'powered_text' => 'Powered by RightAlly', 'powered_url' => 'https://rightally.io',
                'updated_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        Cache::forget(self::CACHE_KEY);
        app(AuditLogger::class)->record('hq.sites.mapbox_secret', metadata: ['set' => $value !== null], severity: 'critical');
    }

    private function secret(): ?string
    {
        $stored = DB::connection(Connections::control())->table('hq_site_branding')->value('mapbox_secret');
        try {
            return is_string($stored) && $stored !== '' ? Crypt::decryptString($stored) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Asks Mapbox for a temporary token (at most an hour, Mapbox's limit). */
    private function mint(string $secret, int $minutes): ?string
    {
        $user = self::username($secret);
        if ($user === null) {
            return null;
        }
        try {
            $response = Http::timeout(5)->acceptJson()->post('https://api.mapbox.com/tokens/v2/'.rawurlencode($user).'?access_token='.rawurlencode($secret), [
                'expires' => CarbonImmutable::now('UTC')->addMinutes(min(60, $minutes))->subSeconds(30)->format('Y-m-d\TH:i:s.v\Z'),
                'scopes' => self::VISITOR_SCOPES,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
        $token = $response->successful() ? $response->json('token') : null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /** The Mapbox account name inside a token (its middle part is base64 JSON with "u"). */
    private static function username(string $token): ?string
    {
        $parts = explode('.', $token);
        $json = json_decode((string) base64_decode(strtr($parts[1] ?? '', '-_', '+/'), true), true);

        return is_array($json) && is_string($json['u'] ?? null) && $json['u'] !== '' ? $json['u'] : null;
    }

    /** The last four characters, for HQ to recognise which token is set. */
    public function hint(): ?string
    {
        $t = $this->token();

        return $t !== null ? substr($t, -4) : null;
    }

    public function save(?string $token, User $by): void
    {
        $token = $token !== null ? trim($token) : null;
        if ($token !== null && $token !== '' && preg_match('/^pk\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+$/', $token) !== 1) {
            throw ValidationException::withMessages(['mapbox_token' => str_starts_with($token, 'sk.')
                ? 'That is a secret token. Use a public token (starts with pk.), because visitors\' browsers can see it.'
                : 'Paste a Mapbox public token (starts with pk.).']);
        }
        $db = DB::connection(Connections::control());
        $value = $token === null || $token === '' ? null : Crypt::encryptString($token);
        $db->table('hq_site_branding')->exists()
            ? $db->table('hq_site_branding')->update(['mapbox_token' => $value, 'updated_by' => $by->id, 'updated_at' => now()])
            : $db->table('hq_site_branding')->insert(['mapbox_token' => $value, 'powered_enabled' => true, 'powered_text' => 'Powered by RightAlly', 'powered_url' => 'https://rightally.io',
                'updated_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->loaded = false;
        app(AuditLogger::class)->record('hq.sites.mapbox_token', metadata: ['set' => $value !== null], severity: 'notice');
    }
}
