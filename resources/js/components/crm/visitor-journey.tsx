import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

export type JourneySummary = {
    summary: string;
    homes_viewed: number;
    searches: number;
    favourites: number;
    visits: number;
    last_seen: string | null;
};

type JourneyEvent = {
    at: string;
    type: string;
    text: string;
    url: string | null;
};
type Journey = {
    summary: string;
    summary_at: string | null;
    ai: boolean;
    stats: {
        homes_viewed: number;
        searches: number;
        favourites: number;
        visits: number;
    };
    events: JourneyEvent[];
};

const icon: Record<string, string> = {
    listing: '🏠',
    search: '🔎',
    favourite: '♥',
    unfavourite: '♡',
    compare: '⚖',
    mortgage: '$',
    valuation: '📈',
    signup: '👤',
    lead: '✉',
    page: '·',
};

/**
 * What a lead did on the brokerage's websites (specs/websites/07): the summary and counts inline, and the full
 * timeline (homes viewed, searches, saves, comparisons, payment estimates) on demand.
 */
export function VisitorJourney({
    leadUlid,
    journey,
}: {
    leadUlid: string;
    journey: JourneySummary;
}) {
    const [detail, setDetail] = useState<Journey | null>(null);
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);

    const load = async () => {
        setOpen(!open);
        if (detail !== null || busy) {
            return;
        }
        setBusy(true);
        try {
            const res = await fetch(`/leads/${leadUlid}/journey`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const body = (await res.json()) as { journey: Journey | null };
            setDetail(body.journey);
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="grid gap-2 rounded-md border border-dashed p-2 text-xs">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">Website journey</span>
                <Badge variant="secondary">
                    {journey.homes_viewed} homes viewed
                </Badge>
                {journey.searches > 0 && (
                    <Badge variant="outline">{journey.searches} searches</Badge>
                )}
                {journey.favourites > 0 && (
                    <Badge variant="outline">{journey.favourites} saved</Badge>
                )}
                <Badge variant="outline">
                    {journey.visits} {journey.visits === 1 ? 'visit' : 'visits'}
                </Badge>
                <Button
                    size="sm"
                    variant="ghost"
                    className="ml-auto h-7"
                    onClick={load}
                    aria-expanded={open}
                >
                    {open ? 'Hide' : 'See everything'}
                </Button>
            </div>
            {journey.summary !== '' && (
                <p className="text-sm whitespace-pre-line">{journey.summary}</p>
            )}
            {open && (
                <div className="grid gap-1">
                    {busy && <p className="text-muted-foreground">Loading…</p>}
                    {detail?.ai === false && detail.summary !== '' && (
                        <p className="text-muted-foreground">
                            Summary written from the visitor's activity (AI
                            summary unavailable).
                        </p>
                    )}
                    <ol className="grid max-h-72 gap-1 overflow-y-auto">
                        {detail?.events.map((e, i) => (
                            <li key={i} className="flex gap-2">
                                <span aria-hidden className="w-4 text-center">
                                    {icon[e.type] ?? '·'}
                                </span>
                                <span className="flex-1">
                                    {e.url ? (
                                        <a
                                            href={e.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="underline"
                                        >
                                            {e.text}
                                        </a>
                                    ) : (
                                        e.text
                                    )}
                                </span>
                                <time
                                    className="text-muted-foreground"
                                    dateTime={e.at}
                                >
                                    {new Date(e.at).toLocaleString(undefined, {
                                        month: 'short',
                                        day: 'numeric',
                                        hour: 'numeric',
                                        minute: '2-digit',
                                    })}
                                </time>
                            </li>
                        ))}
                    </ol>
                </div>
            )}
        </div>
    );
}
