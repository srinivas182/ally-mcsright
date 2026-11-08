<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign-in pages exist only on a tenant's own address (brokerage, solo or HQ host); central hosts
 * return a neutral 404.
 */
final class EnsureTenantHost
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->context->check(), 404);

        return $next($request);
    }
}
