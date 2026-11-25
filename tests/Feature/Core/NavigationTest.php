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
    expect(menuFor(User::factory()->owner()->create()))->toBe(['Dashboard', "Today's 10", 'Game plan', 'Tasks', 'Team chat', 'Leads', 'Contacts', 'Pipeline', 'Inbox', 'Appointments', 'Campaigns', 'Movewise leads', 'Automations', 'Prospector', 'Database health', 'Referral network', 'MLS search', 'My listings', 'CMA and estimates', 'Buyer demand', 'List-IQ', 'All transactions', 'Compliance review', 'Risk', 'Checklists', 'Forms and e-sign', 'Vendors', 'Money coming in', 'Playbooks', 'Bring over old files', 'Policies', 'Transaction settings', 'Ally Studio', 'Social posts', 'Ads', 'Postcards', 'Funnels', 'Reviews', 'Marketing check', 'Brand voice', 'Website builder', 'Blog', 'AI blog writer', 'Photos and videos', 'Communities', 'Website assistant', 'MLS / IDX', 'People', 'Licences and onboarding', 'Recruiting', 'Join page', 'Agent database', 'Retention', 'Agent activity', 'Training', 'Directory', 'Commission plans', 'Ranks', 'Network tree', 'Recruiters', 'Revenue share', 'Payouts', 'Fees', 'Simulator', 'Credits', 'Money received', 'Agent billing', 'Invoices', 'Bills', 'Chart of accounts', 'Journals', 'Adjustments', 'Bank reconciliation', 'Month-end close', 'Books (QuickBooks, Xero)', 'Trust reconciliation', 'Tax (1099)', 'Profit and loss', 'Audit pack', 'Numbers', 'Reports', 'Profitability', 'Licences', 'Offices and teams', 'Roles', 'Features and seats', 'Custom fields', 'Referral network settings', 'Billing', 'RightAlly credits', 'Agent plans', 'Partner earnings', 'Refer a brokerage', 'Fair Housing', 'Privacy requests', 'Approvals', 'Access review', 'Audit log', 'Support access', 'Apps', 'Outside services', 'Guided setup', 'Switch to RightAlly', 'Bring your old files', 'Ready-made library', 'My availability', 'Help']);
});

it('shows agents only their own pages', function (): void {
    expect(menuFor(User::factory()->create()))->toBe(['Today', "Today's 10", 'Game plan', 'Tasks', 'Team chat', 'Leads', 'Contacts', 'Pipeline', 'Inbox', 'Appointments', 'Campaigns', 'Movewise leads', 'Prospector', 'Database health', 'Referral network', 'MLS search', 'My listings', 'CMA and estimates', 'Buyer demand', 'List-IQ', 'My transactions', 'Vendors', 'Money coming in', 'Playbooks', 'Ally Studio', 'Social posts', 'Ads', 'Postcards', 'Funnels', 'Reviews', 'Marketing check', 'AI blog writer', 'My website', 'My money', 'My plan', 'Revenue share', 'Refer an agent', 'My numbers', 'Reports', 'Business planner', 'Training', 'My licences and onboarding', 'Policies', 'Directory', 'RightAlly credits', 'Apps', 'My availability', 'My assistants', 'Help'])
        ->and(menuFor(User::factory()->assistantOf(User::factory()->create())->create()))->toBe(['Today', "Today's 10", 'Tasks', 'Team chat', 'Contacts', 'Pipeline', 'Inbox', 'Campaigns', 'MLS search', 'My transactions', 'Training', 'Policies', 'Help']);
});

it('shows staff the dashboard but not owner-only pages', function (): void {
    expect(menuFor(User::factory()->staff()->create()))->toBe(['Dashboard', "Today's 10", 'Tasks', 'Team chat', 'Leads', 'Contacts', 'Pipeline', 'Inbox', 'Campaigns', 'Automations', 'MLS search', 'My listings', 'Buyer demand', 'All transactions', 'Compliance review', 'Risk', 'Checklists', 'Forms and e-sign', 'Vendors', 'Money coming in', 'Playbooks', 'Bring over old files', 'Policies', 'Transaction settings', 'Ally Studio', 'Social posts', 'Ads', 'Postcards', 'Funnels', 'Reviews', 'Marketing check', 'Website builder', 'Blog', 'AI blog writer', 'Photos and videos', 'Communities', 'Website assistant', 'Licences and onboarding', 'Agent activity', 'Training', 'Directory', 'Numbers', 'Reports', 'Custom fields', 'RightAlly credits', 'Approvals', 'Apps', 'Switch to RightAlly', 'Ready-made library', 'My availability', 'Help']);
});

it('shows HQ staff the HQ console and people', function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $hq = Tenant::query()->where('type', 'hq')->sole();
    $staff = app(TenantContext::class)->runAs($hq, fn () => User::factory()->hqStaff()->create());

    // HQ1: main menus with tabs, like a brokerage's, in the order HQ works; Settings pinned last.
    $groups = collect(app(NavigationRegistry::class)->for($staff))->mapWithKeys(fn (array $g): array => [$g['label'] => array_column($g['items'], 'title')])->all();
    expect($groups)->toBe([
        'Today' => ['Numbers'],
        'Customers' => ['All brokerages', 'Sign-ups', 'Go-live', 'Health checks', 'Texting approvals'],
        'Support' => ['Inbox', 'Help videos', 'Card orders'],
        'Billing' => ['Packages', 'Pricing', 'Credits', 'Referral programme', 'Payments', 'Payout rails'],
        'Websites and MLS' => ['RightAlly website', 'Content desk', 'Brokerage websites', 'MLS connections', 'MLS search', 'Photos and places', 'IDX coverage'],
        'Movewise' => ['Overview', 'Agents', 'Money', 'Launch'],
        'Products' => ['Referral network', 'Ally Studio'],
        'AI' => ['Gateway', 'Quality', 'Cost'],
        'Connections' => ['Outside services', 'Texts and calls', 'Connect setup', 'App demand', 'Partner apps'],
        'System' => ['System health', 'Cells'],
        'Settings' => ['HQ team', 'Security controls', 'Feature switches'],
    ]);
});

it('lets modules add items, grouped and ordered', function (): void {
    app(NavigationRegistry::class)->add(new NavItem('Grow', 'Open houses', '/open-houses', 'users', 1, kinds: [UserKind::Agent]));

    $groups = app(NavigationRegistry::class)->for(User::factory()->create());

    // MN3: a brokerage's menu is its main menus; a page not placed yet goes to Settings › More, so nothing disappears.
    expect(array_column($groups, 'label'))->toBe(['Dashboard', 'CRM', 'Listings', 'Transactions', 'Marketing', 'My commissions', 'Reports', 'Training', 'Settings'])
        ->and($groups[1]['items'][0]['title'])->toBe('Leads')
        ->and(end($groups)['items'])->toContain(['title' => 'Open houses', 'href' => '/open-houses', 'icon' => 'users', 'mobile' => false, 'section' => 'More']);
});

it('shares the brokerage name and the menu with every page', function (): void {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->get('/users')->assertInertia(fn (Assert $page) => $page
        ->where('tenant.name', $this->hostTenant?->name)
        ->where('tenant.isHq', false)
        ->where('navigation.0.label', 'Dashboard')
        ->where('navigation.0.key', 'Dashboard')
        ->where('navigation.0.items.0.href', '/dashboard'));
});

it('shares no menu with guests', function (): void {
    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('navigation', []));
});
