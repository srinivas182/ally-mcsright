<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Tenancy\DomainResolver;
use Modules\Core\Tenancy\Enums\TenantStatus;
use Modules\Core\Tenancy\Host;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant from the request host before anything else runs (specs/core/01).
 *
 * - Central hosts (local development) run without a tenant.
 * - Unknown hosts get a neutral 404 that reveals nothing about tenants.
 * - Tenants that are not active get a notice page instead of the application.
 * - The tenant is cleared at the start and end of every request, so a long-running
 *   process can never carry one tenant into another tenant's request.
 */
final class ResolveTenant
{
    public function __construct(
        private readonly DomainResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Never inherit a tenant from a previous request handled by the same process.
        $this->context->forget();

        $host = Host::normalise($request->getHost());

        /** @var list<string> $central */
        $central = (array) config('core.central_hosts', []);

        if (in_array($host, $central, true)) {
            return $next($request);
        }

        $tenant = $this->resolver->resolve($host);

        if ($tenant === null) {
            $target = $this->resolver->redirectTargetFor($host);

            if ($target !== null) {
                return redirect()->away($request->getScheme().'://'.$target.$request->getRequestUri(), 301);
            }

            abort(404);
        }

        return match ($tenant->status) {
            TenantStatus::Active => $this->continueAs($tenant, $request, $next),
            TenantStatus::Provisioning => $this->notice('provisioning', 503),
            TenantStatus::Suspended => $this->notice('suspended', 403),
            TenantStatus::Closing, TenantStatus::Closed => $this->notice('closed', 410),
        };
    }

    /**
     * Clear the tenant once the response has been sent.
     */
    public function terminate(Request $request, Response $response): void
    {
        $this->context->forget();
    }

    private function continueAs(Tenant $tenant, Request $request, Closure $next): Response
    {
        $this->context->set($tenant);

        return $next($request);
    }

    private function notice(string $state, int $status): Response
    {
        return response()->view('core::tenant-unavailable', ['state' => $state], $status);
    }
}
