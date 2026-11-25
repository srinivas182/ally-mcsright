import { Lightbulb, X } from 'lucide-react';
import { useEffect, useState } from 'react';

import type { PageAi } from '@/components/menu/page-hints';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

const TIPS_KEY = 'page-tips-hidden';

function readHidden(): string[] {
    try {
        const raw = window.localStorage.getItem(TIPS_KEY);
        const list: unknown = raw ? JSON.parse(raw) : [];
        return Array.isArray(list)
            ? list.filter((v): v is string => typeof v === 'string')
            : [];
    } catch {
        return [];
    }
}

/**
 * MN3: the one small "AI" marker on a page. Clicking it explains what the AI does for you, an example to say, how
 * to get the most from it, and three ways on: try it with the assistant, watch the lesson, or book a call.
 */
export function AiMarker({
    ai,
    onWatch,
    onCall,
    hq = false,
}: {
    ai: PageAi;
    onWatch: () => void;
    onCall: () => void;
    /** HQ4: on HQ pages the marker only explains; there is no assistant to try it with, and no call to book. */
    hq?: boolean;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger
                aria-label={`What ${ai.name} does on this page`}
                className="my-2 inline-flex shrink-0 items-center rounded-md border border-primary/30 bg-primary/5 px-2 py-1 text-xs font-bold tracking-wide text-primary hover:bg-primary/10"
            >
                AI
            </PopoverTrigger>
            <PopoverContent
                align="end"
                className="grid w-[min(24rem,calc(100vw-2rem))] gap-3 text-base leading-relaxed"
            >
                <p className="text-lg font-semibold">{ai.name}</p>
                <div className="grid gap-1">
                    <p className="text-sm font-semibold text-muted-foreground">
                        What it does for you
                    </p>
                    <p>{ai.does}</p>
                </div>
                {ai.example && (
                    <p className="rounded-md border bg-muted/40 p-2 text-sm">
                        <span className="font-semibold">Try saying: </span>
                        <i>{ai.example}</i>
                    </p>
                )}
                <div className="grid gap-1">
                    <p className="text-sm font-semibold text-muted-foreground">
                        Get the most from it
                    </p>
                    <p>{ai.most}</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {!hq && (
                        <Button
                            size="sm"
                            onClick={() => {
                                setOpen(false);
                                window.dispatchEvent(
                                    new Event('assistant:open'),
                                );
                            }}
                        >
                            Try it
                        </Button>
                    )}
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => {
                            setOpen(false);
                            onWatch();
                        }}
                    >
                        Watch how
                    </Button>
                    {!hq && (
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => {
                                setOpen(false);
                                onCall();
                            }}
                        >
                            Book a call
                        </Button>
                    )}
                </div>
            </PopoverContent>
        </Popover>
    );
}

/** MN3: one gentle tip per page, under the tabs; "Got it" hides it on this browser. */
export function PageTip({ id, text }: { id: string; text: string }) {
    const [hidden, setHidden] = useState(true);
    useEffect(() => setHidden(readHidden().includes(id)), [id]);

    if (hidden) {
        return null;
    }

    return (
        <div
            role="note"
            className="mb-2 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-[15px] text-amber-950 dark:bg-amber-950/40 dark:text-amber-100"
        >
            <Lightbulb className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <p className="flex-1">
                <span className="font-semibold">Tip: </span>
                {text}
            </p>
            <button
                type="button"
                className="inline-flex items-center gap-1 text-sm font-medium underline-offset-2 hover:underline"
                onClick={() => {
                    setHidden(true);
                    try {
                        window.localStorage.setItem(
                            TIPS_KEY,
                            JSON.stringify([...readHidden(), id].slice(-200)),
                        );
                    } catch {
                        // Private windows can refuse storage; the tip just comes back next time.
                    }
                }}
            >
                Got it <X className="size-3.5" aria-hidden="true" />
            </button>
        </div>
    );
}
