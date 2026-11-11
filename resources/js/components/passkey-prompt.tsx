import { router, usePage } from '@inertiajs/react';
import { FingerprintIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

import PasskeyRegistration from '@/components/passkey-register';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * Offers a passkey right after sign-in, only on devices that can use one (Face ID, Touch ID,
 * Windows Hello, Android fingerprint). Prompting on capable devices is what drives adoption.
 */
export function PasskeyPrompt() {
    const { security } = usePage().props;
    const [open, setOpen] = useState(false);

    useEffect(() => {
        if (
            !security?.passkeyPrompt ||
            typeof window === 'undefined' ||
            !window.PublicKeyCredential
        ) {
            return;
        }
        void window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable?.().then(
            (ok) => setOpen(ok),
        );
    }, [security?.passkeyPrompt]);

    const dismiss = () => {
        setOpen(false);
        router.post(
            '/security/passkey-prompt/dismiss',
            {},
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <Dialog open={open} onOpenChange={(value) => !value && dismiss()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <FingerprintIcon
                            aria-hidden="true"
                            className="size-5"
                        />{' '}
                        Sign in faster next time
                    </DialogTitle>
                    <DialogDescription>
                        Use your face, fingerprint or device PIN instead of a
                        password. It is quicker and safer than codes, and your
                        biometrics never leave your device.
                    </DialogDescription>
                </DialogHeader>
                <PasskeyRegistration onSuccess={() => setOpen(false)} />
                <DialogFooter>
                    <Button variant="ghost" onClick={dismiss}>
                        Not now
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
