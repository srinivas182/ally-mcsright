import { AppContent } from '@/components/app-content';
import { YourAiAssistant } from '@/components/assistant/your-ai-assistant';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { CreditConsentHost } from '@/components/credit-consent';
import { CreditsBanner } from '@/components/credits-banner';
import { ImpersonationBanner } from '@/components/impersonation-banner';
import { MobileNav } from '@/components/mobile-nav';
import { PaymentsTestBanner } from '@/components/payments-test-banner';
import { PasskeyPrompt } from '@/components/passkey-prompt';
import { ChooseView, TalkButton } from '@/components/simple/view-switch';
import type { AppLayoutProps } from '@/types';
import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    const simple = usePage().props.simple?.mode === 'simple';
    useEffect(() => {
        document.documentElement.classList.toggle('simple', simple);
    }, [simple]);

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="min-w-0 overflow-x-clip">
                <PaymentsTestBanner />
                <ImpersonationBanner />
                <CreditsBanner />
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <ChooseView />
                <div className="pb-20 md:pb-0">{children}</div>
            </AppContent>
            <MobileNav />
            <PasskeyPrompt />
            <CreditConsentHost />
            <TalkButton />
            <YourAiAssistant />
        </AppShell>
    );
}
