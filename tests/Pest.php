<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Roster\Brokerage\BrokerageLicences;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case binding
|--------------------------------------------------------------------------
|
| Feature tests run against the full Laravel application with a fresh
| database for every test. Unit and architecture tests stay framework-free.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/** LV2: gives a brokerage an active licence in a state, so it may add MLS boards there. */
function giveLicence(Tenant $tenant, string $state = 'TX'): void
{
    app(TenantContext::class)->runAs($tenant, fn () => app(BrokerageLicences::class)->save($state, '0654321', 'Dana Brooks', 'active', null));
}
