<?php

declare(strict_types=1);

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Brokerage and solo back-office routes: require a tenant that is not RightAlly HQ.
 * Elsewhere (central hosts, HQ host) these routes return a neutral 404.
 */
final class EnsureTenantWorkspace
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->check() || $this->context->current()->isHq()) {
            abort(404);
        }

        return $next($request);
    }
}
