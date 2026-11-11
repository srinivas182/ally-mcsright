import { router } from '@inertiajs/react';
import {
    CopyIcon,
    RefreshCwIcon,
    SparklesIcon,
    ThumbsDownIcon,
    ThumbsUpIcon,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

export type BriefContent = {
    who: string;
    wants: string;
    status: string;
    unknowns: string[];
    next_step: string;
    next_step_reason: string;
    draft_channel: 'text' | 'email' | 'call' | 'note';
    draft: string;
    sources: string[];
    notes: string[];
};

export type Brief = {
    content: BriefContent;
    stale: boolean;
    generated_at?: string;
} | null;

type Props = {
    contactUlid: string;
    brief: Brief;
    phone: string | null;
    email: string | null;
    compact?: boolean;
};

/** The AI brief: who, wants, status, next step and a draft in an allowed channel. */
export function AiBrief({
    contactUlid,
    brief,
    phone,
    email,
    compact = false,
}: Props) {
    const [working, setWorking] = useState(false);
    const [voted, setVoted] = useState(false);
    const autoRefreshed = useRef(false);
    const refresh = () => {
        setWorking(true);
        router.post(
            `/contacts/${contactUlid}/brief`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setWorking(false),
            },
        );
    };

    // Out of date (something new happened): refresh once automatically.
    useEffect(() => {
        if (brief?.stale && !autoRefreshed.current) {
            autoRefreshed.current = true;
            refresh();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [brief?.stale]);

    if (!brief) {
        return (
            <section
                aria-label="AI brief"
                className="grid gap-2 rounded-lg border border-dashed p-4"
            >
                <p className="flex items-center gap-2 font-medium">
                    <SparklesIcon aria-hidden="true" className="size-4" /> AI
                    brief
                </p>
                <p className="text-sm text-muted-foreground">
                    A five-second summary of who they are and what to do next.
                </p>
                <Button
                    size="sm"
                    className="justify-self-start"
                    onClick={refresh}
                    disabled={working}
                >
                    {working ? 'Writing the brief…' : 'Create brief'}
                </Button>
            </section>
        );
    }

    const c = brief.content;
    const href =
        c.draft_channel === 'text' && phone
            ? `sms:${phone}?&body=${encodeURIComponent(c.draft)}`
            : c.draft_channel === 'email' && email
              ? `mailto:${email}?body=${encodeURIComponent(c.draft)}`
              : c.draft_channel === 'call' && phone
                ? `tel:${phone}`
                : null;

    return (
        <section
            aria-label="AI brief"
            className="grid gap-2 rounded-lg border bg-muted/20 p-4 text-sm"
        >
            <div className="flex items-center justify-between gap-2">
                <p className="flex items-center gap-2 font-medium">
                    <SparklesIcon aria-hidden="true" className="size-4" /> AI
                    brief
                    {(brief.stale || working) && (
                        <span className="text-xs font-normal text-muted-foreground">
                            Updating…
                        </span>
                    )}
                </p>
                <Button
                    size="icon"
                    variant="ghost"
                    aria-label="Refresh brief"
                    onClick={refresh}
                    disabled={working}
                >
                    <RefreshCwIcon className="size-4" />
                </Button>
            </div>
            {c.who && (
                <p>
                    <span className="font-medium">Who: </span>
                    {c.who}
                </p>
            )}
            {c.wants && (
                <p>
                    <span className="font-medium">Wants: </span>
                    {c.wants}
                </p>
            )}
            {!compact && c.status && (
                <p>
                    <span className="font-medium">Where things stand: </span>
                    {c.status}
                </p>
            )}
            {c.unknowns.length > 0 && (
                <p className="text-muted-foreground">
                    Not known yet: {c.unknowns.join('; ')}
                </p>
            )}
            <p>
                <span className="font-medium">Next step: </span>
                {c.next_step}{' '}
                <span className="text-muted-foreground">
                    — {c.next_step_reason}
                </span>
            </p>
            {c.draft && (
                <div className="grid gap-2 rounded-md border bg-background p-3">
                    <Badge
                        variant="secondary"
                        className="justify-self-start capitalize"
                    >
                        {c.draft_channel === 'call'
                            ? 'Call opener'
                            : `Draft ${c.draft_channel}`}
                    </Badge>
                    <p className="whitespace-pre-wrap">{c.draft}</p>
                    <div className="flex flex-wrap gap-2">
                        {href && (
                            <Button size="sm" asChild>
                                <a href={href}>
                                    {c.draft_channel === 'call'
                                        ? 'Call'
                                        : c.draft_channel === 'text'
                                          ? 'Open in Messages'
                                          : 'Open in email'}
                                </a>
                            </Button>
                        )}
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                void navigator.clipboard?.writeText(c.draft)
                            }
                        >
                            <CopyIcon aria-hidden="true" /> Copy
                        </Button>
                    </div>
                </div>
            )}
            {c.notes.map((n) => (
                <p
                    key={n}
                    className="text-xs text-amber-700 dark:text-amber-300"
                >
                    {n}
                </p>
            ))}
            <div className="flex items-center gap-1 text-xs text-muted-foreground">
                <span>
                    Based on {c.sources.length} record
                    {c.sources.length === 1 ? '' : 's'}. Was this helpful?
                </span>
                {!voted &&
                    ([true, false] as const).map((helpful) => (
                        <Button
                            key={String(helpful)}
                            size="icon"
                            variant="ghost"
                            aria-label={helpful ? 'Helpful' : 'Not helpful'}
                            onClick={() => {
                                setVoted(true);
                                router.post(
                                    `/contacts/${contactUlid}/brief/feedback`,
                                    { helpful },
                                    {
                                        preserveScroll: true,
                                        preserveState: true,
                                    },
                                );
                            }}
                        >
                            {helpful ? (
                                <ThumbsUpIcon className="size-3.5" />
                            ) : (
                                <ThumbsDownIcon className="size-3.5" />
                            )}
                        </Button>
                    ))}
            </div>
        </section>
    );
}
