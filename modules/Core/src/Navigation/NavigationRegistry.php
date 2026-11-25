<?php

declare(strict_types=1);

namespace Modules\Core\Navigation;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Identity\AgentAssistants;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Simple\SimpleMode;
use Modules\Core\Switches\FeatureSwitches;

/**
 * Modules register their menu items here (an extension point: plugins can add items without
 * touching the core). Each user receives only the items their kind and permissions allow; the
 * same list drives the sidebar, the phone bottom bar and the command bar.
 */
final class NavigationRegistry
{
    /**
     * MN3: a brokerage's main menus, in the words brokers already use (CRM, Transactions, Commissions, Accounting…)
     * and in the order they are used, daily first. Each main menu opens a page whose tabs are its pages; a section
     * label groups tabs where a menu holds many (Accounting, Settings). Pages are placed by their address, so modules
     * keep registering items as before; the order here is the order of the tabs.
     *
     * @var array<string, array<string, list<string>>> menu => section ('' for none) => addresses
     */
    private const TENANT_MENUS = [
        'Dashboard' => ['' => ['/dashboard', '/agent', '/today', '/engage', '/tasks', '/chat']],
        'CRM' => ['' => ['/leads', '/contacts', '/pipeline', '/inbox', '/appointments', '/outreach', '/movewise', '/automations', '/prospector', '/insights/database', '/network']],
        'Listings' => ['' => ['/listings', '/deals/listings', '/pricing', '/listings/demand', '/list-iq']],
        'Transactions' => ['' => ['/deals', '/deals/review', '/deals/risk', '/deals/compliance', '/forms/templates', '/deals/vendors', '/deals/money', '/deals/playbooks',
            '/deals/bring-over-files', '/policies', '/settings/transactions']],
        'Marketing' => ['' => ['/studio', '/social', '/ads', '/postcards', '/funnels', '/reviews', '/marketing-check', '/settings/brand-voice']],
        'Website & IDX' => ['' => ['/settings/website', '/settings/my-website', '/settings/website/blog', '/settings/blog-writer', '/settings/website/media', '/settings/website/areas',
            '/settings/ailly', '/settings/mls']],
        'Agents' => ['' => ['/users', '/roster', '/recruiting', '/recruiting/join-page', '/recruiting/agents', '/recruiting/retention', '/engage/activity', '/learn', '/directory']],
        'Commissions' => ['' => ['/compensation/plans', '/compensation/ranks', '/compensation/network', '/compensation/recruiters', '/compensation/revenue-share', '/finance/payouts', '/compensation/fees', '/compensation/simulator', '/compensation/credits']],
        'My commissions' => ['' => ['/my-money', '/my-plan', '/revenue-share', '/plans', '/recruiting/refer']],
        'Accounting' => [
            'Money in and out' => ['/finance/receipts', '/finance/agent-billing', '/finance/invoices', '/finance/bills'],
            'Books' => ['/finance/accounts', '/finance/journals', '/finance/adjustments', '/finance/bank', '/finance/periods', '/finance/books'],
            'Trust, tax and audit' => ['/finance/trust', '/finance/tax', '/finance/statements', '/finance/audit'],
        ],
        'Reports' => ['' => ['/insights', '/insights/me', '/insights/reports', '/compensation/profitability', '/insights/plan']],
        'Training' => ['' => ['/learn', '/roster/me', '/policies', '/directory']],
        'Settings' => [
            'Brokerage' => ['/settings/licences', '/settings/organisation', '/settings/roles', '/settings/features', '/settings/custom-fields', '/settings/network'],
            'Billing' => ['/settings/billing', '/settings/credits', '/settings/agent-plans', '/settings/partner', '/settings/refer'],
            'Compliance' => ['/settings/fair-housing', '/settings/privacy', '/settings/approvals', '/settings/access-review', '/audit', '/settings/support-access'],
            'Apps' => ['/apps', '/settings/services'],
            'Getting started' => ['/setup/guide', '/switch', '/setup/import', '/setup/library'],
            'My account' => ['/settings/availability', '/my-assistants', '/help'],
        ],
    ];

