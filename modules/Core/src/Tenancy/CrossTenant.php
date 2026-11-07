<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\Events\CrossTenantAccessed;
use Modules\Core\Tenancy\Scopes\TenantScope;
use ReflectionClass;

/**
 * The only sanctioned way to query tenant-owned data across tenants. The calling class must
 * carry #[CrossTenantAccess('why')]; each use is announced for the audit log.
 */
final class CrossTenant
{
    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return Builder<TModel>
     */
    public static function query(string $model, object $caller): Builder
    {
        $attributes = (new ReflectionClass($caller))->getAttributes(CrossTenantAccess::class);

        if ($attributes === []) {
            throw new LogicException($caller::class.' is not allowed cross-tenant access; add #[CrossTenantAccess("reason")].');
        }

        CrossTenantAccessed::dispatch($caller::class, $model, $attributes[0]->newInstance()->reason);

        return $model::query()->withoutGlobalScope(TenantScope::class);
    }
}
