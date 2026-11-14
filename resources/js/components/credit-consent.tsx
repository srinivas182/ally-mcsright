import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { getJson } from '@/lib/http';

export type CreditQuote = {
    action: string;
    label: string;
    credits: number;
    available: number;
    payer: 'you' | 'brokerage';
    enough: boolean;
};

type Pending = { quote: CreditQuote; resolve: (ok: boolean) => void };

let show: ((pending: Pending) => void) | null = null;

/** Small actions (this many credits or fewer) can skip the question for the rest of the day, if the person says so. */
export const SMALL_ACTION = 2;
const SKIP_KEY = 'rightally.credits.skip-small';

function today(): string {
    return new Date().toISOString().slice(0, 10);
}

function smallPreapproved(): boolean {
    try {
        return window.localStorage.getItem(SKIP_KEY) === today();
    } catch {
        return false;
    }
}

function preapproveSmall(on: boolean): void {
    try {
        if (on) {
            window.localStorage.setItem(SKIP_KEY, today());
        } else {
            window.localStorage.removeItem(SKIP_KEY);
        }
    } catch {
        // Storage blocked: the question simply keeps being asked.
    }
}

/**
 * Asks before an AI action spends credits: "This will use 20 credits (you have 340). Continue?"
 * Resolves true when the person agrees. Free actions (0 credits) go ahead without asking.
 * If the price can't be checked, it still asks, without numbers.
 */
export async function confirmCredits(
    action: string,
    options: { onlyIfPreapproved?: boolean } = {},
): Promise<boolean> {
    let quote: CreditQuote;
    try {
        quote = await getJson<CreditQuote>(
            `/credits/quote?action=${encodeURIComponent(action)}`,
        );
    } catch {
        quote = {
            action,
            label: 'This AI action',
            credits: -1,
            available: -1,
            payer: 'brokerage',
            enough: true,
        };
    }
    if (quote.credits === 0) {
        return true;
    }
    const small =
        quote.credits > 0 && quote.credits <= SMALL_ACTION && quote.enough;
    if (small && smallPreapproved()) {
        return true;
    }
    if (options.onlyIfPreapproved) {
        return false;
    }
    if (!show) {
        return window.confirm(
            `This will use ${quote.credits} RightAlly credits. Continue?`,
        );
    }
    const open = show;
    return new Promise<boolean>((resolve) => open({ quote, resolve }));
}

/** Mounted once in the app layout; shows the consent dialog that confirmCredits() asks for. */
export function CreditConsentHost() {
    const [pending, setPending] = useState<Pending | null>(null);
    const [skipSmall, setSkipSmall] = useState(false);
    useEffect(() => {
        show = setPending;
        return () => {
            show = null;
        };
    }, []);

    const answer = (ok: boolean) => {
        if (ok && skipSmall) {
            preapproveSmall(true);
        }
        setSkipSmall(false);
        pending?.resolve(ok);
        setPending(null);
    };
    const quote = pending?.quote;
    const known = quote !== undefined && quote.credits > 0;

    return (
        <Dialog
            open={pending !== null}
            onOpenChange={(open) => !open && answer(false)}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Use RightAlly credits?</DialogTitle>
                    <DialogDescription>
                        {quote?.label ?? 'This AI action'}
                    </DialogDescription>
                </DialogHeader>
                {quote && known && quote.enough && (
                    <p className="text-sm">
                        This will use <strong>{quote.credits}</strong>{' '}
                        {quote.credits === 1 ? 'credit' : 'credits'} (
                        {quote.payer === 'you' ? 'you have' : 'available'}{' '}
                        <strong>{quote.available.toLocaleString()}</strong>
                        {quote.payer === 'brokerage'
                            ? ', from your brokerage'
                            : ''}
                        ). Continue?
                    </p>
                )}
                {quote && known && !quote.enough && (
                    <p className="text-sm text-destructive">
                        This needs {quote.credits} credits and you have{' '}
                        {quote.available.toLocaleString()}.{' '}
                        <a href="/settings/credits" className="underline">
                            Get more credits
                        </a>
                        .
                    </p>
                )}
                {quote && !known && (
                    <p className="text-sm">
                        This uses RightAlly credits. Continue?
                    </p>
                )}
                {quote &&
                    known &&
                    quote.enough &&
                    quote.credits <= SMALL_ACTION && (
                        <label className="flex items-center gap-2 text-sm text-muted-foreground">
                            <input
                                type="checkbox"
                                checked={skipSmall}
                                onChange={(e) => setSkipSmall(e.target.checked)}
                            />
                            Don't ask again today for actions of {SMALL_ACTION}{' '}
                            credits or less
                        </label>
                    )}
                <DialogFooter>
                    <Button variant="outline" onClick={() => answer(false)}>
                        Cancel
                    </Button>
                    <Button
                        onClick={() => answer(true)}
                        disabled={quote !== undefined && known && !quote.enough}
                        autoFocus
                    >
                        Continue
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
