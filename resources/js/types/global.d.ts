import type { Auth } from '@/types/auth';
import type { NavGroup, TenantInfo } from '@/types/navigation';
import type { Reading } from '@/types/reading';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            tenant: TenantInfo | null;
            navigation: NavGroup[];
            simple: {
                mode: 'simple' | 'full' | null;
                locale: 'en' | 'es';
                words: Record<string, string>;
                locales: Record<string, string>;
            } | null;
            reading: Reading;
            paymentsTestMode: boolean;
            platformUrl: string;
            impersonation: {
                by: string;
                target: string;
                expires_at: number;
            } | null;
            security: {
                secondFactorMissing: boolean;
                secondFactorDaysLeft: number | null;
                passkeyPrompt: boolean;
            } | null;
            [key: string]: unknown;
        };
    }
}
