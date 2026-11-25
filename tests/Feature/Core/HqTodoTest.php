<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Access\HqRoles;
use Modules\Core\Access\Role;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/*
| HQ3: Today › To do lists everything waiting for HQ, with a count in the menu; HQ staff open it first, and each
| person only sees the lines for the parts of HQ their role allows.
*/

beforeEach(function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->hq = Tenant::query()->where('type', 'hq')->sole();
    [$this->info, $this->sam] = app(TenantContext::class)->runAs($this->hq, fn () => [
        User::factory()->hqStaff()->create(['email' => 'info@rightally.io']),
        User::factory()->hqStaff()->create(),
    ]);
    $control = DB::connection(Connections::control());
    $a = Tenant::factory()->create();
    $ticket = fn (string $subject): array => ['ulid' => (string) str()->ulid(), 'tenant_id' => $a->id, 'user_id' => 1, 'user_name' => 'Ann', 'user_email' => 'ann@example.com',
        'topic' => 'other', 'subject' => $subject, 'body' => encrypt('x'), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()];
    $control->table('support_tickets')->insert([$ticket('Help'), $ticket('Help again'), $ticket('Please switch on Reviews')]);
    $control->table('billing_tenant_subscriptions')->insert(['tenant_id' => $a->id, 'package_id' => 1, 'interval' => 'month', 'status' => 'grace', 'price_cents' => 49_900,
        'created_at' => now(), 'updated_at' => now()]);
    Cache::flush();
});

function todoUrl(string $path): string
{
    return 'http://app.rightally.io'.$path;
}

it('lists what is waiting, with what to do and where, and counts it in the menu', function (): void {
    $this->actingAs($this->info)->get(todoUrl('/hq/today'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('hq/today')
        ->where('items', fn ($items): bool => collect($items)->pluck('count', 'key')->all() === ['switch_on' => 1, 'support' => 2, 'overdue' => 1])
        ->where('items.0.href', '/hq/support')
        ->where('navigation.0.items.0.href', '/hq/today')
        ->where('navigation.0.items.0.badge', 4));
});

it('is the first page HQ staff see', function (): void {
    $this->actingAs($this->info)->get(todoUrl('/dashboard'))->assertRedirect('/hq/today');
});

it('shows each person only the lines for their part of HQ', function (): void {
    $this->actingAs($this->info)->get(todoUrl('/hq/roles')); // sets up the roles
    $support = app(TenantContext::class)->runAs($this->hq, fn () => Role::query()->where('template_key', 'hq_support')->firstOrFail());
    app(TenantContext::class)->runAs($this->hq, fn () => app(HqRoles::class)->assign($this->sam, $support, $this->info));
    Cache::flush();

    $this->actingAs($this->sam)->get(todoUrl('/hq/today'))->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('items', fn ($items): bool => collect($items)->pluck('key')->all() === ['switch_on', 'support'])
        ->where('navigation.0.items.0.badge', 3));
});

it('is never open to a brokerage', function (): void {
    $brokerage = Tenant::factory()->create();
    $owner = app(TenantContext::class)->runAs($brokerage, fn () => User::factory()->owner()->create());
    expect($this->actingAs($owner)->get(todoUrl('/hq/today'))->status())->toBeIn([302, 403, 404]);
});
