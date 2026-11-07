<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Tenancy\Enums\TenantStatus;
use Modules\Core\Tenancy\Models\Cell;
use Modules\Core\Tenancy\Models\Tenant;

beforeEach(function (): void {
    config(['core.hq_host' => 'app.rightally.io', 'core.hq_bootstrap_admins' => ['admin@rightally.io']]);
    $this->artisan('tenants:bootstrap-hq')->assertSuccessful();
    $this->admin = User::factory()->create(['email' => 'admin@rightally.io']);
    $this->hq = 'http://app.rightally.io';
});

it('is only available on the HQ host', function (): void {
    $brokerage = Tenant::factory()->withPlatformDomain()->create(['slug' => 'jones']);

    $this->actingAs($this->admin)->get('http://jones.rightally.app/hq/tenants')->assertNotFound();
    $this->actingAs($this->admin)->get('http://localhost/hq/tenants')->assertNotFound();
});

it('requires sign-in and an allow-listed HQ admin', function (): void {
    $this->get($this->hq.'/hq/tenants')->assertRedirect();

    $this->actingAs(User::factory()->create(['email' => 'someone@else.com']))
        ->get($this->hq.'/hq/tenants')->assertForbidden();
});

it('lists and filters tenants', function (): void {
    Tenant::factory()->create(['name' => 'Lake Area Realty', 'slug' => 'lake-area']);
    Tenant::factory()->status(TenantStatus::Suspended)->create(['name' => 'Delta Homes', 'slug' => 'delta']);

    $this->actingAs($this->admin)->get($this->hq.'/hq/tenants?status=suspended')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hq/tenants/index')
            ->has('tenants.data', 1)
            ->where('tenants.data.0.slug', 'delta'));
});

it('provisions a tenant from the console, once per form submission', function (): void {
    $payload = [
        'type' => 'brokerage', 'name' => 'Magnolia Realty', 'slug' => 'magnolia', 'state_code' => 'ms',
        'owner_name' => 'Ann Lee', 'owner_email' => 'ANN@magnolia.com', 'idempotency_key' => 'form-visit-1',
    ];

    $this->actingAs($this->admin)->post($this->hq.'/hq/tenants', $payload)->assertRedirect();
    $this->actingAs($this->admin)->post($this->hq.'/hq/tenants', $payload)->assertRedirect();

    $tenant = Tenant::query()->where('slug', 'magnolia')->sole();
    expect($tenant->state_code)->toBe('MS')
        ->and($tenant->created_by_user_id)->toBe($this->admin->id);
});

it('shows validation errors from provisioning', function (): void {
    $this->actingAs($this->admin)->post($this->hq.'/hq/tenants', [
        'type' => 'brokerage', 'name' => 'X', 'slug' => 'admin', 'owner_name' => 'A', 'owner_email' => 'a@b.com', 'idempotency_key' => 'k',
    ])->assertSessionHasErrors('slug');
});

it('suspends, reactivates and closes with reasons', function (): void {
    $tenant = Tenant::factory()->create();
    $base = $this->hq.'/hq/tenants/'.$tenant->ulid;

    $this->actingAs($this->admin)->post($base.'/suspend', ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->admin)->post($base.'/suspend', ['reason' => 'Unpaid invoices'])->assertRedirect();
    expect($tenant->fresh()?->status)->toBe(TenantStatus::Suspended);

    $this->actingAs($this->admin)->post($base.'/reactivate')->assertRedirect();
    $this->actingAs($this->admin)->post($base.'/close', ['reason' => 'Brokerage sold'])->assertRedirect();
    expect($tenant->fresh()?->status)->toBe(TenantStatus::Closing);
});

it('refuses to suspend RightAlly HQ with a clear error', function (): void {
    $hq = Tenant::query()->where('type', 'hq')->sole();

    $this->actingAs($this->admin)->post($this->hq.'/hq/tenants/'.$hq->ulid.'/suspend', ['reason' => 'Testing it'])
        ->assertSessionHasErrors('reason');
    expect($hq->fresh()?->status)->toBe(TenantStatus::Active);
});

it('shows tenant detail with domains', function (): void {
    $tenant = Tenant::factory()->withPlatformDomain()->create(['slug' => 'detail-me']);

    $this->actingAs($this->admin)->get($this->hq.'/hq/tenants/'.$tenant->ulid)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hq/tenants/show')
            ->where('tenant.slug', 'detail-me')
            ->where('domains.0.host', 'detail-me.rightally.app'));
});

it('bootstraps HQ idempotently', function (): void {
    $this->artisan('tenants:bootstrap-hq')->assertSuccessful();

    expect(Tenant::query()->where('type', 'hq')->count())->toBe(1)
        ->and(Cell::query()->count())->toBe(1);
});
