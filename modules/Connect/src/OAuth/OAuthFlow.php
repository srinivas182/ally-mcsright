<?php

declare(strict_types=1);

namespace Modules\Connect\OAuth;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Connect\Connections;
use Modules\Connect\Health;
use Modules\Connect\Models\Connection;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\App;
use Modules\Core\Connect\NeedsReconnect;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/**
 * One-click connect (specs/connect/02): the person presses Connect, signs in to the other app, approves, and comes
 * back connected. Works for every brokerage domain through one central address, like "Sign in with Google":
 *
 * 1. Brokerage host: a sealed 10-minute state (brokerage, host, app, person, a nonce bound to this browser, the PKCE
 *    verifier) and a redirect to the provider.
 * 2. Central host: the provider sends the code back; it is exchanged for tokens there (the client secret never
 *    leaves the server), and a single-use 2-minute ticket holding the encrypted tokens goes back to the brokerage.
 * 3. Brokerage host: the same signed-in person, in the same browser, redeems the ticket; the connection is saved.
 *
 * Access tokens are refreshed when they are about to expire; a refresh the provider refuses means "connect again".
 */
final class OAuthFlow
{
    public function __construct(private readonly TenantContext $tenants, private readonly Connections $connections) {}

    /** Step 1: where to send the browser. */
    public function start(App $app, User $user, Request $request): string
    {
        if ($app->oauth === null || $app->status !== 'available') {
            throw ValidationException::withMessages(['app' => $app->name.' can\'t be connected here yet.']);
        }
        if ($this->connections->ownerFor($app, $user) === false) {
            throw ValidationException::withMessages(['app' => 'Your brokerage connects '.$app->name.' for everyone.']);
        }
        $p = Providers::get($app->oauth['provider']);
        $nonce = Str::random(32);
        $verifier = Str::random(64);
        $request->session()->put('connect.oauth.nonce', $nonce);
        $state = Crypt::encryptString((string) json_encode(['t' => $this->tenants->id(), 'h' => $request->getHost(), 'a' => $app->key, 'u' => $user->id,
            'n' => $nonce, 'v' => $verifier, 'e' => CarbonImmutable::now()->addMinutes(10)->getTimestamp()]));

        return $p['authorize'].'?'.http_build_query([...$p['params'], 'client_id' => $p['client_id'], 'redirect_uri' => Providers::redirectUri(), 'response_type' => 'code',
            'scope' => implode(' ', array_values(array_unique([...$p['base_scopes'], ...$app->oauth['scopes']]))), 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256']);
    }

    /**
     * Step 2 (central host): exchange the code; returns where to send the browser back to.
     *
     * @param  array<string, mixed>  $query
     * @param  array{t: int, h: string, a: string, u: int, n: string, v: string, e: int}  $state
     */
    public function callback(App $app, array $query, array $state): string
    {
        $back = 'https://'.$state['h'].'/apps/oauth/complete';
        if (isset($query['error'])) {
            return $back.'?'.http_build_query(['app' => $app->key, 'error' => $query['error'] === 'access_denied' ? 'cancelled' : 'failed']);
        }
        if ($app->oauth === null || ! is_string($query['code'] ?? null)) {
            return $back.'?'.http_build_query(['app' => $app->key, 'error' => 'failed']);
        }
        $p = Providers::get($app->oauth['provider']);
        $r = Http::asForm()->acceptJson()->timeout(15)->post($p['token'], ['grant_type' => 'authorization_code', 'code' => $query['code'], 'redirect_uri' => Providers::redirectUri(),
            'client_id' => $p['client_id'], 'client_secret' => $p['client_secret'], 'code_verifier' => (string) $state['v']]);
        if (! $r->successful() || ! is_string($r->json('access_token'))) {
            return $back.'?'.http_build_query(['app' => $app->key, 'error' => 'failed']);
        }
        $access = (string) $r->json('access_token');
        $me = Http::withToken($access)->acceptJson()->timeout(10)->get($p['userinfo']);
        $account = (string) ($me->json('email') ?? $me->json('mail') ?? $me->json('userPrincipalName') ?? '');
        $ticket = Str::random(48);
        Cache::put('connect-oauth:'.hash('sha256', $ticket), Crypt::encryptString((string) json_encode(['t' => $state['t'], 'a' => $app->key, 'u' => $state['u'], 'n' => $state['n'],
            'account' => mb_substr($account, 0, 120), 'tokens' => self::tokens($r->json())])), now()->addMinutes(2));

        return $back.'?'.http_build_query(['app' => $app->key, 'ticket' => $ticket]);
    }

    /**
     * Opens and checks the sealed state on the central host.
     *
     * @return array{t: int, h: string, a: string, u: int, n: string, v: string, e: int}
     */
    public static function state(string $sealed): array
    {
        try {
            $s = json_decode(Crypt::decryptString($sealed), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['app' => 'That link is not valid. Please start again from the Apps page.']);
        }
        if (! is_array($s) || (int) ($s['e'] ?? 0) < CarbonImmutable::now()->getTimestamp() || ! is_string($s['h'] ?? null) || ! is_string($s['a'] ?? null)) {
            throw ValidationException::withMessages(['app' => 'That took too long. Please press Connect again.']);
        }

        /** @var array{t: int, h: string, a: string, u: int, n: string, v: string, e: int} $s */
        return $s;
    }

    /** Step 3 (brokerage host): redeem the ticket and save the connection. */
    public function complete(App $app, string $ticket, User $user, Request $request): Connection
    {
        $sealed = Cache::pull('connect-oauth:'.hash('sha256', $ticket));
        $data = is_string($sealed) ? json_decode(Crypt::decryptString($sealed), true) : null;
        $nonce = (string) $request->session()->pull('connect.oauth.nonce', '');
        if (! is_array($data) || (int) $data['t'] !== $this->tenants->id() || (int) $data['u'] !== $user->id || $data['a'] !== $app->key
            || $nonce === '' || ! hash_equals($nonce, (string) $data['n'])) {
            throw ValidationException::withMessages(['app' => 'That didn\'t finish. Please press Connect again.']);
        }
        $owner = $this->connections->ownerFor($app, $user);
        if ($owner === false) {
            throw ValidationException::withMessages(['app' => 'Your brokerage connects '.$app->name.' for everyone.']);
        }
        $c = Connection::query()->where('app_key', $app->key)->where('owner_user_id', $owner)->first() ?? new Connection(['app_key' => $app->key, 'owner_user_id' => $owner]);
        $c->fill(['label' => (string) ($data['account'] ?: $app->name), 'status' => 'connected', 'credentials' => (array) $data['tokens'], 'settings' => $c->settings ?? [],
            'failures' => 0, 'last_error' => null, 'connected_by' => $user->id])->save();
        app(AuditLogger::class)->record('connect.connected', metadata: ['app' => $app->key, 'connection' => $c->ulid, 'for' => $owner === null ? 'brokerage' : 'self', 'one_click' => true],
            severity: 'notice');

        return $c;
    }

    /**
     * A working access token for a connection (refreshed when it is about to expire).
     *
     * @throws NeedsReconnect
     */
    public function accessToken(Connection $c, App $app): string
    {
        $t = $c->credentials ?? [];
        if (isset($t['access_token']) && (int) ($t['expires_at'] ?? 0) > CarbonImmutable::now()->addMinute()->getTimestamp()) {
            return (string) $t['access_token'];
        }
        if (($t['refresh_token'] ?? '') === '' || $app->oauth === null) {
            throw new NeedsReconnect('The sign-in for '.$app->name.' expired.');
        }
        $p = Providers::get($app->oauth['provider']);
        $r = Http::asForm()->acceptJson()->timeout(15)->post($p['token'], ['grant_type' => 'refresh_token', 'refresh_token' => (string) $t['refresh_token'],
            'client_id' => $p['client_id'], 'client_secret' => $p['client_secret']]);
        if (in_array($r->status(), [400, 401], true)) {
            app(Health::class)->failed($c, 'The sign-in for '.$app->name.' was removed or expired.', true);
            throw new NeedsReconnect('The sign-in for '.$app->name.' was removed or expired.');
        }
        if (! $r->successful() || ! is_string($r->json('access_token'))) {
            throw new \RuntimeException($app->name.' did not answer (status '.$r->status().').');
        }
        $c->forceFill(['credentials' => self::tokens($r->json()) + ['refresh_token' => (string) $t['refresh_token']]])->save();

        return (string) $r->json('access_token');
    }

    /** Best effort: tells the provider to forget the sign-in when someone disconnects. */
    public function revoke(Connection $c, App $app): void
    {
        $p = $app->oauth !== null && Providers::configured($app->oauth['provider']) ? Providers::get($app->oauth['provider']) : null;
        $token = (string) (($c->credentials ?? [])['refresh_token'] ?? ($c->credentials ?? [])['access_token'] ?? '');
        if ($p === null || $p['revoke'] === null || $token === '') {
            return;
        }
        try {
            Http::asForm()->timeout(5)->post($p['revoke'], ['token' => $token]);
        } catch (Throwable) {
            // the connection is deleted either way
        }
    }

    /**
     * @param  mixed  $json  the provider's token answer
     * @return array<string, string>
     */
    private static function tokens(mixed $json): array
    {
        $j = is_array($json) ? $json : [];

        return array_filter(['access_token' => (string) ($j['access_token'] ?? ''), 'refresh_token' => (string) ($j['refresh_token'] ?? ''),
            'expires_at' => (string) (CarbonImmutable::now()->getTimestamp() + max(60, (int) ($j['expires_in'] ?? 3600))), 'scope' => (string) ($j['scope'] ?? '')],
            fn (string $v): bool => $v !== '');
    }
}
