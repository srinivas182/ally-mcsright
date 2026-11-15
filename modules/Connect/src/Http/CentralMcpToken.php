<?php

declare(strict_types=1);

namespace Modules\Connect\Http;

use Closure;
use Illuminate\Http\Request;
use Modules\Connect\Mcp\Central;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP calls to the shared address (specs/connect/03, M2b): the token names its brokerage ("{ulid}~token"); the call
 * then runs as that brokerage, and its own OAuth server checks the token. Anything else gets no brokerage, so it is
 * refused (401) by the normal sign-in check.
 */
final class CentralMcpToken
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tenants->forget();
        $bearer = (string) $request->bearerToken();
        [$tenant, $token] = $bearer !== '' ? Central::split($bearer) : [null, ''];
        if ($tenant === null || $token === '') {
            $request->headers->remove('Authorization'); // a brokerage's own token is never accepted here without its brokerage

            return $next($request);
        }
        $this->tenants->set($tenant);
        $request->headers->set('Authorization', 'Bearer '.$token);

        return $next($request);
    }
}
