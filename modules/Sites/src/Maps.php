<?php

declare(strict_types=1);

namespace Modules\Sites;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;

/**
 * The Mapbox token website maps use (specs/websites/06). HQ sets it once for the platform; it is stored encrypted
 * and never shown again. Only public tokens (pk.) are accepted, because the browser sees them: a secret token (sk.)
 * would be exposed on every website. Restrict the token to *.rightally.site and custom domains in Mapbox.
 */
final class Maps
{
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
