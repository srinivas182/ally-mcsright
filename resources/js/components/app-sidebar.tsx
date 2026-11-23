import { Link, usePage } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';
import { Wordmark } from '@/components/app-logo';
import { CreditMeter } from '@/components/credit-meter';
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
                                {tenant?.isHq ? (
                                    <>
                                        <span className="flex size-8 shrink-0 items-center justify-center">
                                            <AppLogoIcon className="!size-8 drop-shadow-sm" />
                                        </span>
                                        <span className="flex min-w-0 items-center gap-2 text-white">
                                            <Wordmark className="h-[0.95rem] w-auto" />
                                            <span className="rounded-md bg-white/12 px-1.5 py-0.5 text-[0.65rem] font-semibold tracking-wide text-[#C3CDEA] ring-1 ring-white/15">
                                                HQ
                                            </span>
                                        </span>
                                    </>
                                ) : (
                                    <>
                                        {tenant?.logo ? (
                                            <img
                                                src={tenant.logo}
                                                alt=""
                                                className="size-8 shrink-0 rounded-lg bg-white object-contain p-0.5"
                                            />
                                        ) : (
                                            <span
                                                aria-hidden="true"
                                                className={`flex size-8 shrink-0 items-center justify-center rounded-lg text-sm font-semibold ${tenant?.branded ? 'bg-sidebar-primary text-sidebar-primary-foreground' : 'bg-gradient-to-b from-[#2A6BFF] to-[#0B3FB8] text-white shadow-[0_1px_0_rgb(255_255_255/0.2)_inset,0_4px_10px_-4px_rgb(21_93_252/0.8)]'}`}
                                            >
                                                {initials(name)}
                                            </span>
                                        )}
                                        <span className="grid min-w-0 leading-tight">
                                            <span className="truncate font-semibold text-white">
                                                {name}
                                            </span>
                                            <span className="truncate text-xs text-sidebar-foreground/80">
                                                on RightAlly
                                            </span>
                                        </span>
                                    </>
                                )}
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain />
            </SidebarContent>

            <SidebarFooter>
                <CreditMeter />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
