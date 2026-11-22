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
    /** Group order in the sidebar: the brokerage's groups, then the HQ console's (HQ1, plain names for what each holds). */
    private const GROUPS = ['Overview', 'Grow', 'Run', 'Finance', 'Protect', 'Know', 'Brokerage',
        'HQ team', 'Customers', 'Sales and pricing', 'Payments', 'Websites and MLS', 'AI', 'Connections', 'Products', 'System', 'HQ'];

    /** @var list<NavItem> */
    private array $items = [];

    public function add(NavItem $item): void
    {
        $this->items[] = $item;
    }

    /**
     * @return list<array{label: string, items: list<array{title: string, href: string, icon: string, mobile: bool}>}>
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

        usort($visible, fn (NavItem $a, NavItem $b): int => [$this->groupIndex($a->group), $a->order, $a->title] <=> [$this->groupIndex($b->group), $b->order, $b->title]);

        $groups = [];
        foreach ($visible as $item) {
            $groups[$item->group][] = ['title' => $item->title, 'href' => $item->href, 'icon' => $item->icon, 'mobile' => $item->mobile];
        }

        $out = [];
        foreach ($groups as $label => $items) {
            // HQ1: in the HQ console the people page is RightAlly's own team, not a brokerage's.
            $label = $user->kind === UserKind::HqStaff && $label === 'Brokerage' ? 'HQ team' : (string) $label;
            $out[] = ['label' => $label, 'items' => $items];
        }

        // Simple mode (SM1): a short menu of the pages used most, from the pages this person can open.
        // Otherwise the full menu, in Spanish for people who chose it (PW1).
        return SimpleMode::on($user) ? SimpleMode::menu($out, SimpleMode::locale($user)) : NavWords::translate($out, SimpleMode::locale($user));
    }

    private function groupIndex(string $group): int
    {
        $index = array_search($group, self::GROUPS, true);

        return $index === false ? count(self::GROUPS) : $index;
    }
}
