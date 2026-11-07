<?php

declare(strict_types=1);

namespace Modules\Core;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Tenancy\DomainResolver;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantCache;
use Modules\Core\Tenancy\TenantContext;

/**
 * Platform Core module.
 *
 * Every RightAlly module is a self-contained package under modules/<Name>/
 * with a module.json manifest and a service provider registered in
 * bootstrap/providers.php. Modules talk to each other only through public
 * interfaces and domain events, never through each other's tables.
 */
final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/core.php', 'core');

        // Scoped: reset between requests (Octane) and between queued jobs.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(TenantCache::class);
        $this->app->singleton(DomainResolver::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'core');

        $this->registerTenantAwareQueues();
        $this->registerMigrationMacros();
    }

    /**
     * $table->tenant() adds the indexed tenant_id column every tenant-owned table needs.
     * No foreign key: tenants live on the control-plane connection, which can be a
     * different database from the cell (architecture v2 section 3).
     */
    private function registerMigrationMacros(): void
    {
        Blueprint::macro('tenant', function (): void {
            /** @var Blueprint $this */
            $this->unsignedBigInteger('tenant_id');
            $this->index('tenant_id');
        });
    }

    /**
     * Jobs carry the tenant they were dispatched under and run as that tenant.
     */
    private function registerTenantAwareQueues(): void
    {
        Queue::createPayloadUsing(function (): array {
            $context = $this->app->make(TenantContext::class);

            return $context->check() ? ['tenant_id' => $context->id()] : [];
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $tenantId = $this->tenantIdFrom($event->job);

            $this->app->make(TenantContext::class)->push(
                $tenantId !== null ? Tenant::query()->findOrFail($tenantId) : null,
            );
        });

        $restore = fn () => $this->app->make(TenantContext::class)->pop();
        Event::listen(JobProcessed::class, $restore);
        Event::listen(JobFailed::class, $restore);
    }

    private function tenantIdFrom(Job $job): ?int
    {
        $payload = $job->payload();
        $id = $payload['tenant_id'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }
}
