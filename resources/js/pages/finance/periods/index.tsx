import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { Textarea } from '@/components/ui/textarea';

type Finding = {
    key: string;
    ref: string;
    level: 'stop' | 'check';
    title: string;
    detail: string;
    link: string | null;
};

type Period = {
    id: number;
    month: string;
    starts_on: string;
    ends_on: string;
    status: 'open' | 'closed';
    closed_at: string | null;
    checked_at: string | null;
    finished: boolean;
    can_close: boolean;
    findings: Finding[];
    close_notes: {
        note: string;
        items: { title: string; detail: string }[];
    } | null;
    year_end: boolean;
};

type Props = {
    periods: Period[];
    checks: { key: string; label: string }[];
    years: { year: number; closed: boolean }[];
    can: { close: boolean; export: boolean };
};

/** FH1: month-end close. The checklist runs nightly and on demand; months close in order. */
export default function PeriodsIndex({ periods, checks, years, can }: Props) {
    const firstOpen = [...periods]
        .reverse()
        .find((p) => p.status === 'open' && p.finished);

    return (
        <>
            <Head title="Month-end close" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Month-end close"
                    description="Closed months can't be changed. A mistake is fixed with a new entry in an open month, so every change stays visible. We check each finished month every night."
                />

                {years.some((y) => y.closed) && (
                    <p className="text-sm">
                        Closed years:{' '}
                        {years
                            .filter((y) => y.closed)
                            .map((y) => y.year)
                            .join(', ')}
                    </p>
                )}

                {firstOpen && (
                    <MonthChecklist
                        period={firstOpen}
                        checks={checks}
                        canClose={can.close}
                    />
                )}

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-base">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3 font-medium">Month</th>
                                <th className="p-3 font-medium">Status</th>
                                <th className="p-3 font-medium">
                                    Statements as closed
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {periods.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={3}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        Months appear when the first entry is
                                        posted.
                                    </td>
                                </tr>
                            )}
                            {periods.map((period) => (
                                <tr key={period.id} className="border-t">
                                    <td className="p-3">
                                        {period.month}
                                        {period.close_notes && (
                                            <p className="text-sm text-muted-foreground">
                                                Closed with a note: “
                                                {period.close_notes.note}”
                                            </p>
                                        )}
                                    </td>
                                    <td className="p-3">
                                        <Badge
                                            variant={
                                                period.status === 'open'
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {period.status === 'open'
                                                ? period.finished
                                                    ? 'Ready to close'
                                                    : 'Open'
                                                : 'Closed'}
                                        </Badge>
                                    </td>
                                    <td className="p-3">
                                        {period.status === 'closed' &&
                                            can.export && (
                                                <span className="flex flex-wrap gap-3">
                                                    <a
                                                        className="underline"
                                                        href={`/finance/periods/${period.id}/statements.pdf`}
                                                    >
                                                        Month (PDF)
                                                    </a>
                                                    {period.year_end && (
                                                        <a
                                                            className="underline"
                                                            href={`/finance/periods/${period.id}/statements.pdf?kind=year`}
                                                        >
                                                            Year (PDF)
                                                        </a>
                                                    )}
                                                </span>
                                            )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

function MonthChecklist({
    period,
    checks,
    canClose,
}: {
    period: Period;
    checks: Props['checks'];
    canClose: boolean;
}) {
    const [note, setNote] = useState('');
    const stops = period.findings.filter((f) => f.level === 'stop');
    const toCheck = period.findings.filter((f) => f.level === 'check');
    const failing = new Set(period.findings.map((f) => f.key));

    return (
        <section
            aria-labelledby="checklist-title"
            className="grid gap-4 rounded-lg border p-4"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="checklist-title" className="text-xl font-semibold">
                        Before you close {period.month}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {period.checked_at
                            ? `Last checked ${new Date(period.checked_at).toLocaleString()}.`
                            : 'Not checked yet.'}
                    </p>
                </div>
                {canClose && (
                    <Button
                        variant="outline"
                        size="lg"
                        onClick={() =>
                            router.post(
                                `/finance/periods/${period.id}/check`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Check again
                    </Button>
                )}
            </div>

            {period.checked_at && (
                <ul className="grid gap-2 text-base">
                    {checks
                        .filter((c) => !failing.has(c.key))
                        .map((c) => (
                            <li key={c.key} className="flex gap-2">
                                <span aria-hidden>✓</span>
                                <span>{c.label}</span>
                            </li>
                        ))}
                </ul>
            )}

            {stops.length > 0 && (
                <FindingList
                    title="Must fix before closing"
                    tone="destructive"
                    items={stops}
                />
            )}
            {toCheck.length > 0 && (
                <FindingList
                    title="Worth a look"
                    tone="warning"
                    items={toCheck}
                />
            )}

            {canClose && stops.length === 0 && (
                <form
                    className="grid gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post(
                            `/finance/periods/${period.id}/close`,
                            { note },
                            { preserveScroll: true },
                        );
                    }}
                >
                    {toCheck.length > 0 && (
                        <label className="grid gap-1 text-base">
                            Why is it OK to close with{' '}
                            {toCheck.length === 1
                                ? 'this item'
                                : `these ${toCheck.length} items`}{' '}
                            to check?
                            <Textarea
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                rows={3}
                                maxLength={1000}
                            />
                        </label>
                    )}
                    <div>
                        <Button
                            type="submit"
                            size="lg"
                            disabled={
                                toCheck.length > 0 && note.trim().length < 10
                            }
                        >
                            Close {period.month}
                            {period.year_end ? ' and the year' : ''}
                        </Button>
                    </div>
                </form>
            )}
        </section>
    );
}

function FindingList({
    title,
    tone,
    items,
}: {
    title: string;
    tone: 'destructive' | 'warning';
    items: Finding[];
}) {
    return (
        <div className="grid gap-2">
            <h3
                className={
                    tone === 'destructive'
                        ? 'font-semibold text-destructive'
                        : 'font-semibold'
                }
            >
                {title}
            </h3>
            <ul className="grid gap-3">
                {items.map((f) => (
                    <li
                        key={`${f.key}-${f.ref}`}
                        className="rounded-md border p-3"
                    >
                        <p className="font-medium">{f.title}</p>
                        <p className="text-sm text-muted-foreground">
                            {f.detail}
                        </p>
                        {f.link && (
                            <Link className="text-sm underline" href={f.link}>
                                Go there
                            </Link>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}

PeriodsIndex.layout = {
    breadcrumbs: [{ title: 'Month-end close', href: '/finance/periods' }],
};
