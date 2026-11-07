<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Tenancy\Exceptions\MissingTenantContext;
use Modules\Core\Tenancy\Exceptions\TenantMismatch;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Tests\Fixtures\TenantNote;
use Tests\Support\TenantIsolation;

beforeEach(function (): void {
    Schema::create('test_tenant_notes', function (Blueprint $table): void {
        $table->id();
        $table->tenant();
        $table->string('body');
        $table->timestamps();
    });
});

it('fills tenant_id from the current tenant', function (): void {
    $tenant = Tenant::factory()->create();

    $note = app(TenantContext::class)->runAs($tenant, fn () => TenantNote::create(['body' => 'Hello']));

    expect($note->tenant_id)->toBe($tenant->id);
});

it('refuses to create tenant-owned records without a tenant', function (): void {
    TenantNote::create(['body' => 'Orphan']);
})->throws(MissingTenantContext::class);

it('refuses to query tenant-owned records without a tenant', function (): void {
    TenantNote::query()->count();
})->throws(MissingTenantContext::class);

it('refuses to create a record for another tenant', function (): void {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    app(TenantContext::class)->runAs($a, fn () => TenantNote::create(['body' => 'Sneaky', 'tenant_id' => $b->id]));
})->throws(TenantMismatch::class);

it('refuses to move a record to another tenant', function (): void {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    app(TenantContext::class)->runAs($a, function () use ($b): void {
        $note = TenantNote::create(['body' => 'Mine']);
        $note->tenant_id = $b->id;
        $note->save();
    });
})->throws(TenantMismatch::class);

it('isolates tenants completely', function (): void {
    TenantIsolation::assertIsolated(TenantNote::class, fn () => TenantNote::create(['body' => 'Private']));
});

it('restores the previous tenant after runAs', function (): void {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $context->runAs($a, function () use ($context, $a, $b): void {
        $context->runAs($b, fn () => expect($context->id())->toBe($b->id));
        expect($context->id())->toBe($a->id);
    });

    expect($context->check())->toBeFalse();
});
