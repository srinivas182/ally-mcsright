import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { Textarea } from '@/components/ui/textarea';

type Match = { source: string; record: string; held: string | null };

type PrivacyRequest = {
    ulid: string;
    kind: 'know' | 'delete' | 'correct' | 'opt_out';
    kind_label: string;
    name: string;
    email: string;
    phone: string | null;
    details: string | null;
    status: 'open' | 'done' | 'declined';
    due_on: string | null;
    late: boolean;
    outcome: string | null;
    confirmed_at: string | null;
    matches: Match[] | null;
};

type Rule = {
    kind: string;
    label: string;
    about: string;
    months: number | null;
    default: number | null;
    min: number;
    last_run_at: string | null;
    last_removed: number;
};

type Props = {
    requests: PrivacyRequest[];
    retention: Rule[];
    canSetRetention: boolean;
    days: number;
};

const action: Record<PrivacyRequest['kind'], string> = {
    know: 'Send them a copy',
    delete: 'Delete their information',
    correct: 'Mark as corrected',
    opt_out: 'Stop marketing to them',
};

/**
 * TR1 (specs/core/18): privacy requests from the public, already confirmed by email, with the records RightAlly found
 * and a button that does the work; and how long each kind of data is kept.
 */
export default function Privacy({
    requests,
    retention,
    canSetRetention,
    days,
}: Props) {
    const open = requests.filter((r) => r.status === 'open');
    const closed = requests.filter((r) => r.status !== 'open');

    return (
        <>
            <Head title="Privacy requests" />
            <div className="mx-auto grid w-full max-w-4xl gap-8 p-4 text-base md:p-6">
                <PageHeader
                    title="Privacy requests"
                    description={`People can ask from your website to see, delete or correct their information, or to stop it being shared. They confirm by email first; the law gives you ${days} days to answer.`}
                />

                <section className="grid gap-3">
                    <h2 className="text-lg font-semibold">
                        Waiting for an answer
                    </h2>
                    {open.length === 0 && (
                        <p className="text-muted-foreground">
                            No requests are waiting.
                        </p>
                    )}
                    {open.map((r) => (
                        <OpenRequest key={r.ulid} request={r} />
                    ))}
                </section>

                <section className="grid gap-3">
                    <h2 className="text-lg font-semibold">
                        How long data is kept
                    </h2>
                    <p className="text-muted-foreground">
                        Every night, data older than this is removed. Deals,
                        money records and the audit log are never removed here;
                        they follow their own legal rules.
                    </p>
                    <ul className="grid gap-2">
                        {retention.map((rule) => (
                            <li
                                key={rule.kind}
                                className="grid gap-2 rounded-lg border p-4"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="font-medium">
                                        {rule.label}
                                    </span>
                                    {canSetRetention ? (
                                        <select
                                            aria-label={`Keep ${rule.label} for`}
                                            className="h-10 rounded-md border bg-background px-3"
                                            value={rule.months ?? ''}
                                            onChange={(e) =>
                                                router.post(
                                                    '/settings/privacy/retention',
                                                    {
                                                        kind: rule.kind,
                                                        months:
                                                            e.target.value ===
                                                            ''
                                                                ? null
                                                                : Number(
                                                                      e.target
                                                                          .value,
                                                                  ),
                                                    },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <option value="">Keep</option>
                                            {[
                                                3, 6, 12, 13, 18, 24, 36, 48,
                                                60, 84,
                                            ]
                                                .filter((m) => m >= rule.min)
                                                .map((m) => (
                                                    <option key={m} value={m}>
                                                        {m} months
                                                        {m === rule.default
                                                            ? ' (suggested)'
                                                            : ''}
                                                    </option>
                                                ))}
                                        </select>
                                    ) : (
                                        <Badge variant="outline">
                                            {rule.months === null
                                                ? 'Kept'
                                                : `${rule.months} months`}
                                        </Badge>
                                    )}
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {rule.about}
                                </p>
                                {rule.last_run_at && (
                                    <p className="text-sm text-muted-foreground">
                                        Last run{' '}
                                        {new Date(
                                            rule.last_run_at,
                                        ).toLocaleDateString()}
                                        : {rule.last_removed} removed.
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>

                {closed.length > 0 && (
                    <section className="grid gap-2">
                        <h2 className="text-lg font-semibold">Answered</h2>
                        <ul className="divide-y rounded-lg border">
                            {closed.map((r) => (
                                <li key={r.ulid} className="grid gap-1 p-3">
                                    <span className="font-medium">
                                        {r.name} · {r.kind_label}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {r.status === 'done'
                                            ? 'Done'
                                            : 'Declined'}
                                        : {r.outcome}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

function OpenRequest({ request: r }: { request: PrivacyRequest }) {
    const [note, setNote] = useState('');
    const opts = { preserveScroll: true };

    return (
        <article className="grid gap-3 rounded-lg border p-4">
            <div className="flex flex-wrap items-center gap-2">
                <h3 className="grow font-semibold">
                    {r.name} · {r.kind_label}
                </h3>
                {r.late ? (
                    <Badge variant="destructive">Late</Badge>
                ) : (
                    <Badge variant="outline">Answer by {r.due_on}</Badge>
                )}
            </div>
            <p className="text-sm text-muted-foreground">
                {r.email}
                {r.phone ? ` · ${r.phone}` : ''} · confirmed{' '}
                {r.confirmed_at
                    ? new Date(r.confirmed_at).toLocaleDateString()
                    : ''}
            </p>
            {r.details && (
                <p className="rounded-md bg-muted/50 p-3">{r.details}</p>
            )}
            <div>
                <p className="font-medium">What RightAlly found</p>
                {r.matches === null || r.matches.length === 0 ? (
                    <p className="text-muted-foreground">
                        No records with this email or phone.
                    </p>
                ) : (
                    <ul className="list-disc pl-6">
                        {r.matches.map((m, i) => (
                            <li key={i}>
                                {m.source}: {m.record}
                                {m.held && (
                                    <span className="text-amber-700 dark:text-amber-400">
                                        {' '}
                                        · kept: {m.held}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            <label className="grid gap-1">
                <span className="text-sm">
                    {r.kind === 'correct'
                        ? 'What you corrected'
                        : 'Note (needed only to decline)'}
                </span>
                <Textarea
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                />
            </label>
            <div className="flex flex-wrap gap-2">
                <Button
                    onClick={() =>
                        router.post(
                            `/settings/privacy/${r.ulid}/complete`,
                            { note },
                            opts,
                        )
                    }
                >
                    {action[r.kind]}
                </Button>
                <Button
                    variant="outline"
                    onClick={() =>
                        router.post(
                            `/settings/privacy/${r.ulid}/decline`,
                            { note },
                            opts,
                        )
                    }
                >
                    Decline with this note
                </Button>
            </div>
        </article>
    );
}