    /** MN3: agents learn and earn in their own main menus (owners find these pages under Agents and Transactions). */
    private const AGENT_MENUS = ['/learn' => 'Training', '/directory' => 'Training', '/policies' => 'Training', '/roster/me' => 'Training',
        '/settings/my-website' => 'Marketing', '/settings/blog-writer' => 'Marketing'];

    /** MN3: tab names in the words brokers use (the page itself is unchanged). */
    private const TENANT_TITLES = ['/deals' => 'All transactions', '/deals/review' => 'Compliance review', '/deals/compliance' => 'Checklists', '/forms/templates' => 'Forms and e-sign',
        '/outreach' => 'Campaigns', '/movewise' => 'Movewise leads', '/insights/database' => 'Database health', '/listings' => 'MLS search', '/deals/listings' => 'My listings',
        '/roster' => 'Licences and onboarding', '/recruiting/retention' => 'Retention', '/learn' => 'Training', '/compensation/plans' => 'Commission plans', '/settings/ailly' => 'Website assistant',
        '/settings/mls' => 'MLS / IDX', '/settings/network' => 'Referral network settings', '/plans' => 'Plans and fees'];

    /**
     * HQ1: the HQ console's main menus, like a brokerage's: each opens a page whose tabs are its pages. Every HQ page is
     * placed here (a test fails when one is not); detail pages, "new" forms and downloads open from these pages.
     *
     * @var array<string, list<string>> menu => addresses, in tab order
     */
    private const HQ_MENUS = [
        'Today' => ['/hq/dashboard'],
        'Customers' => ['/hq/tenants', '/hq/signups', '/hq/go-live', '/hq/health-checks', '/hq/texting'],
        'Support' => ['/hq/support', '/hq/support/videos', '/hq/cards'],
        'Billing' => ['/hq/packages', '/hq/billing', '/hq/credits', '/hq/affiliates', '/hq/payments', '/hq/payout-rails'],
        'Websites and MLS' => ['/hq/website', '/hq/content', '/hq/sites', '/hq/mls', '/hq/mls/search', '/hq/mls/photos-places', '/hq/idx'],
        'Movewise' => ['/hq/movewise', '/hq/movewise/program', '/hq/movewise/money', '/hq/movewise/launch'],
        'Products' => ['/hq/network', '/hq/studio'],
        'AI' => ['/hq/ai', '/hq/ai/quality', '/hq/ai/cost'],
        'Connections' => ['/hq/services', '/hq/messaging', '/hq/connect', '/hq/apps', '/hq/partners'],
        'System' => ['/hq/watchtower', '/hq/cells'],
        'Settings' => ['/users', '/hq/controls', '/hq/switches'],
    ];

    /** HQ1: tab names in the HQ console. */
    private const HQ_TITLES = ['/hq/dashboard' => 'Numbers', '/hq/tenants' => 'All brokerages', '/hq/support' => 'Inbox', '/hq/billing' => 'Pricing', '/hq/packages' => 'Packages',
        '/hq/credits' => 'Credits', '/hq/sites' => 'Brokerage websites', '/hq/movewise' => 'Overview', '/hq/movewise/program' => 'Agents', '/hq/movewise/money' => 'Money',
        '/hq/movewise/launch' => 'Launch', '/hq/ai' => 'Gateway', '/hq/ai/quality' => 'Quality', '/hq/ai/cost' => 'Cost', '/users' => 'HQ team'];

    /** MN3: an agent's own wording. */
    private const AGENT_TITLES = ['/deals' => 'My transactions', '/deals/listings' => 'My listings'];

    /** MN3: the order of the main menus. */
    private const TENANT_ORDER = ['Dashboard', 'CRM', 'Listings', 'Transactions', 'Marketing', 'Website & IDX', 'Agents', 'Commissions', 'My commissions', 'Accounting',
        'Reports', 'Training', 'Settings'];

    /** @var list<NavItem> */
    private array $items = [];

    public function add(NavItem $item): void
    {
        $this->items[] = $item;
    }

