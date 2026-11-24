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
    expect(menuFor(User::factory()->owner()->create()))->toBe(['Dashboard', 'Game plan', "Today's 10", 'Tasks', 'Team chat', 'Leads', 'Inbox', 'Contacts', 'Appointments', 'Funnels', 'Reviews', 'Prospector', 'Referral network', 'Database', 'Outreach', 'Listings', 'Buyer demand', 'CMA and estimates', 'List-IQ', 'Movewise', 'Ally Studio', 'Postcards', 'Social posts', 'Ads', 'Marketing check', 'Deals', 'Review queue', 'Risk', 'Policies', 'Numbers', 'Reports', 'People', 'Directory', 'Roster and compliance', 'Recruiting', 'Agent database', 'Keeping agents', 'Learn', 'Agent activity', 'Money received', 'Payouts', 'Agent billing', 'Tax (1099)', 'Trust reconciliation', 'Chart of accounts', 'Journals', 'Adjustments', 'Month-end close', 'Books (QuickBooks, Xero)', 'Bills', 'Invoices', 'Bank reconciliation', 'Profit and loss', 'Audit pack', 'Plans', 'Fees', 'Credits', 'Simulator', 'Revenue share', 'Profitability', 'Apps', 'Licences', 'Website', 'Switch to RightAlly', 'AIlly assistant', 'Guided setup', 'MLS', 'Bring your old files', 'My availability', 'Ready-made library', 'Agent plans', 'RightAlly credits', 'Billing', 'Partner earnings', 'Refer a brokerage', 'Referral network', 'Transactions', 'Brand voice', 'Forms', 'AI blog writer', 'Automations', 'Custom fields', 'Roles', 'Access review', 'Features and seats', 'Outside services', 'Approvals', 'Offices and teams', 'Support access', 'Privacy requests', 'Fair Housing', 'Audit log', 'Help']);
});

it('shows agents only their own pages', function (): void {
    expect(menuFor(User::factory()->create()))->toBe(['Today', 'Game plan', "Today's 10", 'Tasks', 'Team chat', 'Leads', 'Inbox', 'Contacts', 'Appointments', 'Funnels', 'Reviews', 'Prospector', 'Referral network', 'Database', 'Outreach', 'Listings', 'Buyer demand', 'CMA and estimates', 'List-IQ', 'Movewise', 'Ally Studio', 'Postcards', 'Social posts', 'Ads', 'Marketing check', 'Deals', 'Policies', 'My plan', 'My money', 'Revenue share', 'My numbers', 'Reports', 'Business planner', 'Directory', 'Learn', 'My licences and onboarding', 'Refer an agent', 'Apps', 'My website', 'My availability', 'RightAlly credits', 'AI blog writer', 'My assistants', 'Help'])
        ->and(menuFor(User::factory()->assistantOf(User::factory()->create())->create()))->toBe(['Today', "Today's 10", 'Tasks', 'Team chat', 'Inbox', 'Contacts', 'Outreach', 'Listings', 'Deals', 'Policies', 'Learn', 'Help']);
});

it('shows staff the dashboard but not owner-only pages', function (): void {
    expect(menuFor(User::factory()->staff()->create()))->toBe(['Dashboard', "Today's 10", 'Tasks', 'Team chat', 'Leads', 'Inbox', 'Contacts', 'Funnels', 'Reviews', 'Outreach', 'Listings', 'Buyer demand', 'Ally Studio', 'Postcards', 'Social posts', 'Ads', 'Marketing check', 'Deals', 'Review queue', 'Risk', 'Policies', 'Numbers', 'Reports', 'Directory', 'Roster and compliance', 'Learn', 'Agent activity', 'Apps', 'Website', 'Switch to RightAlly', 'AIlly assistant', 'My availability', 'Ready-made library', 'RightAlly credits', 'Transactions', 'Forms', 'AI blog writer', 'Automations', 'Custom fields', 'Approvals', 'Help']);
});

it('shows HQ staff the HQ console and people', function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $hq = Tenant::query()->where('type', 'hq')->sole();
    $staff = app(TenantContext::class)->runAs($hq, fn () => User::factory()->hqStaff()->create());

    // HQ1: grouped under plain headings, in the order HQ works.
    $groups = collect(app(NavigationRegistry::class)->for($staff))->mapWithKeys(fn (array $g): array => [$g['label'] => array_column($g['items'], 'title')])->all();
    expect($groups)->toBe([
        'Overview' => ['Dashboard'],
        'HQ team' => ['People'],
        'Customers' => ['Brokerages', 'Health checks', 'Sign-ups', 'Go-live', 'Support', 'Help videos'],
        'Sales and pricing' => ['Package builder', 'Pricing', 'RightAlly credits', 'Referral programme'],
        'Payments' => ['Payments', 'Payout rails'],
        'Websites and MLS' => ['RightAlly website', 'Content desk', 'Websites', 'MLS connections', 'IDX coverage'],
        'AI' => ['AI gateway', 'AI quality', 'AI cost'],
        'Connections' => ['Outside services', 'Texts and calls', 'Connect setup', 'App demand', 'Partner apps'],
        'Products' => ['Referral network', 'Ally Studio', 'Movewise', 'Movewise agents', 'Movewise money', 'Movewise launch'],
        'System' => ['System health', 'Cells', 'Feature switches', 'Security controls'],
    ]);
});

it('lets modules add items, grouped and ordered', function (): void {
    app(NavigationRegistry::class)->add(new NavItem('Grow', 'Open houses', '/open-houses', 'users', 1, kinds: [UserKind::Agent]));

    $groups = app(NavigationRegistry::class)->for(User::factory()->create());

    // UX1: a brokerage's menu is grouped into plain main menus; an item a module adds to its own group keeps that group.
    expect(array_column($groups, 'label'))->toBe(['Home', 'Leads and clients', 'Listings', 'Marketing', 'Deals', 'Money', 'Reports', 'Team', 'Grow', 'Settings'])
        ->and($groups[1]['items'][0]['title'])->toBe('Leads')
        ->and($groups[8]['items'][0]['title'])->toBe('Open houses');
});

it('shares the brokerage name and the menu with every page', function (): void {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->get('/users')->assertInertia(fn (Assert $page) => $page
        ->where('tenant.name', $this->hostTenant?->name)
        ->where('tenant.isHq', false)
        ->where('navigation.0.label', 'Home')
        ->where('navigation.0.items.0.href', '/dashboard'));
});

it('shares no menu with guests', function (): void {
    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('navigation', []));
});
