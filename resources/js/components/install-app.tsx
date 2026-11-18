import { usePage } from '@inertiajs/react';
import { Smartphone } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { shellWords } from '@/lib/shell-words';

type InstallEvent = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: string }>;
};

/** Registers the service worker once (production builds only). */
export function registerServiceWorker(): void {
    if (
        typeof window === 'undefined' ||
        !('serviceWorker' in navigator) ||
        !import.meta.env.PROD
    ) {
        return;
    }
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => undefined);
    });
}

function standalone(): boolean {
    return (
        typeof window !== 'undefined' &&
        (window.matchMedia?.('(display-mode: standalone)').matches ||
            (navigator as Navigator & { standalone?: boolean }).standalone ===
                true)
    );
}

/**
 * "Add to home screen" (PW1, specs/core/17): uses the browser's own install prompt where there is one (Chrome, Edge,
 * Android); on iPhone and iPad shows the two taps it takes. Hidden once RightAlly is opened from the home screen.
 */
export function InstallApp() {
    const { simple } = usePage().props;
    const w = shellWords(simple?.locale);
    const [prompt, setPrompt] = useState<InstallEvent | null>(null);
    const [help, setHelp] = useState(false);
    const [done, setDone] = useState(false);

    useEffect(() => {
        const onPrompt = (e: Event) => {
            e.preventDefault();
            setPrompt(e as InstallEvent);
        };
        const onInstalled = () => setDone(true);
        window.addEventListener('beforeinstallprompt', onPrompt);
        window.addEventListener('appinstalled', onInstalled);
        return () => {
            window.removeEventListener('beforeinstallprompt', onPrompt);
            window.removeEventListener('appinstalled', onInstalled);
        };
    }, []);

    if (standalone() || done) {
        return null;
    }
    const ios = /iphone|ipad|ipod/i.test(
        typeof navigator !== 'undefined' ? navigator.userAgent : '',
    );

    return (
        <>
            <Button
                variant="ghost"
                className="w-full justify-start gap-2"
                onClick={async () => {
                    if (prompt) {
                        await prompt.prompt();
                        const choice = await prompt.userChoice;
                        setPrompt(null);
                        if (choice.outcome === 'accepted') {
                            setDone(true);
                        }
                    } else {
                        setHelp(true);
                    }
                }}
            >
                <Smartphone aria-hidden="true" className="size-4" />
                {w.install}
            </Button>
            <Dialog open={help} onOpenChange={setHelp}>
                <DialogContent>
                    <DialogTitle>{w.installTitle}</DialogTitle>
                    <DialogDescription className="text-base">
                        {ios ? w.installIos : w.installOther}
                    </DialogDescription>
                    <Button
                        className="justify-self-start"
                        onClick={() => setHelp(false)}
                    >
                        {w.close}
                    </Button>
                </DialogContent>
            </Dialog>
        </>
    );
}
