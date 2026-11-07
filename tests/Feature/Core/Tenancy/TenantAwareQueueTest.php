<?php

declare(strict_types=1);

use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Tests\Fixtures\RecordTenantJob;

beforeEach(fn () => RecordTenantJob::$seen = []);

it('runs a job as the tenant it was dispatched under', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $context->runAs($tenant, function () use ($context, $tenant): void {
        RecordTenantJob::dispatch();
        expect($context->id())->toBe($tenant->id); // request tenant preserved after a sync job
    });

    expect(RecordTenantJob::$seen)->toBe([$tenant->id]);
});

it('runs a job dispatched without a tenant with no tenant', function (): void {
    RecordTenantJob::dispatch();

    expect(RecordTenantJob::$seen)->toBe([null]);
});

it('runs jobs from different tenants as their own tenant', function (): void {
    [$a, $b] = Tenant::factory()->count(2)->create()->all();
    $context = app(TenantContext::class);

    // Use statement closures: a PendingDispatch returned from an arrow function would only
    // dispatch when destroyed, after runAs() has already restored the previous tenant.
    $context->runAs($a, function (): void {
        RecordTenantJob::dispatch();
    });
    $context->runAs($b, function (): void {
        RecordTenantJob::dispatch();
    });

    expect(RecordTenantJob::$seen)->toBe([$a->id, $b->id]);
});
