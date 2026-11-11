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
};

export type NavGroup = { label: string; items: ServerNavItem[] };

export type TenantInfo = { name: string; isHq: boolean };