    /**
     * @return list<array{key: string, label: string, items: list<array{title: string, href: string, icon: string, mobile: bool, section?: string}>}>
     */
    public function for(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $switches = app(FeatureSwitches::class);
        $assistants = app(AgentAssistants::class);
        $visible = array_filter($this->items, fn (NavItem $item): bool => ($item->kinds === [] || in_array($user->kind, $item->kinds, true))
            && ($item->ability === null || Gate::forUser($user)->allows($item->ability))
            && $switches->allowsPath($user, $item->href) // FL1: switched-off areas and seats
            && $assistants->allowsPath($user, $item->href)); // AG1: an assistant's preset

        $hq = $user->kind === UserKind::HqStaff;
        $agent = in_array($user->kind, [UserKind::Agent, UserKind::Assistant], true);
        $place = fn (NavItem $item): array => $hq ? self::hqPlace($item) : self::tenantPlace($item, $agent);
        usort($visible, fn (NavItem $a, NavItem $b): int => [$place($a)[2], $place($a)[3], $a->title] <=> [$place($b)[2], $place($b)[3], $b->title]);

        $groups = [];
        foreach ($visible as $item) {
            [$menu, $section] = $place($item);
            $title = $hq ? (self::HQ_TITLES[$item->href] ?? $item->title) : ($agent ? (self::AGENT_TITLES[$item->href] ?? self::TENANT_TITLES[$item->href] ?? $item->title) : (self::TENANT_TITLES[$item->href] ?? $item->title));
            $groups[$menu][] = ['title' => $title, 'href' => $item->href, 'icon' => $item->icon, 'mobile' => $item->mobile] + ($section !== '' ? ['section' => $section] : []);
        }

        $out = [];
        foreach ($groups as $label => $items) {
            $label = (string) $label;
            // MN3: `key` stays in English (the help guide and icons use it); `label` may be translated.
            $out[] = ['key' => $label, 'label' => $label, 'items' => $items];
        }

        // Simple mode (SM1): a short menu of the pages used most, from the pages this person can open.
        // Otherwise the full menu, in Spanish for people who chose it (PW1).
        return SimpleMode::on($user) ? SimpleMode::menu($out, SimpleMode::locale($user)) : NavWords::translate($out, SimpleMode::locale($user));
    }

    /**
     * Where a brokerage's page goes: [menu, section, menu position, position within the menu].
     *
     * @return array{0: string, 1: string, 2: int, 3: int}
     */
    private static function tenantPlace(NavItem $item, bool $agent): array
    {
        if ($agent && isset(self::AGENT_MENUS[$item->href])) {
            $menu = self::AGENT_MENUS[$item->href];
            $at = array_search($item->href, self::TENANT_MENUS[$menu][''], true);

            return [$menu, '', (int) array_search($menu, self::TENANT_ORDER, true), $at === false ? 999 : (int) $at];
        }
        $n = 0;
        foreach (self::TENANT_MENUS as $menu => $sections) {
            if ($menu === 'Training' || ($agent && $menu === 'Agents' && in_array($item->href, ['/learn', '/directory'], true))) {
                continue;
            }
            foreach ($sections as $section => $paths) {
                foreach ($paths as $path) {
                    if ($path === $item->href) {
                        return [$menu, $section, (int) array_search($menu, self::TENANT_ORDER, true), $n];
                    }
                    $n++;
                }
            }
        }

        // Not placed yet: Settings › More, so nothing ever disappears from the menu.
        return ['Settings', 'More', (int) array_search('Settings', self::TENANT_ORDER, true), 10_000 + $item->order];
    }

    /**
     * HQ1: where an HQ page goes: [menu, section, menu position, position within the menu].
     *
     * @return array{0: string, 1: string, 2: int, 3: int}
     */
    private static function hqPlace(NavItem $item): array
    {
        $index = 0;
        foreach (self::HQ_MENUS as $menu => $paths) {
            $at = array_search($item->href, $paths, true);
            if ($at !== false) {
                return [$menu, '', $index, (int) $at];
            }
            $index++;
        }

        // Not placed yet: Settings › More, so nothing ever disappears from the menu.
        return ['Settings', 'More', $index, 10_000 + $item->order];
    }

    /**
     * HQ1: every address the HQ menus place (for the test that no HQ page is left out).
     *
     * @return list<string>
     */
    public static function hqAddresses(): array
    {
        return array_merge(...array_values(self::HQ_MENUS));
    }
}
