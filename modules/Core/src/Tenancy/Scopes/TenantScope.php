<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Modules\Core\Tenancy\Exceptions\MissingTenantContext;
use Modules\Core\Tenancy\TenantContext;

/**
 * Restricts every query on a tenant-owned model to the current tenant.
 * Querying without a tenant context throws instead of silently returning all tenants.
 *
 * @implements Scope<Model>
 */
final class TenantScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->check()) {
            throw MissingTenantContext::forModel($model::class);
        }

        $builder->where($model->qualifyColumn('tenant_id'), $context->id());
    }
}
