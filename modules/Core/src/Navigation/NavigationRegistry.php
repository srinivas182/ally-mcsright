<?php

declare(strict_types=1);

namespace Modules\Core\Navigation;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Modules register their menu items here (an extension point: plugins can add items without
 * touching the core). Each user receives only the items their kind and permissions allow; the
 * same list drives the sidebar, the phone bottom bar and the command bar.
 */
final class NavigationRegistry
{
    /** Group order in the sidebar. */
    private const GROUPS = ['Overview', 'Grow', 'Run', 'Finance', 'Protect', 'Know', 'Brokerage', 'HQ'];

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

        $visible = array_filter($this->items, fn (NavItem $item): bool => ($item->kinds === [] || in_array($user->kind, $item->kinds, true))
            && ($item->ability === null || Gate::forUser($user)->allows($item->ability)));

        usort($visible, fn (NavItem $a, NavItem $b): int => [$this->groupIndex($a->group), $a->order, $a->title] <=> [$this->groupIndex($b->group), $b->order, $b->title]);

        $groups = [];
        foreach ($visible as $item) {
            $groups[$item->group][] = ['title' => $item->title, 'href' => $item->href, 'icon' => $item->icon, 'mobile' => $item->mobile];
        }

        $out = [];
        foreach ($groups as $label => $items) {
            $out[] = ['label' => (string) $label, 'items' => $items];
        }

        return $out;
    }

    private function groupIndex(string $group): int
    {
        $index = array_search($group, self::GROUPS, true);

        return $index === false ? count(self::GROUPS) : $index;
    }
}
