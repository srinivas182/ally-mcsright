<?php

declare(strict_types=1);

namespace Modules\Connect\Mcp;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shared AI assistant address, https://{core.mcp_host} (specs/connect/03, M2b). One address for every brokerage,
 * so RightAlly can be listed in the Claude and ChatGPT directories. It never holds anyone's data or issues tokens of
 * its own; it is a router:
 *
 * 1. An AI app registers here once (same allow-list as each brokerage).
 * 2. Sign-in: the person types their email; the hashed directory finds their brokerage, and the browser goes to that
 *    brokerage's own sign-in and "Allow" screen. The brokerage issues the code, for its own copy of the AI app.
 * 3. The code, and later the tokens, are handed to the AI app with the brokerage's id in front ("{ulid}~…"). Every
 *    token request and every MCP call is routed to that brokerage, whose own OAuth server checks everything (PKCE,
 *    client, expiry, revocation) and whose own rules apply to every tool.
 */
final class Central
{
    public const SEP = '~';

    private const PENDING = 'mcp-central:';

    public function __construct(private readonly TenantContext $tenants) {}

    public static function host(): string
    {
        return (string) config('core.mcp_host');
    }

    public static function returnUri(): string
    {
        return 'https://'.self::host().'/oauth/return';
    }

    /** @return array<string, mixed> */
    public static function authorizationServer(): array
    {
        $base = 'https://'.self::host();

        return ['issuer' => $base, 'authorization_endpoint' => $base.'/authorize', 'token_endpoint' => $base.'/token', 'registration_endpoint' => $base.'/register',
            'response_types_supported' => ['code'], 'code_challenge_methods_supported' => ['S256'], 'scopes_supported' => ['mcp:use', 'rightally:write'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'], 'token_endpoint_auth_methods_supported' => ['none']];
    }

    /** @return array<string, mixed> */
    public static function protectedResource(): array
    {
        $base = 'https://'.self::host();

        return ['resource' => $base.'/mcp', 'authorization_servers' => [$base], 'scopes_supported' => ['mcp:use', 'rightally:write'], 'bearer_methods_supported' => ['header']];
    }

    /** Registers an AI app (dynamic client registration), with the same allow-list as every brokerage. */
    public function register(Request $request): JsonResponse
    {
        $name = trim((string) ($request->input('client_name') ?? $request->input('name') ?? 'AI assistant'));
        $uris = array_values(array_filter((array) $request->input('redirect_uris', []), 'is_string'));
        if ($uris === [] || count($uris) > 10) {
            return response()->json(['error' => 'invalid_redirect_uri', 'error_description' => 'redirect_uris is required.'], 400);
        }
        foreach ($uris as $uri) {
            if (! self::allowedRedirect($uri)) {
                return response()->json(['error' => 'invalid_redirect_uri', 'error_description' => 'redirect_uris is not a permitted redirect domain.'], 400);
            }
        }
        $id = (string) Str::uuid();
        DB::connection(Connections::control())->table('mcp_central_clients')->insert(['id' => $id, 'name' => mb_substr($name !== '' ? $name : 'AI assistant', 0, 255),
            'redirect_uris' => json_encode($uris), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['client_id' => $id, 'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'], 'redirect_uris' => $uris,
            'scope' => 'mcp:use', 'token_endpoint_auth_method' => 'none'], 201);
    }

    public static function allowedRedirect(string $uri): bool
    {
        $scheme = (string) parse_url($uri, PHP_URL_SCHEME);
        if (in_array($scheme, (array) config('mcp.custom_schemes', []), true)) {
            return true;
        }
        if (! in_array($scheme, ['http', 'https'], true) || filter_var($uri, FILTER_VALIDATE_URL) === false || parse_url($uri, PHP_URL_HOST) === self::host()) {
            return false;
        }
        foreach ((array) config('mcp.redirect_domains', []) as $allowed) {
            $allowed = rtrim((string) $allowed, '/');
            if ($uri === $allowed || str_starts_with($uri, $allowed.'/') || str_starts_with($uri, $allowed.':')) {
                return true;
            }
        }

        return false;
    }

    /** @return array{id: string, name: string, redirect_uris: list<string>}|null */
    public static function client(string $id): ?array
    {
        $row = Str::isUuid($id) ? DB::connection(Connections::control())->table('mcp_central_clients')->where('id', $id)->first() : null;
        if ($row === null) {
            return null;
        }
        $uris = json_decode((string) $row->redirect_uris, true);

        return ['id' => (string) $row->id, 'name' => (string) $row->name, 'redirect_uris' => is_array($uris) ? array_values(array_map('strval', $uris)) : []];
    }

    /**
     * Step 1 of sign-in: checks the AI app's request and keeps it for 15 minutes while the person finds their brokerage.
     *
     * @param  array<string, mixed>  $q
     * @return array{id: string, client: string}
     */
    public function begin(array $q): array
    {
        $client = self::client((string) ($q['client_id'] ?? ''));
        $redirect = (string) ($q['redirect_uri'] ?? '');
        if ($client === null || ! in_array($redirect, $client['redirect_uris'], true)) {
            throw ValidationException::withMessages(['client_id' => 'This AI app is not registered with RightAlly. Please start again from the AI app.']);
        }
        if (($q['response_type'] ?? '') !== 'code' || ($q['code_challenge_method'] ?? '') !== 'S256' || ! is_string($q['code_challenge'] ?? null)
            || ! preg_match('/^[A-Za-z0-9_-]{43,128}$/', $q['code_challenge'])) {
            throw ValidationException::withMessages(['client_id' => 'The AI app sent an incomplete sign-in request. Please start again from the AI app.']);
        }
        $scopes = array_values(array_intersect(explode(' ', (string) ($q['scope'] ?? 'mcp:use')), ['mcp:use', 'rightally:write']));
        $id = Str::random(40);
        Cache::put(self::PENDING.$id, ['client' => $client['id'], 'name' => $client['name'], 'redirect_uri' => $redirect, 'state' => is_string($q['state'] ?? null) ? $q['state'] : null,
            'challenge' => $q['code_challenge'], 'scope' => implode(' ', $scopes !== [] ? $scopes : ['mcp:use'])], now()->addMinutes(15));

        return ['id' => $id, 'client' => $client['name']];
    }

    /** @return array{client: string, name: string, redirect_uri: string, state: string|null, challenge: string, scope: string, tenant?: string}|null */
    public static function pending(string $id): ?array
    {
        $p = Cache::get(self::PENDING.$id);
        if (! is_array($p) || ! is_string($p['client'] ?? null) || ! is_string($p['redirect_uri'] ?? null) || ! is_string($p['challenge'] ?? null)) {
            return null;
        }

        return ['client' => $p['client'], 'name' => (string) ($p['name'] ?? ''), 'redirect_uri' => $p['redirect_uri'], 'state' => is_string($p['state'] ?? null) ? $p['state'] : null,
            'challenge' => $p['challenge'], 'scope' => (string) ($p['scope'] ?? 'mcp:use')] + (is_string($p['tenant'] ?? null) ? ['tenant' => $p['tenant']] : []);
    }

    /** Step 2: the brokerage's own sign-in and "Allow" screen, for its own copy of the AI app. */
    public function toBrokerage(string $pendingId, Tenant $tenant): string
    {
        $p = self::pending($pendingId) ?? throw ValidationException::withMessages(['email' => 'That took too long. Please start again from the AI app.']);
        $domain = $tenant->primaryDomain() ?? throw ValidationException::withMessages(['email' => 'This brokerage is not ready for AI assistants yet.']);
        $host = $domain->host;
        $local = $this->localClient($p['client'], $tenant);
        Cache::put(self::PENDING.$pendingId, ['tenant' => $tenant->ulid] + $p, now()->addMinutes(15)); // the code will come from this brokerage

        return 'https://'.$host.'/oauth/authorize?'.http_build_query(['client_id' => $local, 'redirect_uri' => self::returnUri(), 'response_type' => 'code',
            'scope' => $p['scope'], 'state' => $pendingId, 'code_challenge' => $p['challenge'], 'code_challenge_method' => 'S256']);
    }

    /** Step 3: the brokerage sent the code back here; hand it to the AI app with the brokerage's id in front. */
    public function back(Request $request): string
    {
        $p = self::pending((string) $request->query('state', ''));
        if ($p === null) {
            throw ValidationException::withMessages(['email' => 'That took too long. Please start again from the AI app.']);
        }
        Cache::forget(self::PENDING.(string) $request->query('state'));
        $code = (string) $request->query('code', '');
        $from = (string) ($p['tenant'] ?? '');
        $params = $code !== '' && $from !== '' ? ['code' => $from.self::SEP.$code] : ['error' => (string) ($request->query('error') ?: 'access_denied')];

        return $p['redirect_uri'].(str_contains($p['redirect_uri'], '?') ? '&' : '?').http_build_query($params + array_filter(['state' => $p['state']]));
    }

    /** Token requests: routed to the brokerage named in the code or refresh token, whose own OAuth server answers. */
    public function token(Request $request): Response
    {
        $grant = (string) $request->input('grant_type', '');
        $value = (string) $request->input($grant === 'refresh_token' ? 'refresh_token' : 'code', '');
        $client = self::client((string) $request->input('client_id', ''));
        [$tenant, $inner] = self::split($value);
        if (! in_array($grant, ['authorization_code', 'refresh_token'], true) || $client === null || $tenant === null) {
            return response()->json(['error' => 'invalid_grant', 'error_description' => 'The code or token is not valid.'], 400);
        }
        if ($grant === 'authorization_code' && ! in_array((string) $request->input('redirect_uri'), $client['redirect_uris'], true)) {
            return response()->json(['error' => 'invalid_grant', 'error_description' => 'redirect_uri does not match.'], 400);
        }
        $local = DB::connection(Connections::control())->table('mcp_central_links')->where('central_client_id', $client['id'])->where('tenant_id', $tenant->id)->value('local_client_id');
        if ($local === null) {
            return response()->json(['error' => 'invalid_grant', 'error_description' => 'The code or token is not valid.'], 400);
        }
        $body = array_filter(['grant_type' => $grant, 'client_id' => (string) $local, 'code' => $grant === 'authorization_code' ? $inner : null,
            'refresh_token' => $grant === 'refresh_token' ? $inner : null, 'redirect_uri' => $grant === 'authorization_code' ? self::returnUri() : null,
            'code_verifier' => $grant === 'authorization_code' ? (string) $request->input('code_verifier', '') : null, 'scope' => $request->input('scope')], fn ($v) => $v !== null);

        $this->tenants->set($tenant);
        try {
            $inner = Request::create('https://'.self::host().'/token', 'POST', $body);
            $psr = (new PsrHttpFactory)->createRequest($inner)->withParsedBody($body);
            $response = app(AccessTokenController::class)->issueToken($psr, (new PsrHttpFactory)->createResponse(new \Illuminate\Http\Response));
        } finally {
            $this->tenants->forget();
        }
        $json = json_decode((string) $response->getContent(), true);
        if ($response->getStatusCode() === 200 && is_array($json)) {
            foreach (['access_token', 'refresh_token'] as $k) {
                if (is_string($json[$k] ?? null)) {
                    $json[$k] = $tenant->ulid.self::SEP.$json[$k];
                }
            }

            return response()->json($json)->header('Cache-Control', 'no-store');
        }

        return response((string) $response->getContent(), $response->getStatusCode(), ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }

    /** @return array{0: Tenant|null, 1: string} the brokerage named in front of a code or token, and the rest */
    public static function split(string $value): array
    {
        $at = strpos($value, self::SEP);
        if ($at !== 26) {
            return [null, ''];
        }
        $tenant = Tenant::query()->where('ulid', substr($value, 0, 26))->where('status', 'active')->where('type', '!=', 'hq')->first();

        return [$tenant, substr($value, 27)];
    }

    /** The brokerage's own registration of this AI app (created the first time someone there uses it). */
    private function localClient(string $centralId, Tenant $tenant): string
    {
        $links = DB::connection(Connections::control())->table('mcp_central_links');
        $known = (clone $links)->where('central_client_id', $centralId)->where('tenant_id', $tenant->id)->value('local_client_id');
        if ($known !== null) {
            return (string) $known;
        }
        $client = self::client($centralId) ?? throw ValidationException::withMessages(['email' => 'Please start again from the AI app.']);
        $response = $this->tenants->runAs($tenant, fn () => app(OAuthRegisterController::class)(
            Request::create('/oauth/register', 'POST', ['client_name' => $client['name'], 'redirect_uris' => [self::returnUri(), ...$client['redirect_uris']]])));
        $local = (string) ($response->getData(true)['client_id'] ?? '');
        if ($local === '') {
            throw ValidationException::withMessages(['email' => 'This brokerage could not add the AI app. Please ask RightAlly support.']);
        }
        $links->insert(['central_client_id' => $centralId, 'tenant_id' => $tenant->id, 'local_client_id' => $local, 'created_at' => now(), 'updated_at' => now()]);

        return $local;
    }
}
