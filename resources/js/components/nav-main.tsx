import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useState } from 'react';

import { MENU_ICONS, useCurrentMenu } from '@/components/menu/current-menu';
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
import type { NavGroup } from '@/types';

const OPEN_KEY = 'hq-menu-open';

/** MN3: one main menu in a brokerage's sidebar: a single link to the menu's first page. */
function MainMenuLink({ group }: { group: NavGroup }) {
    const current = useCurrentMenu();
    const key = group.key ?? group.label;
    const active =
        current.group !== null &&
        (current.group.key ?? current.group.label) === key;

    return (
        <SidebarMenuItem>
            <SidebarMenuButton
                asChild
                isActive={active}
                tooltip={{ children: group.label }}
                className="h-10 text-[15px]"
            >
                <Link href={group.items[0].href} prefetch>
                    <NavIcon name={MENU_ICONS[key] ?? group.items[0].icon} />
                    <span>{group.label}</span>
                </Link>
            </SidebarMenuButton>
        </SidebarMenuItem>
    );
}

/** MN3: Settings, pinned to the bottom of a brokerage's sidebar, just above the AI credits. */
export function NavSettings() {
    const { navigation, tenant, simple } = usePage().props;
    const settings = navigation.find(
        (g) => (g.key ?? g.label) === 'Settings' && g.items.length > 0,
    );
    if (tenant?.isHq || simple?.mode === 'simple' || !settings) {
        return null;
    }

    return (
        <SidebarMenu>
            <MainMenuLink group={settings} />
        </SidebarMenu>
    );
}
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
    // MN2
    Transactions: 'briefcase',
    Website: 'layout-grid',
    'Brokerage setup': 'building-2',
    Compliance: 'shield-check',
    'Getting started': 'rocket',
    'My account': 'id-card',
    Transacciones: 'briefcase',
    'Sitio web': 'layout-grid',
    'Configuración de la correduría': 'building-2',
    Cumplimiento: 'shield-check',
    'Primeros pasos': 'rocket',
    'Mi cuenta': 'id-card',
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

    // MN3: a brokerage's sidebar is its main menus only; each opens a page whose tabs are that menu's pages.
    // Settings sits at the bottom, above the AI credits (NavSettings).
    const flat = !tenant?.isHq && !simpleMenu;
    if (flat) {
        return (
            <SidebarGroup className="px-2 py-1">
                <SidebarMenu className="gap-1">
                    {navigation
                        .filter(
                            (g) =>
                                g.items.length > 0 &&
                                (g.key ?? g.label) !== 'Settings',
                        )
                        .map((group) => (
                            <MainMenuLink
                                key={group.key ?? group.label}
                                group={group}
                            />
                        ))}
                </SidebarMenu>
            </SidebarGroup>
        );
    }

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
