import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { ImpersonationBanner } from '@/components/impersonation-banner';
import { MobileNav } from '@/components/mobile-nav';
import { PaymentsTestBanner } from '@/components/payments-test-banner';
import { PasskeyPrompt } from '@/components/passkey-prompt';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="min-w-0 overflow-x-clip">
                <PaymentsTestBanner />
                <ImpersonationBanner />
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <div className="pb-20 md:pb-0">{children}</div>
            </AppContent>
            <MobileNav />
            <PasskeyPrompt />
        </AppShell>
    );
}
