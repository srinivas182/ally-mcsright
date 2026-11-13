<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

beforeEach(fn () => $this->onTenantHost('acme'));

/** @return list<string> titles visible to the user */
function menuFor(User $user): array
{
    return collect(app(NavigationRegistry::class)->for($user))->flatMap(fn ($g) => array_column($g['items'], 'title'))->all();
}

it('shows owners the dashboard, people and finance', function (): void {
    expect(menuFor(User::factory()->owner()->create()))->toBe(['Dashboard', "Today's 10", 'Tasks', 'Deals', 'Review queue', 'Contacts', 'Money received', 'Payouts', 'Agent billing', 'Tax (1099)', 'Trust reconciliation', 'Chart of accounts', 'Journals', 'Periods', 'People', 'Plans', 'Fees', 'Credits', 'Simulator', 'Revenue share', 'Profitability', 'Roster and compliance', 'Agent plans', 'Billing', 'Partner earnings', 'Transactions', 'Roles', 'Offices and teams', 'Audit log']);
});

it('shows agents only their own pages', function (): void {
    expect(menuFor(User::factory()->create()))->toBe(['Today', "Today's 10", 'Tasks', 'Deals', 'My plan', 'My money', 'Revenue share', 'Contacts'])
        ->and(menuFor(User::factory()->assistantOf(User::factory()->create())->create()))->toBe(['Today', "Today's 10", 'Tasks', 'Deals', 'Contacts']);
});

it('shows staff the dashboard but not owner-only pages', function (): void {
    expect(menuFor(User::factory()->staff()->create()))->toBe(['Dashboard', "Today's 10", 'Tasks', 'Deals', 'Review queue', 'Contacts', 'Roster and compliance', 'Transactions']);
});

it('shows HQ staff the HQ console and people', function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $hq = Tenant::query()->where('type', 'hq')->sole();
    $staff = app(TenantContext::class)->runAs($hq, fn () => User::factory()->hqStaff()->create());

    expect(menuFor($staff))->toBe(['People', 'Tenants', 'AI gateway', 'Pricing', 'Payments', 'Payout rails']);
});

it('lets modules add items, grouped and ordered', function (): void {
    app(NavigationRegistry::class)->add(new NavItem('Grow', 'Leads', '/leads', 'users', 5, kinds: [UserKind::Agent]));

    $groups = app(NavigationRegistry::class)->for(User::factory()->create());

    expect(array_column($groups, 'label'))->toBe(['Overview', 'Grow'])
        ->and($groups[1]['items'][0]['title'])->toBe('Leads')
        ->and($groups[1]['items'][1]['title'])->toBe('Contacts');
});

it('shares the brokerage name and the menu with every page', function (): void {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->get('/users')->assertInertia(fn (Assert $page) => $page
        ->where('tenant.name', $this->hostTenant?->name)
        ->where('tenant.isHq', false)
        ->where('navigation.0.label', 'Overview')
        ->where('navigation.0.items.0.href', '/dashboard'));
});

it('shares no menu with guests', function (): void {
    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('navigation', []));
});
