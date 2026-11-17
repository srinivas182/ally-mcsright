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
    expect(menuFor(User::factory()->owner()->create()))->toBe(['Dashboard', "Today's 10", 'Tasks', 'Deals', 'Review queue', 'Risk', 'Numbers', 'Policies', 'Reports', 'Database', 'Leads', 'Inbox', 'Listings', 'Buyer demand', 'CMA and estimates', 'Contacts', 'List-IQ', 'Ally Studio', 'Funnels', 'Postcards', 'Reviews', 'Movewise', 'Prospector', 'Social posts', 'Ads', 'Outreach', 'Money received', 'Payouts', 'Agent billing', 'Tax (1099)', 'Trust reconciliation', 'Chart of accounts', 'Journals', 'Periods', 'Books (QuickBooks, Xero)', 'People', 'Plans', 'Fees', 'Credits', 'Simulator', 'Revenue share', 'Profitability', 'Directory', 'Roster and compliance', 'Recruiting', 'Agent database', 'Keeping agents', 'Apps', 'Website', 'Switch to RightAlly', 'AIlly assistant', 'MLS', 'My availability', 'Agent plans', 'RightAlly credits', 'Billing', 'Partner earnings', 'Transactions', 'Forms', 'Automations', 'Custom fields', 'Roles', 'Access review', 'Offices and teams', 'Fair Housing', 'Audit log']);
});

it('shows agents only their own pages', function (): void {
    expect(menuFor(User::factory()->create()))->toBe(['Today', "Today's 10", 'Tasks', 'Deals', 'My numbers', 'Policies', 'Reports', 'Business planner', 'My plan', 'Database', 'My money', 'My licences and onboarding', 'Revenue share', 'Leads', 'Inbox', 'Listings', 'Buyer demand', 'CMA and estimates', 'Contacts', 'List-IQ', 'Ally Studio', 'Funnels', 'Postcards', 'Reviews', 'Movewise', 'Prospector', 'Social posts', 'Ads', 'Refer an agent', 'Outreach', 'Directory', 'Apps', 'My website', 'My availability', 'RightAlly credits'])
        ->and(menuFor(User::factory()->assistantOf(User::factory()->create())->create()))->toBe(['Today', "Today's 10", 'Tasks', 'Deals', 'Policies', 'Inbox', 'Listings', 'Contacts', 'Outreach']);
});

it('shows staff the dashboard but not owner-only pages', function (): void {
    expect(menuFor(User::factory()->staff()->create()))->toBe(['Dashboard', "Today's 10", 'Tasks', 'Deals', 'Review queue', 'Risk', 'Numbers', 'Policies', 'Reports', 'Leads', 'Inbox', 'Listings', 'Buyer demand', 'Contacts', 'Ally Studio', 'Funnels', 'Postcards', 'Reviews', 'Social posts', 'Ads', 'Outreach', 'Directory', 'Roster and compliance', 'Apps', 'Website', 'Switch to RightAlly', 'AIlly assistant', 'My availability', 'RightAlly credits', 'Transactions', 'Forms', 'Automations', 'Custom fields']);
});

it('shows HQ staff the HQ console and people', function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $hq = Tenant::query()->where('type', 'hq')->sole();
    $staff = app(TenantContext::class)->runAs($hq, fn () => User::factory()->hqStaff()->create());

    expect(menuFor($staff))->toBe(['People', 'Tenants', 'Health checks', 'Go-live', 'Texts and calls', 'Websites', 'App demand', 'MLS connections', 'Connect setup', 'AI gateway', 'Pricing', 'RightAlly credits', 'Ally Studio', 'Movewise', 'Movewise agents', 'Movewise launch', 'Movewise money', 'Payments', 'Payout rails']);
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
