import { usePage } from '@inertiajs/react';

import type { NavGroup, ServerNavItem } from '@/types';

/** MN3: a main menu's icon, by its English name. */
export const MENU_ICONS: Record<string, string> = {
    Dashboard: 'layout-grid',
    CRM: 'users',
    Listings: 'house',
    Transactions: 'briefcase',
    Marketing: 'megaphone',
    'Website & IDX': 'layout-grid',
    Agents: 'id-card',
    Commissions: 'coins',
    'My commissions': 'wallet',
    Accounting: 'landmark',
    Reports: 'chart-column',
    Training: 'book-open',
    Settings: 'toggle-right',
};

function path(url: string): string {
    try {
        return new URL(url, 'http://x').pathname;
    } catch {
        return url;
    }
}

/**
 * MN3: the main menu (and its tab) for the page being shown: the tab whose address is the longest match for the
 * page's address, so a transaction's own page still shows the Transactions tabs.
 */
export function useCurrentMenu(): {
    group: NavGroup | null;
    item: ServerNavItem | null;
} {
    const { navigation, url } = usePage().props as unknown as {
        navigation: NavGroup[];
        url?: string;
    };
    const here = path(usePage().url);
    let best: { group: NavGroup; item: ServerNavItem } | null = null;
    for (const group of navigation ?? []) {
        for (const item of group.items) {
            const p = path(item.href);
            const hit = here === p || (p !== '/' && here.startsWith(`${p}/`));
            if (
                hit &&
                (best === null || p.length > path(best.item.href).length)
            ) {
                best = { group, item };
            }
        }
    }
    void url;

    return { group: best?.group ?? null, item: best?.item ?? null };
}
