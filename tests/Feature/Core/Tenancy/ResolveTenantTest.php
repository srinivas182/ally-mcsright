<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Modules\Core\Tenancy\Enums\TenantStatus;
use Modules\Core\Tenancy\Host;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Core\Tenancy\TenantContext;

beforeEach(function (): void {
    Route::middleware('web')->get('/_tenant-probe', function () {
        $context = app(TenantContext::class);

        return $context->check() ? 'tenant:'.$context->current()->slug : 'tenant:none';
    });
});

function probe(string $host): TestResponse
{
    return test()->get('http://'.$host.'/_tenant-probe');
}

it('resolves a tenant from its platform subdomain', function (): void {
    $tenant = Tenant::factory()->withPlatformDomain()->create(['slug' => 'jones-homes']);

    probe(Host::platformHostFor($tenant->slug))->assertOk()->assertSeeText('tenant:jones-homes');
});

it('normalises upper case and ports', function (): void {
    Tenant::factory()->withPlatformDomain()->create(['slug' => 'jones-homes']);

    probe('JONES-HOMES.rightally.app:8080')->assertOk()->assertSeeText('tenant:jones-homes');
});

it('resolves a verified custom domain but not a pending one', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'lake-area']);
    TenantDomain::factory()->verified()->for($tenant)->create(['host' => 'www.lakearea.com']);
    TenantDomain::factory()->for($tenant)->create(['host' => 'www.pending-lakearea.com']);

    probe('www.lakearea.com')->assertOk()->assertSeeText('tenant:lake-area');
    probe('www.pending-lakearea.com')->assertNotFound();
});

it('returns a neutral 404 for unknown hosts', function (): void {
    Tenant::factory()->withPlatformDomain()->create(['name' => 'Secret Realty']);

    probe('nobody.rightally.app')->assertNotFound()->assertDontSeeText('Secret Realty');
});

it('runs central hosts without a tenant', function (): void {
    probe('localhost')->assertOk()->assertSeeText('tenant:none');
});

it('shows notices instead of the app for tenants that are not active', function (TenantStatus $status, int $code): void {
    $tenant = Tenant::factory()->withPlatformDomain()->status($status)->create();

    probe(Host::platformHostFor($tenant->slug))->assertStatus($code)->assertDontSeeText('tenant:');
})->with([
    'provisioning' => [TenantStatus::Provisioning, 503],
    'suspended' => [TenantStatus::Suspended, 403],
    'closing' => [TenantStatus::Closing, 410],
    'closed' => [TenantStatus::Closed, 410],
]);

it('stops routing immediately when a domain is removed', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'moving-on']);
    $domain = TenantDomain::factory()->verified()->for($tenant)->create(['host' => 'www.movingon.com']);

    probe('www.movingon.com')->assertOk();
    $domain->delete();
    probe('www.movingon.com')->assertNotFound();
});

it('does not leak the tenant into the next request', function (): void {
    Tenant::factory()->withPlatformDomain()->create(['slug' => 'first-tenant']);

    probe('first-tenant.rightally.app')->assertSeeText('tenant:first-tenant');
    probe('localhost')->assertSeeText('tenant:none');
});
