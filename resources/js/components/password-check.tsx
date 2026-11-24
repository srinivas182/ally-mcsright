import { router } from '@inertiajs/react';
import type { Method, RequestPayload } from '@inertiajs/core';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { postJson } from '@/lib/http';

type Pending = { url: string; method: Method; data: RequestPayload };

/**
 * UX1: when a change needs a fresh password, ask for it here, over the page, instead of sending the person to another
 * page (which threw away everything they had typed). Once confirmed, the same change is sent again with their entries.
 */
export function PasswordCheckHost() {
    const last = useRef<Pending | null>(null);
    const [pending, setPending] = useState<Pending | null>(null);
    const [password, setPassword] = useState('');
    const [problem, setProblem] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        const offBefore = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (visit.method !== 'get') {
                last.current = {
                    url: visit.url.href,
                    method: visit.method,
                    data: visit.data,
                };
            }
        });
        const offError = router.on('error', (event) => {
            if ('confirm_password' in event.detail.errors && last.current) {
                setPending(last.current);
                setPassword('');
                setProblem(null);
            }
        });

        return () => {
            offBefore();
            offError();
        };
    }, []);

    const confirm = async () => {
        if (!pending) {
            return;
        }
        setBusy(true);
        setProblem(null);
        try {
            await postJson('/user/confirm-password', { password });
            const again = pending;
            setPending(null);
            router.visit(again.url, {
                method: again.method,
                data: again.data,
                preserveScroll: true,
                preserveState: true,
            });
        } catch {
            setProblem('That password is not right. Please try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog
            open={pending !== null}
            onOpenChange={(open) => !open && setPending(null)}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Confirm your password</DialogTitle>
                    <DialogDescription>
                        For your security, please type your password to save
                        this change. Everything you entered stays as it is.
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="grid gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        void confirm();
                    }}
                >
                    <label className="grid gap-1 text-sm font-medium">
                        Password
                        <Input
                            type="password"
                            autoComplete="current-password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            aria-invalid={problem !== null}
                        />
                    </label>
                    {problem && (
                        <p className="text-sm text-destructive" role="alert">
                            {problem}
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setPending(null)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={busy || !password}>
                            Confirm and save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
