import { createInertiaApp } from '@inertiajs/react';
import { registerServiceWorker } from '@/components/install-app';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { watchBrowserErrors } from '@/lib/watchtower';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// PW1: the installable app (only built app files and the offline page are cached; never pages or data).
registerServiceWorker();

// OB1: crashes in the browser reach Watchtower with the other problems.
watchBrowserErrors();

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
            case name.startsWith('dev/'):
            case name.startsWith('public/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
