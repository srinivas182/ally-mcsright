<?php

declare(strict_types=1);

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * HQ console routes exist only on the HQ host; elsewhere they return a neutral 404. Runs before
 * sign-in; the HQ staff permission is checked after it (can:hq.tenants.manage).
 */
final class EnsureHqConsole
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->check() || ! $this->context->current()->isHq()) {
            abort(404);
        }

        return $next($request);
    }
}
