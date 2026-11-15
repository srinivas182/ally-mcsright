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
    brief: Brief | null;
    chat: string;
};
type Home = {
    ulid: string;
    label: string;
    price: number | null;
    url: string | null;
};
type Brief = {
    ai: boolean;
    summary: string;
    budget: string;
    timeline: string;
    financing: string;
    must_haves: string[];
    concerns: string[];
    best_time: string;
    opening_line: string;
    checklist: string[];
    top_homes: (Home & { views: number })[];
    send_next: Home[];
};

const money = (v: number | null) =>
    v === null ? '' : ` · $${v.toLocaleString('en-US')}`;

function HomeLink({ home }: { home: Home }) {
    return home.url ? (
        <a
            href={home.url}
            target="_blank"
            rel="noreferrer"
            className="underline"
        >
            {home.label}
            {money(home.price)}
        </a>
    ) : (
        <span>
            {home.label}
            {money(home.price)}
        </span>
    );
}

/** What the agent needs to close: wants, budget, timeline, homes, an opening line and a checklist. */
function ClosingBriefCard({ brief }: { brief: Brief }) {
    const facts: [string, string][] = [
        ['Budget', brief.budget],
        ['Timeline', brief.timeline],
        ['Financing', brief.financing],
        ['Best time to reach', brief.best_time],
    ];
    return (
        <section className="grid gap-2 rounded-md border bg-background p-3 text-sm">
            <div className="flex items-center gap-2">
                <span className="font-semibold">Closing brief</span>
                {!brief.ai && (
                    <Badge variant="outline">
                        From their activity (AI unavailable)
                    </Badge>
                )}
            </div>
            <p>{brief.summary}</p>
            <dl className="grid gap-x-4 gap-y-1 sm:grid-cols-2">
                {facts.map(([k, v]) => (
                    <div key={k} className="flex gap-1">
                        <dt className="text-muted-foreground">{k}:</dt>
                        <dd>{v}</dd>
                    </div>
                ))}
            </dl>
            {brief.must_haves.length > 0 && (
                <p>
                    <span className="text-muted-foreground">Must-haves:</span>{' '}
                    {brief.must_haves.join(' · ')}
                </p>
            )}
            {brief.concerns.length > 0 && (
                <p>
                    <span className="text-muted-foreground">Concerns:</span>{' '}
                    {brief.concerns.join(' · ')}
                </p>
            )}
            {brief.opening_line !== '' && (
                <p className="rounded bg-muted/50 p-2">
                    <span className="text-muted-foreground">Open with: </span>“
                    {brief.opening_line}”
                </p>
            )}
            {brief.checklist.length > 0 && (
                <div>
                    <p className="text-muted-foreground">Do next:</p>
                    <ol className="ml-5 list-decimal">
                        {brief.checklist.map((c, i) => (
                            <li key={i}>{c}</li>
                        ))}
                    </ol>
                </div>
            )}
            {brief.top_homes.length > 0 && (
                <div>
                    <p className="text-muted-foreground">
                        Homes they care about:
                    </p>
                    <ul className="ml-5 list-disc">
                        {brief.top_homes.map((h) => (
                            <li key={h.ulid}>
                                <HomeLink home={h} />
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {brief.send_next.length > 0 && (
                <div>
                    <p className="text-muted-foreground">Send them next:</p>
                    <ul className="ml-5 list-disc">
                        {brief.send_next.map((h) => (
                            <li key={h.ulid}>
                                <HomeLink home={h} />
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}

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
                    {open ? 'Hide' : 'Closing brief and everything they did'}
                </Button>
            </div>
            {journey.summary !== '' && (
                <p className="text-sm whitespace-pre-line">{journey.summary}</p>
            )}
            {open && (
                <div className="grid gap-1">
                    {busy && <p className="text-muted-foreground">Loading…</p>}
                    {detail?.brief && <ClosingBriefCard brief={detail.brief} />}
                    {detail?.chat && (
                        <details className="rounded-md border p-2">
                            <summary className="cursor-pointer font-medium">
                                Chat with the website assistant
                            </summary>
                            <p className="mt-1 whitespace-pre-line">
                                {detail.chat}
                            </p>
                        </details>
                    )}
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
