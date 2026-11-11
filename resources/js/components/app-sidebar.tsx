import { Link, usePage } from '@inertiajs/react';

import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0]?.toUpperCase())
        .join('');
}

export function AppSidebar() {
    const { tenant, navigation } = usePage().props;
    const home = navigation[0]?.items[0]?.href ?? '/';
    const name = tenant?.name ?? 'RightAlly';

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={home} prefetch>
                                <span
                                    aria-hidden="true"
                                    className="flex size-8 items-center justify-center rounded-md bg-primary text-sm font-semibold text-primary-foreground"
                                >
                                    {initials(name)}
                                </span>
                                <span className="truncate font-semibold">
                                    {name}
                                </span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
