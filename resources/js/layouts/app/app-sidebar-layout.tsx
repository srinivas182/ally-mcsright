import { AppContent } from '@/components/app-content';
import { YourAiAssistant } from '@/components/assistant/your-ai-assistant';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { CreditConsentHost } from '@/components/credit-consent';
import { CreditsBanner } from '@/components/credits-banner';
import { HelpMe } from '@/components/help-me';
import { SecondFactorReminder } from '@/components/second-factor-reminder';
import { ImpersonationBanner } from '@/components/impersonation-banner';
import { MobileNav } from '@/components/mobile-nav';
import { PaymentsTestBanner } from '@/components/payments-test-banner';
import { PasskeyPrompt } from '@/components/passkey-prompt';
import {
    SkipLink,
    useEasierReading,
} from '@/components/reading/easier-reading';
import { ChooseView, TalkButton } from '@/components/simple/view-switch';
import type { AppLayoutProps } from '@/types';
import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    const { simple: simpleProps, reading } = usePage().props;
    const simple = simpleProps?.mode === 'simple';
    useEffect(() => {
        document.documentElement.classList.toggle('simple', simple);
    }, [simple]);
    useEasierReading(reading);

    return (
        <AppShell variant="sidebar">
            <SkipLink />
            <AppSidebar />
            <AppContent
                variant="sidebar"
                id="main-content"
                tabIndex={-1}
                className="min-w-0 overflow-x-clip focus:outline-none"
            >
                <PaymentsTestBanner />
                <ImpersonationBanner />
                <CreditsBanner />
                <SecondFactorReminder />
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <ChooseView />
                <div className="pb-20 md:pb-0">{children}</div>
            </AppContent>
            <MobileNav />
            <PasskeyPrompt />
            <CreditConsentHost />
            <TalkButton />
            <HelpMe />
            <YourAiAssistant />
        </AppShell>
    );
}
