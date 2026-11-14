import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Point = { topic: string; text: string };
export type Deadline = { label: string; date: string; rule: string };
export type PacketSummary = {
    status: 'none' | 'draft' | 'approved' | 'stale';
    content: {
        headline: string;
        points: Point[];
        deadlines: Deadline[];
        agreeing_to: string[];
        unusual: { text: string; why: string }[];
        es: { headline: string; points: Point[]; agreeing_to: string[] };
        removed: number;
    } | null;
    generated_at: string | null;
    approved_by: string | null;
};
export type ClauseReview = {
    setting: string;
    terms: { form: string; label: string; text: string }[];
    approved: boolean;
    can_approve: boolean;
};

const statusLabel: Record<PacketSummary['status'], string> = {
    none: 'Not written',
    draft: 'Draft — check and approve',
    approved: 'Approved — signers see it',
    stale: 'Out of date — terms changed',
};

/** Points, deadlines and commitments in one language. */
export function SummaryBody({
    headline,
    points,
    agreeing,
}: {
    headline: string;
    points: Point[];
    agreeing: string[];
}) {
    return (
        <div className="grid gap-2">
            {headline && <p className="font-medium">{headline}</p>}
            {points.length > 0 && (
                <dl className="grid gap-1">
                    {points.map((p, i) => (
                        <div key={i}>
                            <dt className="text-xs text-muted-foreground">
                                {p.topic}
                            </dt>
                            <dd>{p.text}</dd>
                        </div>
                    ))}
                </dl>
            )}
            {agreeing.length > 0 && (
                <ul className="grid gap-0.5">
                    {agreeing.map((a) => (
                        <li key={a}>✓ {a}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * The agent's view of the plain-English summary: write, check (English and Spanish), approve, and put
 * deadlines on the deal's key dates.
 */
export function SummaryPanel({
    packet,
    status,
    summary,
}: {
    packet: string;
    status: string;
    summary: PacketSummary;
}) {
    const [lang, setLang] = useState<'en' | 'es'>('en');
    const c = summary.content;
    const [picked, setPicked] = useState<Record<number, string>>({});
    const opts = { preserveScroll: true };
    const finished = status === 'completed' || status === 'voided';
    if (finished && !c) return null;

    return (
        <section className="grid gap-3 rounded-lg border p-4 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <h2 className="font-semibold">Plain-English summary</h2>
                <Badge
                    variant={
                        summary.status === 'approved' ? 'secondary' : 'outline'
                    }
                >
                    {statusLabel[summary.status]}
                </Badge>
                {!finished && (
                    <Button
                        size="sm"
                        variant="outline"
                        className="ml-auto"
                        onClick={() =>
                            router.post(
                                `/forms/packets/${packet}/summary`,
                                {},
                                opts,
                            )
                        }
                    >
                        {c ? 'Write again' : 'Write summary'}
                    </Button>
                )}
            </div>
            {!c ? (
                <p className="text-muted-foreground">
                    AI writes a short summary from the filled-in terms, in
                    English and Spanish. Every amount and date is checked
                    against the documents. You approve it before signers see it.
                </p>
            ) : (
                <>
                    <div className="flex gap-1" role="tablist">
                        {(['en', 'es'] as const).map((l) => (
                            <Button
                                key={l}
                                size="sm"
                                role="tab"
                                aria-selected={lang === l}
                                variant={lang === l ? 'secondary' : 'ghost'}
                                onClick={() => setLang(l)}
                            >
                                {l === 'en' ? 'English' : 'Español'}
                            </Button>
                        ))}
                    </div>
                    {lang === 'en' ? (
                        <SummaryBody
                            headline={c.headline}
                            points={c.points}
                            agreeing={c.agreeing_to}
                        />
                    ) : (
                        <SummaryBody
                            headline={c.es.headline}
                            points={c.es.points}
                            agreeing={c.es.agreeing_to}
                        />
                    )}
                    {c.removed > 0 && (
                        <p className="text-xs text-muted-foreground">
                            {c.removed} line(s) were left out because their
                            amounts or dates are not in the documents.
                        </p>
                    )}
                    {c.unusual.length > 0 && (
                        <div className="rounded border border-amber-300 bg-amber-50 p-2 text-amber-900">
                            <p className="font-medium">Worth a second look</p>
                            {c.unusual.map((u, i) => (
                                <p key={i}>
                                    • {u.text} — {u.why}
                                </p>
                            ))}
                        </div>
                    )}
                    {summary.status === 'draft' && (
                        <Button
                            size="sm"
                            className="justify-self-start"
                            onClick={() =>
                                router.post(
                                    `/forms/packets/${packet}/summary/approve`,
                                    {},
                                    opts,
                                )
                            }
                        >
                            Approve for signers
                        </Button>
                    )}
                    {summary.approved_by && summary.status === 'approved' && (
                        <p className="text-xs text-muted-foreground">
                            Approved by {summary.approved_by}
                        </p>
                    )}
                    {c.deadlines.length > 0 && (
                        <div className="grid gap-1 border-t pt-2">
                            <h3 className="font-medium">Deadlines</h3>
                            {c.deadlines.map((d, i) => (
                                <label
                                    key={i}
                                    className="flex flex-wrap items-center gap-2"
                                >
                                    <input
                                        type="checkbox"
                                        className="size-4"
                                        checked={i in picked}
                                        onChange={(e) => {
                                            const next = { ...picked };
                                            if (e.target.checked)
                                                next[i] = d.date;
                                            else delete next[i];
                                            setPicked(next);
                                        }}
                                    />
                                    <span>{d.label}</span>
                                    {i in picked ? (
                                        <Input
                                            type="date"
                                            aria-label={`${d.label} date`}
                                            className="w-40"
                                            value={picked[i]}
                                            onChange={(e) =>
                                                setPicked({
                                                    ...picked,
                                                    [i]: e.target.value,
                                                })
                                            }
                                        />
                                    ) : (
                                        <span className="text-muted-foreground">
                                            {d.date || 'date to set'}
                                        </span>
                                    )}
                                    <span className="text-xs text-muted-foreground">
                                        {d.rule}
                                    </span>
                                </label>
                            ))}
                            <Button
                                size="sm"
                                variant="outline"
                                className="justify-self-start"
                                disabled={
                                    Object.keys(picked).length === 0 ||
                                    Object.values(picked).some((v) => !v)
                                }
                                onClick={() =>
                                    router.post(
                                        `/forms/packets/${packet}/dates`,
                                        {
                                            dates: Object.entries(picked).map(
                                                ([i, date]) => ({
                                                    label: c.deadlines[
                                                        Number(i)
                                                    ].label,
                                                    date,
                                                }),
                                            ),
                                        },
                                        {
                                            ...opts,
                                            onSuccess: () => setPicked({}),
                                        },
                                    )
                                }
                            >
                                Add to the deal's key dates
                            </Button>
                        </div>
                    )}
                </>
            )}
        </section>
    );
}

/** Special terms not from the brokerage's clause library, found before sending. */
export function ClauseReviewPanel({
    packet,
    status,
    review,
}: {
    packet: string;
    status: string;
    review: ClauseReview;
}) {
    if (review.setting === 'off' || review.terms.length === 0) return null;

    return (
        <section
            role="status"
            className="grid gap-2 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
        >
            <p className="font-medium">
                Special terms not from your clause library (
                {review.terms.length}){review.approved && ' — approved'}
            </p>
            {review.terms.map((t, i) => (
                <div key={i}>
                    <p className="text-xs">
                        {t.form} · {t.label}
                    </p>
                    <p className="whitespace-pre-wrap">{t.text}</p>
                </div>
            ))}
            {!review.approved && review.setting === 'require' && (
                <p className="text-xs">
                    The broker or office staff must approve this wording before
                    the packet can be sent.
                </p>
            )}
            {!review.approved && review.can_approve && status === 'draft' && (
                <Button
                    size="sm"
                    variant="outline"
                    className="justify-self-start bg-background"
                    onClick={() =>
                        router.post(
                            `/forms/packets/${packet}/clauses/approve`,
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    Approve this wording
                </Button>
            )}
        </section>
    );
}
