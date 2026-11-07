<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Modules\Core\Tenancy\Actions\ProvisionTenant;
use Modules\Core\Tenancy\Data\ProvisionTenantData;
use Modules\Core\Tenancy\Enums\CellStatus;
use Modules\Core\Tenancy\Enums\TenantStatus;
use Modules\Core\Tenancy\Enums\TenantType;
use Modules\Core\Tenancy\Events\TenantProvisioned;
use Modules\Core\Tenancy\Exceptions\NoCellAvailable;
use Modules\Core\Tenancy\Models\Cell;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Ledger\Models\LedgerAccount;

beforeEach(fn () => Route::middleware('web')->get('/_probe', fn () => 'ok'));

function provisionData(array $overrides = []): ProvisionTenantData
{
    return new ProvisionTenantData(...array_merge([
        'type' => TenantType::Brokerage,
        'name' => 'Jones Homes Realty',
        'slug' => 'jones-homes',
        'ownerEmail' => 'owner@joneshomes.com',
        'ownerName' => 'Pat Jones',
        'idempotencyKey' => 'signup-1',
        'stateCode' => 'MS',
    ], $overrides));
}

beforeEach(fn () => Cell::factory()->create(['name' => 'cell-a']));

it('provisions an active tenant with its primary platform domain', function (): void {
    Event::fake([TenantProvisioned::class]);

    $tenant = app(ProvisionTenant::class)->handle(provisionData());

    expect($tenant->status)->toBe(TenantStatus::Active)
        ->and($tenant->primaryDomain()?->host)->toBe('jones-homes.rightally.app');

    $this->get('http://jones-homes.rightally.app/_probe')->assertOk();
    Event::assertDispatched(TenantProvisioned::class, fn ($e) => $e->tenantId === $tenant->id && $e->ownerEmail === 'owner@joneshomes.com');
});

it('is idempotent by provisioning key', function (): void {
    $first = app(ProvisionTenant::class)->handle(provisionData());
    $second = app(ProvisionTenant::class)->handle(provisionData(['name' => 'Different name']));

    expect($second->id)->toBe($first->id)->and(Tenant::query()->count())->toBe(1);
});

it('validates name, address and owner', function (array $overrides, string $field): void {
    try {
        app(ProvisionTenant::class)->handle(provisionData($overrides));
        $this->fail('Expected validation to fail.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }
})->with([
    'blank name' => [['name' => '  '], 'name'],
    'bad slug' => [['slug' => 'Jones Homes'], 'slug'],
    'reserved slug' => [['slug' => 'admin'], 'slug'],
    'bad email' => [['ownerEmail' => 'not-an-email'], 'owner_email'],
    'blank owner' => [['ownerName' => ''], 'owner_name'],
]);

it('refuses an address that is already taken', function (): void {
    app(ProvisionTenant::class)->handle(provisionData());

    app(ProvisionTenant::class)->handle(provisionData(['idempotencyKey' => 'signup-2']));
})->throws(ValidationException::class);

it('places tenants in the active cell with the most room', function (): void {
    Cell::factory()->create(['name' => 'cell-full', 'capacity_tenants' => 1]);
    Cell::factory()->create(['name' => 'cell-off', 'status' => CellStatus::Draining]);
    $roomy = Cell::factory()->create(['name' => 'cell-roomy', 'capacity_tenants' => 50_000]);

    $tenant = app(ProvisionTenant::class)->handle(provisionData());

    expect($tenant->cell_id)->toBe($roomy->id);
});

it('fails clearly when no cell has room', function (): void {
    Cell::query()->update(['status' => CellStatus::Full->value]);

    app(ProvisionTenant::class)->handle(provisionData());
})->throws(NoCellAvailable::class);

it('seeds the ledger chart of accounts for new brokerages', function (): void {
    $tenant = app(ProvisionTenant::class)->handle(provisionData());

    $count = app(TenantContext::class)->runAs($tenant, fn () => LedgerAccount::query()->where('is_system', true)->count());

    expect($count)->toBe(15);
});
