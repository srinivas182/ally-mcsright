import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useState } from 'react';

import { NavIcon } from '@/components/nav-icon';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';

const OPEN_KEY = 'hq-menu-open';
const TENANT_OPEN_KEY = 'menu-open';

/** HQ1: an icon for each HQ heading, so the headings read as main menu items. */
const GROUP_ICONS: Record<string, string> = {
    Overview: 'layout-grid',
    'HQ team': 'users',
    Customers: 'building-2',
    'Sales and pricing': 'badge-dollar-sign',
    Payments: 'credit-card',
    'Websites and MLS': 'house',
    AI: 'sparkles',
    Connections: 'plug',
    Products: 'blocks',
    System: 'shield',
    // UX1: a brokerage's main menus.
    Home: 'house',
    'Leads and clients': 'users',
    Listings: 'building-2',
    Marketing: 'megaphone',
    Deals: 'handshake',
    Money: 'wallet',
    Reports: 'chart-column',
    Team: 'id-card',
    Finance: 'landmark',
    Compensation: 'coins',
    Brokerage: 'briefcase',
    People: 'users',
    Settings: 'toggle-right',
    // The same menus in Spanish.
    Inicio: 'house',
    'Prospectos y clientes': 'users',
    Propiedades: 'building-2',
    Operaciones: 'handshake',
    Mercadeo: 'megaphone',
    Dinero: 'wallet',
    Informes: 'chart-column',
    Equipo: 'id-card',
    Finanzas: 'landmark',
    Compensación: 'coins',
};

function readOpen(key: string): string[] {
    try {
        const raw = window.localStorage.getItem(key);
        const list: unknown = raw ? JSON.parse(raw) : [];
        return Array.isArray(list)
            ? list.filter((v): v is string => typeof v === 'string')
            : [];
    } catch {
        return [];
    }
}

function saveOpen(key: string, list: string[]): void {
    try {
        window.localStorage.setItem(key, JSON.stringify(list));
    } catch {
        // Private windows can refuse storage; the menu still works.
    }
}

/**
 * Module groups from the server, filtered to what this user may use. In the HQ console (HQ1) each group opens and
 * closes, so the long menu stays short: the group of the page you are on is always open, and the groups you opened
 * stay open on this browser.
 */
export function NavMain() {
    const { navigation, tenant } = usePage().props;
    const { isCurrentUrl } = useCurrentUrl();
    const { state, isMobile } = useSidebar();
    // UX1: brokerages get the same opening main menus as HQ (the short simple-view menu stays flat).
    const simpleMenu = usePage().props.simple?.mode === 'simple';
    const folding = !simpleMenu && (state === 'expanded' || isMobile);
    const key = tenant?.isHq ? OPEN_KEY : TENANT_OPEN_KEY;
    const [open, setOpen] = useState<string[]>(() =>
        typeof window === 'undefined' ? [] : readOpen(key),
    );
    const toggle = (label: string) => {
        const next = open.includes(label)
            ? open.filter((l) => l !== label)
            : [...open, label];
        setOpen(next);
        saveOpen(key, next);
    };

    if (folding) {
        return (
            <SidebarGroup className="px-2 py-1">
                <SidebarMenu className="gap-1">
                    {navigation.map((group) => {
                        const here = group.items.some((item) =>
                            isCurrentUrl(item.href),
                        );
                        const shown =
                            here ||
                            open.includes(group.label) ||
                            navigation.length === 1;
                        const id = `nav-${group.label.replace(/\W+/g, '-').toLowerCase()}`;

                        return (
                            <SidebarMenuItem key={group.label}>
                                <SidebarMenuButton
                                    type="button"
                                    aria-expanded={shown}
                                    aria-controls={id}
                                    onClick={() => !here && toggle(group.label)}
                                    className="font-medium"
                                >
                                    <NavIcon
                                        name={
                                            GROUP_ICONS[group.label] ??
                                            'layout-grid'
                                        }
                                    />
                                    <span>{group.label}</span>
                                    <ChevronRight
                                        aria-hidden="true"
                                        className={`ml-auto size-4 opacity-70 transition-transform ${shown ? 'rotate-90' : ''}`}
                                    />
                                </SidebarMenuButton>
                                {shown && (
                                    <ul
                                        id={id}
                                        className="mt-1 ml-[1.1rem] grid gap-0.5 border-l border-sidebar-border/60 pl-2"
                                    >
                                        {group.items.map((item) => (
                                            <SidebarMenuItem key={item.href}>
                                                <SidebarMenuButton
                                                    asChild
                                                    isActive={isCurrentUrl(
                                                        item.href,
                                                    )}
                                                    tooltip={{
                                                        children: item.title,
                                                    }}
                                                >
                                                    <Link
                                                        href={item.href}
                                                        prefetch
                                                    >
                                                        <NavIcon
                                                            name={item.icon}
                                                        />
                                                        <span>
                                                            {item.title}
                                                        </span>
                                                    </Link>
                                                </SidebarMenuButton>
                                            </SidebarMenuItem>
                                        ))}
                                    </ul>
                                )}
                            </SidebarMenuItem>
                        );
                    })}
                </SidebarMenu>
            </SidebarGroup>
        );
    }

    return (
        <>
            {navigation.map((group) => (
                <SidebarGroup key={group.label} className="px-2 py-0">
                    <SidebarGroupLabel>{group.label}</SidebarGroupLabel>
                    <SidebarMenu>
                        {group.items.map((item) => (
                            <SidebarMenuItem key={item.href}>
                                <SidebarMenuButton
                                    asChild
                                    isActive={isCurrentUrl(item.href)}
                                    tooltip={{ children: item.title }}
                                >
                                    <Link href={item.href} prefetch>
                                        <NavIcon name={item.icon} />
                                        <span>{item.title}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        ))}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}
