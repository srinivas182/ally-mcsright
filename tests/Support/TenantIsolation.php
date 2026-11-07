<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use PHPUnit\Framework\Assert;

/**
 * Cross-tenant test harness (specs/core/01). Every tenant-owned model shipped must
 * pass assertIsolated(): tenant B cannot find, list, count, update or delete tenant A's records.
 */
final class TenantIsolation
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  Closure(): Model  $makeRecord  creates one record in the current tenant
     */
    public static function assertIsolated(string $modelClass, Closure $makeRecord): void
    {
        $context = app(TenantContext::class);
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $record = $context->runAs($tenantA, $makeRecord);
        $key = $record->getKey();
        $keyName = $record->getKeyName();

        $context->runAs($tenantB, function () use ($modelClass, $key, $keyName): void {
            Assert::assertNull($modelClass::query()->find($key), "{$modelClass}: tenant B found tenant A's record by key.");
            Assert::assertSame(0, $modelClass::query()->count(), "{$modelClass}: tenant B can list tenant A's records.");
            // A no-op write (key set to itself) works for every table, including append-only logs.
            Assert::assertSame(0, $modelClass::query()->whereKey($key)->toBase()->update([$keyName => $key]), "{$modelClass}: tenant B updated tenant A's record.");
            Assert::assertSame(0, $modelClass::query()->whereKey($key)->delete(), "{$modelClass}: tenant B deleted tenant A's record.");
        });

        $context->runAs($tenantA, function () use ($modelClass, $key): void {
            Assert::assertNotNull($modelClass::query()->find($key), "{$modelClass}: tenant A lost its own record.");
        });
    }
}
