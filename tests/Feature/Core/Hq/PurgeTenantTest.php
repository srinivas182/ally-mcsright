<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Enums\TenantStatus;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\Models\TenantDomain;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Models\Contact;

/*
| TD1: HQ can delete a closed brokerage for good: its data, files, website and addresses go, and the address can be
| used again. It must be closed first, HQ types the address and confirms its password, and HQ itself can't be deleted.
*/

beforeEach(function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq')->assertSuccessful();
    $this->hqTenant = Tenant::query()->where('type', 'hq')->sole();
    $this->admin = app(TenantContext::class)->runAs($this->hqTenant, fn () => User::factory()->hqStaff()->create());
    $this->hq = 'http://app.rightally.io';
    Storage::fake('local');
    $this->jones = Tenant::factory()->withPlatformDomain()->create(['slug' => 'jones', 'name' => 'Jones Realty']);
    $this->other = Tenant::factory()->withPlatformDomain()->create(['slug' => 'oak', 'name' => 'Oak Realty']);
    foreach ([$this->jones, $this->other] as $t) {
        app(TenantContext::class)->runAs($t, function (): void {
            User::factory()->owner()->create();
            foreach (['Ann', 'Bo'] as $first) {
                Contact::query()->create(['first_name' => $first, 'last_name' => 'Test', 'origin' => 'company', 'source' => 'manual', 'emails' => [], 'phones' => []]);
            }
        });
    }
    Storage::disk('local')->put('forms/'.$this->jones->id.'/a.pdf', 'x');
    Storage::disk('local')->put('forms/'.$this->other->id.'/b.pdf', 'x');
});

it('deletes a closed brokerage\'s data, files and addresses, and leaves every other brokerage alone', function (): void {
    $as = fn () => $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()]);
    // Not before it is closed.
    $as()->post($this->hq.'/hq/tenants/'.$this->jones->ulid.'/purge', ['confirm' => 'jones'])->assertSessionHasErrors('tenant');

    $this->jones->forceFill(['status' => TenantStatus::Closing, 'closing_at' => now()])->save();
    $as()->post($this->hq.'/hq/tenants/'.$this->jones->ulid.'/purge', ['confirm' => 'jone'])->assertSessionHasErrors('confirm');
    $as()->post($this->hq.'/hq/tenants/'.$this->jones->ulid.'/purge', ['confirm' => 'jones'])->assertRedirect('/hq/tenants');

    $cell = DB::connection(Connections::cell());
    expect($cell->table('users')->where('tenant_id', $this->jones->id)->count())->toBe(0)
        ->and($cell->table('crm_contacts')->where('tenant_id', $this->jones->id)->count())->toBe(0)
        ->and($cell->table('users')->where('tenant_id', $this->other->id)->count())->toBe(1)
        ->and($cell->table('crm_contacts')->where('tenant_id', $this->other->id)->count())->toBe(2)
        ->and(TenantDomain::query()->where('tenant_id', $this->jones->id)->exists())->toBeFalse()
        ->and(TenantDomain::query()->where('tenant_id', $this->other->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists('forms/'.$this->jones->id.'/a.pdf'))->toBeFalse()
        ->and(Storage::disk('local')->exists('forms/'.$this->other->id.'/b.pdf'))->toBeTrue()
        ->and(Tenant::query()->where('slug', 'jones')->exists())->toBeFalse() // the address is free again
        ->and(Tenant::withTrashed()->find($this->jones->id)?->trashed())->toBeTrue();
});

it('asks for the password and never deletes HQ', function (): void {
    $this->jones->forceFill(['status' => TenantStatus::Closed, 'closed_at' => now()])->save();
    $this->actingAs($this->admin)->post($this->hq.'/hq/tenants/'.$this->jones->ulid.'/purge', ['confirm' => 'jones'])->assertRedirect();
    expect(Tenant::query()->where('slug', 'jones')->exists())->toBeTrue();

    $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])
        ->post($this->hq.'/hq/tenants/'.$this->hqTenant->ulid.'/purge', ['confirm' => $this->hqTenant->slug])->assertSessionHasErrors('tenant');
});

it('never suspends, closes or deletes the design demo website (DP1)', function (): void {
    $demo = Tenant::factory()->withPlatformDomain()->create(['slug' => 'demo', 'name' => 'Your Brokerage']);
    $as = fn () => $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()]);
    $this->actingAs($this->admin)->get($this->hq.'/hq/tenants/'.$demo->ulid)->assertInertia(fn ($p) => $p->where('tenant.is_protected', true));
    $as()->post($this->hq.'/hq/tenants/'.$demo->ulid.'/close', ['reason' => 'Testing the guard here'])->assertSessionHasErrors();
    $demo->forceFill(['status' => TenantStatus::Closed])->save(); // even if it were closed somehow
    $as()->post($this->hq.'/hq/tenants/'.$demo->ulid.'/purge', ['confirm' => 'demo'])->assertSessionHasErrors('tenant');
    expect(Tenant::query()->where('slug', 'demo')->exists())->toBeTrue();
});
