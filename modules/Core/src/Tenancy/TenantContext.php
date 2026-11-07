<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy;

use Closure;
use Modules\Core\Tenancy\Exceptions\MissingTenantContext;
use Modules\Core\Tenancy\Models\Tenant;

/**
 * Holds the tenant for the current request, job or command.
 *
 * Registered as a scoped singleton so it is reset between requests (including
 * under Octane) and between queued jobs.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    /** @var list<Tenant|null> */
    private array $stack = [];

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function forget(): void
    {
        $this->tenant = null;
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    public function current(): Tenant
    {
        return $this->tenant ?? throw MissingTenantContext::none();
    }

    public function id(): int
    {
        return $this->current()->id;
    }

    public function idOrNull(): ?int
    {
        return $this->tenant?->id;
    }

    /**
     * Switch to a tenant (or none) for a unit of work, remembering the current one.
     * Used around queued jobs, which may run inside a request (sync driver).
     */
    public function push(?Tenant $tenant): void
    {
        $this->stack[] = $this->tenant;
        $this->tenant = $tenant;
    }

    /**
     * Restore the tenant that was current before the matching push().
     */
    public function pop(): void
    {
        $this->tenant = $this->stack === [] ? null : array_pop($this->stack);
    }

    /**
     * Run a callback as the given tenant, restoring the previous context afterwards.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runAs(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }
}
