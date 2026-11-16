import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { Textarea } from '@/components/ui/textarea';

type Item = {
    id: number;
    person: string;
    person_ulid: string;
    kind: string;
    role: string;
    risky: boolean;
    expires_at: string | null;
    last_sign_in: string | null;
    dormant: boolean;
    access: string[];
    decision: 'keep' | 'remove' | null;
};
type Props = {
    quarter: string;
    started: boolean;
    completed: string | null;
    completedBy: string | null;
    note: string | null;
    items: Item[];
    history: { quarter: string; completed_at: string }[];
    canAct: boolean;
};

/** The quarterly access review (specs/core/03, R1). */
export default function AccessReview({
    quarter,
    started,
    completed,
    completedBy,
    note,
    items,
    history,
    canAct,
}: Props) {
    const [signNote, setSignNote] = useState('');
    const [error, setError] = useState<string | null>(null);
    const open = items.filter((i) => i.decision === null).length;
    const decide = (id: number, decision: 'keep' | 'remove') =>
        router.post(
            `/settings/access-review/items/${id}`,
            { decision },
            {
                preserveScroll: true,
                onError: (e) => setError(Object.values(e)[0] ?? null),
            },
        );

    return (
        <>
            <Head title="Access review" />
            <div className="flex flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title={`Access review: ${quarter}`}
                    description="Once a quarter, confirm who can see money, people and contacts. Keep or remove each role, then sign it off."
                    actions={
                        <Button variant="outline" asChild>
                            <a href="/settings/access-review/export">
                                Export who can do what (CSV)
                            </a>
                        </Button>
                    }
                />
                {error && (
                    <p role="alert" className="text-sm text-destructive">
                        {error}
                    </p>
                )}
                {completed ? (
                    <p className="rounded-lg border p-3 text-sm">
                        Signed off on {completed.slice(0, 10)}
                        {completedBy ? ` by ${completedBy}` : ''}.
                        {note ? ` Note: ${note}` : ''}
                    </p>
                ) : !started ? (
                    <section className="grid gap-2 rounded-lg border p-4 text-sm">
                        <p>
                            The review lists every role each person holds, with
                            what it gives them, when they last signed in and
                            when their access ends.
                        </p>
                        {canAct && (
                            <Button
                                className="justify-self-start"
                                onClick={() =>
                                    router.post(
                                        '/settings/access-review/start',
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Start this quarter's review
                            </Button>
                        )}
                    </section>
                ) : null}
                {started && (
                    <ul className="grid gap-3">
                        {items.length === 0 && (
                            <li className="text-sm text-muted-foreground">
                                Nobody holds a role yet: everyone has the access
                                of their user type.
                            </li>
                        )}
                        {items.map((i) => (
                            <li
                                key={i.id}
                                className="grid gap-2 rounded-lg border p-4 text-sm md:grid-cols-[1fr_auto]"
                            >
                                <div className="grid gap-1">
                                    <p className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {i.person}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {i.role}
                                        </span>
                                        {i.risky && (
                                            <Badge variant="outline">
                                                Money, people or exports
                                            </Badge>
                                        )}
                                        {i.dormant && (
                                            <Badge variant="secondary">
                                                {i.last_sign_in
                                                    ? `Last signed in ${i.last_sign_in}`
                                                    : 'Never signed in'}
                                            </Badge>
                                        )}
                                        {i.expires_at && (
                                            <Badge variant="secondary">
                                                Ends {i.expires_at}
                                            </Badge>
                                        )}
                                    </p>
                                    <ul className="list-disc pl-5 text-muted-foreground">
                                        {i.access.map((a) => (
                                            <li key={a}>{a}</li>
                                        ))}
                                    </ul>
                                </div>
                                <div className="flex items-start gap-2">
                                    {i.decision ? (
                                        <Badge
                                            variant={
                                                i.decision === 'remove'
                                                    ? 'destructive'
                                                    : 'secondary'
                                            }
                                        >
                                            {i.decision === 'remove'
                                                ? 'Removed'
                                                : 'Kept'}
                                        </Badge>
                                    ) : (
                                        canAct &&
                                        !completed && (
                                            <>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        decide(i.id, 'keep')
                                                    }
                                                >
                                                    Keep
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="destructive"
                                                    onClick={() =>
                                                        decide(i.id, 'remove')
                                                    }
                                                >
                                                    Remove
                                                </Button>
                                            </>
                                        )
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                {started && !completed && canAct && (
                    <section className="grid gap-2 rounded-lg border p-4 text-sm">
                        <p>
                            {open > 0
                                ? `${open} line${open === 1 ? '' : 's'} still to decide.`
                                : 'Everything is decided. Sign the review off.'}
                        </p>
                        <Textarea
                            aria-label="Note for the record (optional)"
                            placeholder="Note for the record (optional)"
                            value={signNote}
                            onChange={(e) => setSignNote(e.target.value)}
                        />
                        <Button
                            className="justify-self-start"
                            disabled={open > 0}
                            onClick={() =>
                                router.post(
                                    '/settings/access-review/complete',
                                    { note: signNote },
                                    {
                                        preserveScroll: true,
                                        onError: (e) =>
                                            setError(
                                                Object.values(e)[0] ?? null,
                                            ),
                                    },
                                )
                            }
                        >
                            Sign off the review
                        </Button>
                    </section>
                )}
                {history.length > 0 && (
                    <section className="grid gap-1 text-sm">
                        <p className="font-medium">Signed-off reviews</p>
                        {history.map((h) => (
                            <p
                                key={h.quarter}
                                className="text-muted-foreground"
                            >
                                {h.quarter}: signed off {h.completed_at}
                            </p>
                        ))}
                    </section>
                )}
            </div>
        </>
    );
}

AccessReview.layout = {
    breadcrumbs: [{ title: 'Access review', href: '/settings/access-review' }],
};
