import { BellIcon } from 'lucide-react';

import { Breadcrumbs } from '@/components/breadcrumbs';
import { CommandBar } from '@/components/command-bar';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return (
        <header className="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-4 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="-ml-1 hidden md:inline-flex" />
                <div className="hidden min-w-0 md:block">
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
            </div>
            <div className="ml-auto flex flex-1 items-center justify-end gap-2 md:flex-none">
                <CommandBar />
                {/* Notifications arrive with the notification centre (Platform Core). */}
                <Button variant="ghost" size="icon" aria-label="Notifications">
                    <BellIcon />
                </Button>
            </div>
        </header>
    );
}
