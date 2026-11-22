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
    expect(menuFor(User::factory()->owner()->create()))->toBe(['Dashboard', 'Game plan', "Today's 10", 'Tasks', 'Deals', 'Review queue', 'Risk', 'Team chat', 'Numbers', 'Policies', 'Reports', 'Database', 'Leads', 'Inbox', 'Listings', 'Buyer demand', 'CMA and estimates', 'Contacts', 'List-IQ', 'Appointments', 'Ally Studio', 'Funnels', 'Postcards', 'Reviews', 'Movewise', 'Prospector', 'Social posts', 'Ads', 'Referral network', 'Marketing check', 'Outreach', 'Money received', 'Payouts', 'Agent billing', 'Tax (1099)', 'Trust reconciliation', 'Chart of accounts', 'Journals', 'Adjustments', 'Month-end close', 'Books (QuickBooks, Xero)', 'Bills', 'Invoices', 'Bank reconciliation', 'Profit and loss', 'Audit pack', 'People', 'Plans', 'Fees', 'Credits', 'Simulator', 'Revenue share', 'Profitability', 'Directory', 'Roster and compliance', 'Recruiting', 'Agent database', 'Keeping agents', 'Learn', 'Agent activity', 'Apps', 'Website', 'Switch to RightAlly', 'AIlly assistant', 'Guided setup', 'MLS', 'Bring your old files', 'My availability', 'Ready-made library', 'Agent plans', 'RightAlly credits', 'Billing', 'Partner earnings', 'Refer a brokerage', 'Referral network', 'Transactions', 'Brand voice', 'Forms', 'AI blog writer', 'Automations', 'Custom fields', 'Roles', 'Access review', 'Features and seats', 'Outside services', 'Approvals', 'Offices and teams', 'Support access', 'Privacy requests', 'Fair Housing', 'Audit log', 'Help']);
});

it('shows agents only their own pages', function (): void {
    expect(menuFor(User::factory()->create()))->toBe(['Today', 'Game plan', "Today's 10", 'Tasks', 'Deals', 'Team chat', 'My numbers', 'Policies', 'Reports', 'Business planner', 'My plan', 'Database', 'My money', 'My licences and onboarding', 'Revenue share', 'Leads', 'Inbox', 'Listings', 'Buyer demand', 'CMA and estimates', 'Contacts', 'List-IQ', 'Appointments', 'Ally Studio', 'Funnels', 'Postcards', 'Reviews', 'Movewise', 'Prospector', 'Social posts', 'Ads', 'Referral network', 'Marketing check', 'Refer an agent', 'Outreach', 'Directory', 'Learn', 'Apps', 'My website', 'My availability', 'RightAlly credits', 'AI blog writer', 'My assistants', 'Help'])
        ->and(menuFor(User::factory()->assistantOf(User::factory()->create())->create()))->toBe(['Today', "Today's 10", 'Tasks', 'Deals', 'Team chat', 'Policies', 'Inbox', 'Listings', 'Contacts', 'Outreach', 'Learn', 'Help']);
});

it('shows staff the dashboard but not owner-only pages', function (): void {
    expect(menuFor(User::factory()->staff()->create()))->toBe(['Dashboard', "Today's 10", 'Tasks', 'Deals', 'Review queue', 'Risk', 'Team chat', 'Numbers', 'Policies', 'Reports', 'Leads', 'Inbox', 'Listings', 'Buyer demand', 'Contacts', 'Ally Studio', 'Funnels', 'Postcards', 'Reviews', 'Social posts', 'Ads', 'Marketing check', 'Outreach', 'Directory', 'Roster and compliance', 'Learn', 'Agent activity', 'Apps', 'Website', 'Switch to RightAlly', 'AIlly assistant', 'My availability', 'Ready-made library', 'RightAlly credits', 'Transactions', 'Forms', 'AI blog writer', 'Automations', 'Custom fields', 'Approvals', 'Help']);
});

it('shows HQ staff the HQ console and people', function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $hq = Tenant::query()->where('type', 'hq')->sole();
    $staff = app(TenantContext::class)->runAs($hq, fn () => User::factory()->hqStaff()->create());

    // HQ1: grouped under plain headings, in the order HQ works.
    $groups = collect(app(NavigationRegistry::class)->for($staff))->mapWithKeys(fn (array $g): array => [$g['label'] => array_column($g['items'], 'title')])->all();
    expect($groups)->toBe([
        'HQ team' => ['People'],
        'Customers' => ['Brokerages', 'Health checks', 'Go-live', 'Support', 'Help videos'],
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
