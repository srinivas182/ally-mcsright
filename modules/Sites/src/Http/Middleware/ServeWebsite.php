<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Tenancy\DomainResolver;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Http\SiteRouter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Website hosts never reach the back-office routes (specs/websites/05): a request for a host registered as a
 * website is resolved to its brokerage and handled by the site's own small router, with no session or cookies,
 * so pages are cacheable at the edge. Every other host carries on as before.
 */
final class ServeWebsite
{
    public function __construct(private readonly DomainResolver $domains, private readonly TenantContext $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->domains->isWebsite($request->getHost())) {
            return $next($request);
        }
        $tenant = $this->domains->resolve($request->getHost());
        if ($tenant === null || $tenant->status->value !== 'active') {
            return response()->view('sites::unavailable', [], 404)->header('Cache-Control', 'no-store');
        }
        $this->tenants->set($tenant);
        try {
            return app(SiteRouter::class)->dispatch($request);
        } finally {
            $this->tenants->forget();
        }
    }
}
