<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Exceptions\MissingTenantContext;
use Modules\Core\Tenancy\Exceptions\TenantMismatch;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Scopes\TenantScope;
use Modules\Core\Tenancy\TenantContext;

/**
 * Makes a model tenant-owned (specs/core/01):
 * - every query is limited to the current tenant (throws without a context);
 * - tenant_id is filled on create and can never point at another tenant;
 * - tenant_id can never change after creation.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);

            if (! $context->check()) {
                throw MissingTenantContext::forModel($model::class);
            }

            $given = $model->getAttribute('tenant_id');

            if ($given !== null && (int) $given !== $context->id()) {
                throw TenantMismatch::onCreate($model::class);
            }

            $model->setAttribute('tenant_id', $context->id());
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw TenantMismatch::onUpdate($model::class);
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
