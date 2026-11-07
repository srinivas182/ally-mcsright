<?php

declare(strict_types=1);

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * HQ console routes exist only on the HQ host and only for permitted HQ staff.
 * Elsewhere they return a neutral 404; on the HQ host without permission, 403.
 */
final class EnsureHqConsole
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->check() || ! $this->context->current()->isHq()) {
            abort(404);
        }

        abort_unless(Gate::allows('hq.tenants.manage'), 403);

        return $next($request);
    }
}
