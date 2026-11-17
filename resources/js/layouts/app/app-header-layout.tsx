import { AppContent } from '@/components/app-content';
import { AppHeader } from '@/components/app-header';
import { AppShell } from '@/components/app-shell';
import {
    SkipLink,
    useEasierReading,
} from '@/components/reading/easier-reading';
import { usePage } from '@inertiajs/react';
import type { AppLayoutProps } from '@/types';

export default function AppHeaderLayout({
    children,
    breadcrumbs,
}: AppLayoutProps) {
    useEasierReading(usePage().props.reading);

    return (
        <AppShell variant="header">
            <SkipLink />
            <AppHeader breadcrumbs={breadcrumbs} />
            <AppContent variant="header" id="main-content" tabIndex={-1}>
                {children}
            </AppContent>
        </AppShell>
    );
}
