import type { Auth } from '@/types/auth';
import type { NavGroup, TenantInfo } from '@/types/navigation';

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
            paymentsTestMode: boolean;
            impersonation: {
                by: string;
                target: string;
                expires_at: number;
            } | null;
            security: {
                secondFactorMissing: boolean;
                passkeyPrompt: boolean;
            } | null;
            [key: string]: unknown;
        };
    }
}
