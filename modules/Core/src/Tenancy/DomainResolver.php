<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy;

use Illuminate\Contracts\Cache\Repository as Cache;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Core\Tenancy\Models\TenantHostRedirect;

/**
 * Maps a host name to its tenant. The host-to-tenant-id lookup is cached so the
 * control plane is not on the request path; the cache is cleared whenever a domain changes.
 */
final class DomainResolver
{
    private const MISSING = 0;

    public function __construct(private readonly Cache $cache) {}

    public function resolve(string $host): ?Tenant
    {
        $host = Host::normalise($host);

        /** @var int $tenantId */
        $tenantId = $this->cache->remember(
            $this->key($host),
            (int) config('core.domain_cache_ttl', 300),
            function () use ($host): int {
                $domain = TenantDomain::query()->where('host', $host)->first();

                return $domain !== null && $domain->routes() ? $domain->tenant_id : self::MISSING;
            },
        );

        if ($tenantId === self::MISSING) {
            return null;
        }

        return Tenant::query()->find($tenantId);
    }

    /**
     * For an old platform host after a slug change: the host to redirect to (the tenant's
     * current primary domain), or null when there is no active redirect.
     */
    public function redirectTargetFor(string $host): ?string
    {
        $redirect = TenantHostRedirect::query()
            ->where('host', Host::normalise($host))
            ->where('redirect_until', '>', now())
            ->first();

        return $redirect?->tenant->primaryDomain()?->host;
    }

    public function forget(string $host): void
    {
        $this->cache->forget($this->key(Host::normalise($host)));
    }

    private function key(string $host): string
    {
        return 'core:domain:'.$host;
    }
}
