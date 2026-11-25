<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/*
| HQ1: the HQ console has main menus with tabs, like a brokerage's, and every HQ page is in one of them.
*/

beforeEach(function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
});

it('shows the HQ main menus in order, with their tabs', function (): void {
    $this->actingAs($this->staff)->get('http://app.rightally.io/hq/dashboard')->assertInertia(fn (Assert $p) => $p
        ->where('navigation', fn ($nav) => collect($nav)->pluck('key')->all() === ['Today', 'Customers', 'Support', 'Billing', 'Websites and MLS', 'Movewise', 'Products', 'AI',
            'Connections', 'System', 'Settings'])
        ->where('navigation.0.items', fn ($items) => collect($items)->pluck('title')->all() === ['To do', 'Numbers'])
        ->where('navigation.1.items', fn ($items) => collect($items)->pluck('title')->all() === ['All brokerages', 'Sign-ups', 'Go-live', 'Health checks', 'Texting approvals'])
        ->where('navigation.5.items', fn ($items) => collect($items)->pluck('title')->all() === ['Overview', 'Agents', 'Money', 'Launch'])
        ->where('navigation.10.items', fn ($items) => collect($items)->pluck('href')->all() === ['/users', '/hq/roles', '/hq/controls', '/hq/switches']));
});

it('puts every HQ page in a menu (detail pages, new forms and downloads open from them)', function (): void {
    $pages = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => in_array('GET', $r->methods(), true) && str_starts_with($r->uri(), 'hq/'))
        ->map(fn ($r) => '/'.$r->uri())
        ->reject(fn (string $u) => str_contains($u, '{') || preg_match('/\.(csv|pdf)$/', $u) || str_ends_with($u, '/download') || str_ends_with($u, '/create') || str_ends_with($u, '/new')
            || str_starts_with($u, '/hq/guides/')) // HQ4: the menu guides' videos, not a page
        ->values();
    $menu = collect(NavigationRegistry::hqAddresses());

    expect($pages->diff($menu)->values()->all())->toBe([]);

    $this->actingAs($this->staff)->get('http://app.rightally.io/hq/dashboard')->assertInertia(fn (Assert $p) => $p
        ->where('navigation', fn ($nav) => $pages->diff(collect($nav)->flatMap(fn ($g) => collect($g['items'])->pluck('href')))->isEmpty()));
});
