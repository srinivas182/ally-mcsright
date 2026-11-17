<?php

declare(strict_types=1);

namespace Modules\Api\Http;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Passport\AccessToken;
use Modules\Api\Keys;
use Modules\Api\Scopes;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Who is calling the API (API1): a personal API key ("ra_key_…"), or a partner app's OAuth token for a person who
 * allowed it, in a brokerage that allowed that app. Either way the call acts as that person and sees only what
 * they see. 120 calls a minute per key or token.
 */
final class ApiAuth
{
    public const LIMIT = 120;

    public function __construct(private readonly TenantContext $tenants, private readonly Keys $keys) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = (string) $request->bearerToken();
        $caller = null;
        if (str_starts_with($bearer, 'ra_key_')) {
            $found = $this->keys->find($bearer);
            if ($found !== null) {
                $caller = ['user' => $found['user'], 'scopes' => $found['scopes'], 'id' => $found['id'], 'app' => null];
            }
        } else {
            $caller = $this->oauth($request); // a partner app's token (the OAuth guard reads it from the same header)
        }
        if ($caller === null) {
            return self::error(401, 'unauthenticated', 'Send a valid API key or access token as "Authorization: Bearer …".');
        }
        if ((int) $caller['user']->tenant_id !== $this->tenants->id()) {
            return self::error(401, 'unauthenticated', 'This key or token belongs to another brokerage.');
        }
        $limit = 'api:'.$this->tenants->id().':'.$caller['id'];
        if (RateLimiter::tooManyAttempts($limit, self::LIMIT)) {
            return self::error(429, 'rate_limited', 'Too many calls. Wait a moment and try again.')->header('Retry-After', (string) RateLimiter::availableIn($limit));
        }
        RateLimiter::hit($limit, 60);
        Auth::setUser($caller['user']);
        $request->setUserResolver(fn () => $caller['user']);
        $request->attributes->set('api_scopes', $caller['scopes']);
        $request->attributes->set('api_caller', $caller['id']);
        $request->attributes->set('api_app', $caller['app']);
        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) self::LIMIT);
        $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining($limit, self::LIMIT));
        $response->headers->set('RightAlly-Version', '2026-11-01');

        return $response;
    }

    /** @return array{user: User, scopes: list<string>, id: string, app: string}|null */
    private function oauth(Request $request): ?array
    {
        try {
            $user = Auth::guard('api')->user();
        } catch (Throwable) {
            return null;
        }
        if (! $user instanceof User) {
            return null;
        }
        $token = $user->token();
        $clientId = $token instanceof AccessToken ? (string) $token->oauth_client_id : '';
        $scopes = [];
        foreach (array_keys(Scopes::ALL) as $s) {
            if ($token !== null && $token->can(Scopes::oauth($s))) {
                $scopes[] = $s;
            }
        }
        if ($scopes === [] || ! DB::connection(Connections::cell())->table('api_partner_grants')->where('tenant_id', $this->tenants->id())->where('client_id', $clientId)->exists()) {
            return null; // not an API token, or the brokerage has not allowed this app
        }

        return ['user' => $user, 'scopes' => $scopes, 'id' => 'oauth:'.$clientId.':'.$user->id, 'app' => $clientId];
    }

    /** @param array<string, mixed> $fields */
    public static function error(int $status, string $code, string $message, array $fields = []): JsonResponse
    {
        return response()->json(['error' => array_filter(['code' => $code, 'message' => $message, 'fields' => $fields !== [] ? $fields : null])], $status);
    }
}
