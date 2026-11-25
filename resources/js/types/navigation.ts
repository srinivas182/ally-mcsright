import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
};

/** Menu sent by the server for the signed-in user (modules register items). */
export type ServerNavItem = {
    title: string;
    href: string;
    icon: string;
    mobile: boolean;
    /** MN3: a section inside a main menu (Accounting, Settings). */
    section?: string;
    /** HQ3: how many things are waiting (Today › To do). */
    badge?: number;
};

/** `key` is the menu's English name (guides and icons use it); `label` may be translated. */
export type NavGroup = { key?: string; label: string; items: ServerNavItem[] };

export type TenantInfo = {
    name: string;
    isHq: boolean;
    /** BR2: the brokerage's logo and sign-in photo, when it uses its own brand in the back office. */
    logo?: string | null;
    photo?: string | null;
    branded?: boolean;
};
