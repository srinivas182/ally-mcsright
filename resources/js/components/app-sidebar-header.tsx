import { Breadcrumbs } from '@/components/breadcrumbs';
import { CommandBar } from '@/components/command-bar';
import { NotificationBell } from '@/components/notification-bell';
import { TextSizeButton } from '@/components/reading/easier-reading';
import { ShowMe } from '@/components/simple/show-me';
import { ViewSwitch } from '@/components/simple/view-switch';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return (
        <header
            data-slot="app-topbar"
            className="flex h-16 shrink-0 items-center gap-2 border-b border-[rgb(6_22_63/0.07)] px-4 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
        >
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="-ml-1 hidden md:inline-flex" />
                <div className="hidden min-w-0 md:block">
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
            </div>
            <div className="ml-auto flex flex-1 items-center justify-end gap-2 md:flex-none">
                <ShowMe />
                <TextSizeButton />
                <ViewSwitch />
                <CommandBar />
                <NotificationBell />
            </div>
        </header>
    );
}
