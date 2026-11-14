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
    expect(menuFor(User::factory()->owner()->create()))->toBe(['Dashboard', "Today's 10", 'Tasks', 'Deals', 'Review queue', 'Risk', 'Leads', 'Inbox', 'Listings', 'Contacts', 'Money received', 'Payouts', 'Agent billing', 'Tax (1099)', 'Trust reconciliation', 'Chart of accounts', 'Journals', 'Periods', 'People', 'Plans', 'Fees', 'Credits', 'Simulator', 'Revenue share', 'Profitability', 'Directory', 'Roster and compliance', 'Website', 'AIlly assistant', 'MLS', 'Agent plans', 'RightAlly credits', 'Billing', 'Partner earnings', 'Transactions', 'Forms', 'Roles', 'Offices and teams', 'Audit log']);
});

it('shows agents only their own pages', function (): void {
    expect(menuFor(User::factory()->create()))->toBe(['Today', "Today's 10", 'Tasks', 'Deals', 'My plan', 'My money', 'My licences and onboarding', 'Revenue share', 'Leads', 'Inbox', 'Listings', 'Contacts', 'Directory', 'My website', 'RightAlly credits'])
        ->and(menuFor(User::factory()->assistantOf(User::factory()->create())->create()))->toBe(['Today', "Today's 10", 'Tasks', 'Deals', 'Inbox', 'Listings', 'Contacts']);
});

it('shows staff the dashboard but not owner-only pages', function (): void {
    expect(menuFor(User::factory()->staff()->create()))->toBe(['Dashboard', "Today's 10", 'Tasks', 'Deals', 'Review queue', 'Risk', 'Leads', 'Inbox', 'Listings', 'Contacts', 'Directory', 'Roster and compliance', 'Website', 'AIlly assistant', 'RightAlly credits', 'Transactions', 'Forms']);
});

it('shows HQ staff the HQ console and people', function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $hq = Tenant::query()->where('type', 'hq')->sole();
    $staff = app(TenantContext::class)->runAs($hq, fn () => User::factory()->hqStaff()->create());

    expect(menuFor($staff))->toBe(['People', 'Tenants', 'Texts and calls', 'Websites', 'MLS connections', 'AI gateway', 'Pricing', 'RightAlly credits', 'Payments', 'Payout rails']);
});

it('lets modules add items, grouped and ordered', function (): void {
    app(NavigationRegistry::class)->add(new NavItem('Grow', 'Open houses', '/open-houses', 'users', 1, kinds: [UserKind::Agent]));

    $groups = app(NavigationRegistry::class)->for(User::factory()->create());

    expect(array_column($groups, 'label'))->toBe(['Overview', 'Grow', 'People', 'Settings'])
        ->and($groups[1]['items'][0]['title'])->toBe('Open houses')
        ->and($groups[1]['items'][1]['title'])->toBe('Leads');
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
