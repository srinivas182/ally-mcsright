import type { ReactNode } from 'react';

import { CreditConsentHost } from '@/components/credit-consent';
import { ImpersonationBanner } from '@/components/impersonation-banner';

/**
 * WB1: the website editor fills the whole window, like a dedicated site builder: no back-office menu around it, just
 * its own top bar, the editing panel and the page.
 */
export default function EditorLayout({ children }: { children: ReactNode }) {
    return (
        <div className="flex min-h-svh flex-col bg-muted/30 text-foreground">
            <ImpersonationBanner />
            {children}
            <CreditConsentHost />
        </div>
    );
}
