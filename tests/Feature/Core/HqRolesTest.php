<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Access\HqRoles;
use Modules\Core\Access\Role;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/*
| HQ2: HQ staff have roles. Seven ready-made roles plus HQ's own; each page checks the role; a few things only Super
| admin can do; info@rightally.io becomes Super admin when roles are first set up; there is always a Super admin.
*/

beforeEach(function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->hq = Tenant::query()->where('type', 'hq')->sole();
    [$this->info, $this->sam] = app(TenantContext::class)->runAs($this->hq, fn () => [
        User::factory()->hqStaff()->create(['email' => 'info@rightally.io', 'first_name' => 'Info']),
        User::factory()->hqStaff()->create(['first_name' => 'Sam']),
    ]);
});

function hqUrl(string $path): string
{
    return 'http://app.rightally.io'.$path;
}

function hqRole(string $template): Role
{
    return app(TenantContext::class)->runAs(test()->hq, fn () => Role::query()->where('template_key', $template)->firstOrFail());
}

function giveHqRole(User $person, string $template): void
{
    app(TenantContext::class)->runAs(test()->hq, fn () => app(HqRoles::class)->assign($person, hqRole($template), test()->info));
}

it('sets up the roles the first time, with info@rightally.io as Super admin; others see only Today until given a role', function (): void {
    $this->actingAs($this->info)->get(hqUrl('/hq/roles'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('hq/roles')
        ->where('roles', fn ($r) => collect($r)->pluck('name')->sort()->values()->all() === ['Content and MLS', 'Engineering', 'Finance', 'Sales', 'Super admin', 'Support', 'View only'])
        ->where('people', fn ($p) => collect($p)->firstWhere('email', 'info@rightally.io')['role'] === hqRole(HqRoles::SUPER)->ulid
            && collect($p)->firstWhere('name', $this->sam->name)['role'] === null));

    $this->actingAs($this->sam)->get(hqUrl('/hq/dashboard'))->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('navigation', fn ($nav) => collect($nav)->pluck('key')->all() === ['Today']));
    $this->actingAs($this->sam)->get(hqUrl('/hq/tenants'))->assertForbidden();
});

it('lets each role use only its part of HQ, and see-only means no changes', function (): void {
    $this->actingAs($this->info)->get(hqUrl('/hq/roles'));
    giveHqRole($this->sam, 'hq_support');
    $this->actingAs($this->sam)->get(hqUrl('/hq/tenants'))->assertOk();
    $this->actingAs($this->sam)->get(hqUrl('/hq/mls'))->assertOk(); // see
    $this->actingAs($this->sam)->get(hqUrl('/hq/payments'))->assertForbidden();
    $this->actingAs($this->sam)->get(hqUrl('/hq/roles'))->assertForbidden();
    $this->actingAs($this->sam)->get(hqUrl('/hq/dashboard'))->assertInertia(fn (Assert $p) => $p
        ->where('navigation', fn ($nav) => collect($nav)->pluck('key')->all() === ['Today', 'Customers', 'Support', 'Websites and MLS', 'Movewise']));

    giveHqRole($this->sam, 'hq_finance');
    $this->actingAs($this->sam)->get(hqUrl('/hq/payout-rails'))->assertOk();
    // Payout rails are Super admin's to change, and signing in as a brokerage user needs its own permission.
    $this->actingAs($this->sam)->post(hqUrl('/hq/payout-rails/enable'), [])->assertForbidden();
});

it('lets the Super admin make roles, but never with the Super admin only permissions', function (): void {
    $this->actingAs($this->info)->get(hqUrl('/hq/roles'));
    $confirmed = ['auth.password_confirmed_at' => time()];
    $this->actingAs($this->info)->withSession($confirmed)->post(hqUrl('/hq/roles'), ['name' => 'Night desk', 'permissions' => ['hq.today.see', 'hq.payments.change']])
        ->assertSessionHasErrors('permissions');
    $this->actingAs($this->info)->withSession($confirmed)->post(hqUrl('/hq/roles'), ['name' => 'Night desk', 'permissions' => ['hq.today.see', 'hq.support.change', 'hq.support_access']])
        ->assertSessionHasNoErrors();
    $night = app(TenantContext::class)->runAs($this->hq, fn () => Role::query()->where('name', 'Night desk')->sole());
    $this->actingAs($this->info)->withSession($confirmed)->post(hqUrl("/hq/roles/people/{$this->sam->ulid}"), ['role' => $night->ulid])->assertSessionHasNoErrors();

    $this->actingAs($this->sam)->get(hqUrl('/hq/support'))->assertOk();
    $this->actingAs($this->sam)->withSession($confirmed)->post(hqUrl('/hq/roles'), ['name' => 'Sneaky', 'permissions' => ['hq.billing.change']])->assertForbidden();
});

it('always keeps a Super admin', function (): void {
    $this->actingAs($this->info)->get(hqUrl('/hq/roles'));
    $confirmed = ['auth.password_confirmed_at' => time()];
    $this->actingAs($this->info)->withSession($confirmed)->post(hqUrl("/hq/roles/people/{$this->info->ulid}"), ['role' => hqRole('hq_view_only')->ulid])
        ->assertSessionHasErrors('role');

    giveHqRole($this->sam, HqRoles::SUPER);
    $this->actingAs($this->info)->withSession($confirmed)->post(hqUrl("/hq/roles/people/{$this->info->ulid}"), ['role' => hqRole('hq_view_only')->ulid])
        ->assertSessionHasNoErrors();
});

it('keeps HQ permissions out of brokerages\' roles', function (): void {
    $this->onTenantHost('acme');
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner)->get('/settings/roles')->assertInertia(fn (Assert $p) => $p
        ->where('catalogue', fn ($c) => ! collect($c)->pluck('area')->contains('hq')));
});
