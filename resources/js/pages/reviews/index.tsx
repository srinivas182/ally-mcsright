import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';

type ReviewRow = {
    ulid: string;
    source: string;
    can_reply: boolean;
    author: string | null;
    rating: number | null;
    text: string | null;
    date: string;
    reply: string | null;
    hidden: boolean;
    hidden_reason: string | null;
};

function Row({
    r,
    canHide,
    reasons,
}: {
    r: ReviewRow;
    canHide: boolean;
    reasons: Record<string, string>;
}) {
    const [reply, setReply] = useState(r.reply ?? '');
    const [replying, setReplying] = useState(false);
    const [reason, setReason] = useState('');

    return (
        <li className="grid gap-2 rounded-xl border p-3">
            <div className="flex flex-wrap items-center gap-2 text-sm">
                <Badge variant="outline">{r.source}</Badge>
                {r.rating !== null && (
                    <span aria-label={`${r.rating} out of 5 stars`}>
                        {'★'.repeat(r.rating)}
                        <span className="text-muted-foreground">
                            {'☆'.repeat(5 - r.rating)}
                        </span>
                    </span>
                )}
                <span className="font-medium">{r.author ?? 'A client'}</span>
                <span className="text-muted-foreground">{r.date}</span>
                {r.hidden && (
                    <Badge variant="destructive">
                        Hidden: {r.hidden_reason}
                    </Badge>
                )}
            </div>
            {r.text && <p className="text-sm">{r.text}</p>}
            {r.reply && !replying && (
                <p className="rounded-lg bg-muted p-2 text-sm">
                    <span className="text-muted-foreground">Your reply: </span>
                    {r.reply}
                </p>
            )}
            {replying && (
                <div className="grid gap-2">
                    <textarea
                        aria-label="Your reply"
                        className="min-h-20 rounded-md border bg-background p-2 text-sm"
                        maxLength={4000}
                        value={reply}
                        onChange={(e) => setReply(e.target.value)}
                    />
                    <div>
                        <Button
                            size="sm"
                            onClick={() =>
                                router.post(
                                    `/reviews/${r.ulid}/reply`,
                                    { reply },
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setReplying(false),
                                    },
                                )
                            }
                        >
                            Post on Google
                        </Button>
                    </div>
                </div>
            )}
            <div className="flex flex-wrap items-center gap-2">
                {r.can_reply && !replying && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setReplying(true)}
                    >
                        {r.reply ? 'Change reply' : 'Reply'}
                    </Button>
                )}
                {canHide &&
                    (r.hidden ? (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    `/reviews/${r.ulid}/show`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Show again
                        </Button>
                    ) : (
                        <>
                            <select
                                aria-label="Why hide it"
                                className="h-9 rounded-md border bg-background px-2 text-sm"
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                            >
                                <option value="">Hide it because…</option>
                                {Object.entries(reasons).map(([k, v]) => (
                                    <option key={k} value={k}>
                                        {v}
                                    </option>
                                ))}
                            </select>
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={reason === ''}
                                onClick={() =>
                                    router.post(
                                        `/reviews/${r.ulid}/hide`,
                                        { reason },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Hide
                            </Button>
                        </>
                    ))}
            </div>
        </li>
    );
}

/**
 * Grow → Reviews (specs/connect/20): reviews from Google and RealSatisfied, each with its source and date. They show
 * on your websites; Google reviews can be answered from here. Low ratings stay; hiding needs a reason.
 */
export default function ReviewsPage({
    brokerage,
    can_brokerage,
    can_hide,
    summary,
    reasons,
    connected,
    reviews,
}: {
    brokerage: boolean;
    can_brokerage: boolean;
    can_hide: boolean;
    summary: {
        count: number;
        sources: {
            source: string;
            label: string;
            count: number;
            average: number | null;
        }[];
    };
    reasons: Record<string, string>;
    connected: string[];
    reviews: ReviewRow[];
}) {
    return (
        <>
            <Head title="Reviews" />
            <div className="grid max-w-3xl gap-6 p-4 text-base">
                <PageHeader
                    title={
                        brokerage ? "The brokerage's reviews" : 'Your reviews'
                    }
                    description="Reviews from Google and RealSatisfied, as their authors wrote them, on your websites. Answer Google reviews from here."
                />
                <div className="flex flex-wrap items-center gap-3 text-sm">
                    {summary.sources.map((s) => (
                        <span
                            key={s.source}
                            className="rounded-lg border px-3 py-2"
                        >
                            {s.label}:{' '}
                            {s.average !== null ? `${s.average} ★ · ` : ''}
                            {s.count} {s.count === 1 ? 'review' : 'reviews'}
                        </span>
                    ))}
                    {can_brokerage && (
                        <Link
                            href={
                                brokerage
                                    ? '/reviews'
                                    : '/reviews?who=brokerage'
                            }
                            className="underline"
                        >
                            {brokerage
                                ? 'My reviews'
                                : "The brokerage's reviews"}
                        </Link>
                    )}
                </div>
                {!connected.includes('google_business') && (
                    <p className="text-sm">
                        <Link
                            href="/apps/google_business"
                            className="underline"
                        >
                            Connect Google Business Profile
                        </Link>{' '}
                        to bring in your Google reviews.
                    </p>
                )}
                {!brokerage && !connected.includes('realsatisfied') && (
                    <p className="text-sm">
                        <Link href="/apps/realsatisfied" className="underline">
                            Connect RealSatisfied
                        </Link>{' '}
                        to bring in your RealSatisfied reviews.
                    </p>
                )}
                {reviews.length === 0 ? (
                    <EmptyState
                        title="No reviews yet"
                        description="They show here within a day of connecting."
                    />
                ) : (
                    <ul className="grid gap-3">
                        {reviews.map((r) => (
                            <Row
                                key={r.ulid}
                                r={r}
                                canHide={can_hide}
                                reasons={reasons}
                            />
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
