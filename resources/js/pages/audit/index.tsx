import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { AuditEventRow, type AuditRow } from '@/components/audit-row';
import { AuditStreams, type Stream } from '@/components/audit-streams';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Line = {
    sentence: string;
    at: string;
    count: number;
    important: boolean;
    items: AuditRow[];
};
type Filters = {
    area: string | null;
    actor: string | null;
    from: string | null;
    to: string | null;
    severity: string | null;
    q: string | null;
    important: boolean | string;
};
type Props = {
    view: 'activity' | 'technical';
    rows: (Line | AuditRow)[];
    links: { url: string | null; label: string; active: boolean }[];
    filters: Filters;
    people: { ulid: string; name: string }[];
    retention: { years: number; min: number; max: number };
    holds: {
        ulid: string;
        reason: string;
        scope: string;
        since: string | null;
    }[];
    streams: Stream[];
};

const areas: [string, string][] = [
    ['core.auth', 'Sign-ins'],
    ['core.impersonation', 'Log in as'],
    ['core.user', 'People'],
    ['crm.contact', 'Contacts'],
    ['crm.task', 'Tasks'],
    ['crm.consent', 'Consent'],
    ['core.audit', 'Retention and holds'],
];

function ActivityLine({ line }: { line: Line }) {
    const [open, setOpen] = useState(false);

    return (
        <li className="p-3 text-sm">
            <button
                type="button"
                className="grid w-full gap-0.5 text-left"
                aria-expanded={open}
                onClick={() => setOpen(!open)}
            >
                <span className="flex flex-wrap items-center gap-2">
                    {line.sentence}
                    {line.important && (
                        <Badge variant="secondary">Important</Badge>
                    )}
                </span>
                <span className="text-xs text-muted-foreground">
                    {new Date(line.at).toLocaleString()}
                </span>
            </button>
            {open && (
                <ul className="mt-2 divide-y rounded-md border">
                    {line.items.map((e) => (
                        <AuditEventRow key={e.ulid} e={e} />
                    ))}
                </ul>
            )}
        </li>
    );
}

function Retention({ retention, holds }: Pick<Props, 'retention' | 'holds'>) {
    const years = useForm({ years: retention.years });
    const hold = useForm({ reason: '', from_date: '', to_date: '' });

    return (
        <details className="rounded-lg border p-4 text-sm">
            <summary className="cursor-pointer font-medium">
                Retention and legal holds
            </summary>
            <form
                className="mt-3 flex flex-wrap items-end gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    years.post('/audit/retention', { preserveScroll: true });
                }}
            >
                <label className="grid gap-1">
                    Keep audit events for (years)
                    <Input
                        type="number"
                        min={retention.min}
                        max={retention.max}
                        className="w-24"
                        value={years.data.years}
                        onChange={(e) =>
                            years.setData('years', Number(e.target.value))
                        }
                    />
                </label>
                <Button type="submit" variant="outline">
                    Save
                </Button>
                <span className="text-xs text-muted-foreground">
                    At least {retention.min} years; deleted after, unless on
                    legal hold.
                </span>
            </form>
            <p className="mt-4 font-medium">Legal holds</p>
            {holds.length === 0 && (
                <p className="text-muted-foreground">
                    None. Place a hold to stop deletion during a dispute, audit
                    or lawsuit.
                </p>
            )}
            {holds.map((h) => (
                <p
                    key={h.ulid}
                    className="flex items-center justify-between gap-2 border-b py-1"
                >
                    <span>
                        {h.scope}: {h.reason}
                    </span>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                            router.post(
                                `/audit/holds/${h.ulid}/release`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Release
                    </Button>
                </p>
            ))}
            <form
                className="mt-2 flex flex-wrap items-end gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    hold.post('/audit/holds', {
                        preserveScroll: true,
                        onSuccess: () => hold.reset(),
                    });
                }}
            >
                <Input
                    aria-label="Reason"
                    placeholder="Smith v. Acme Realty, case 24-1187"
                    className="min-w-64 flex-1"
                    value={hold.data.reason}
                    onChange={(e) => hold.setData('reason', e.target.value)}
                />
                <Input
                    aria-label="From"
                    type="date"
                    className="w-40"
                    value={hold.data.from_date}
                    onChange={(e) => hold.setData('from_date', e.target.value)}
                />
                <Input
                    aria-label="To"
                    type="date"
                    className="w-40"
                    value={hold.data.to_date}
                    onChange={(e) => hold.setData('to_date', e.target.value)}
                />
                <Button
                    type="submit"
                    variant="outline"
                    disabled={hold.processing}
                >
                    Place hold
                </Button>
            </form>
            {hold.errors.reason && (
                <p className="text-sm text-destructive">{hold.errors.reason}</p>
            )}
        </details>
    );
}

export default function AuditLog({
    view,
    rows,
    links,
    filters,
    people,
    retention,
    holds,
    streams,
}: Props) {
    const apply = (patch: Partial<Filters> & { view?: string }) =>
        router.get(
            '/audit',
            { ...filters, view, ...patch },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    const important = filters.important === true || filters.important === '1';

    return (
        <>
            <Head title="Audit log" />
            <div className="flex flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Audit log"
                    description="Every sign-in, view and change, sealed so it can't be altered."
                />
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <div
                        role="group"
                        aria-label="View"
                        className="flex rounded border"
                    >
                        {(['activity', 'technical'] as const).map((v) => (
                            <button
                                key={v}
                                type="button"
                                aria-pressed={view === v}
                                className={`px-3 py-1.5 capitalize ${view === v ? 'bg-muted font-medium' : ''}`}
                                onClick={() => apply({ view: v })}
                            >
                                {v}
                            </button>
                        ))}
                    </div>
                    <label className="flex items-center gap-1">
                        <input
                            type="checkbox"
                            className="size-4"
                            checked={important}
                            onChange={(e) =>
                                apply({
                                    important: e.target.checked ? '1' : false,
                                })
                            }
                        />
                        Important only
                    </label>
                    <select
                        aria-label="Area"
                        className="rounded border bg-background px-2 py-1.5"
                        value={filters.area ?? ''}
                        onChange={(e) =>
                            apply({ area: e.target.value || null })
                        }
                    >
                        <option value="">Everything</option>
                        {areas.map(([v, l]) => (
                            <option key={v} value={v}>
                                {l}
                            </option>
                        ))}
                    </select>
                    <select
                        aria-label="Person"
                        className="rounded border bg-background px-2 py-1.5"
                        value={filters.actor ?? ''}
                        onChange={(e) =>
                            apply({ actor: e.target.value || null })
                        }
                    >
                        <option value="">Anyone</option>
                        {people.map((p) => (
                            <option key={p.ulid} value={p.ulid}>
                                {p.name}
                            </option>
                        ))}
                    </select>
                    <Input
                        aria-label="From"
                        type="date"
                        className="w-40"
                        value={filters.from ?? ''}
                        onChange={(e) =>
                            apply({ from: e.target.value || null })
                        }
                    />
                    <Input
                        aria-label="To"
                        type="date"
                        className="w-40"
                        value={filters.to ?? ''}
                        onChange={(e) => apply({ to: e.target.value || null })}
                    />
                    <Input
                        aria-label="Search names"
                        placeholder="Pat Lee"
                        className="w-48"
                        defaultValue={filters.q ?? ''}
                        onKeyDown={(e) =>
                            e.key === 'Enter' &&
                            apply({
                                q: (e.target as HTMLInputElement).value || null,
                            })
                        }
                    />
                </div>
                {rows.length === 0 ? (
                    <EmptyState title="Nothing recorded for these filters" />
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {view === 'activity'
                            ? (rows as Line[]).map((l) => (
                                  <ActivityLine
                                      key={l.items[0].ulid}
                                      line={l}
                                  />
                              ))
                            : (rows as AuditRow[]).map((e) => (
                                  <AuditEventRow key={e.ulid} e={e} />
                              ))}
                    </ul>
                )}
                <nav
                    aria-label="Pages"
                    className="flex flex-wrap gap-1 text-sm"
                >
                    {links.map((l) =>
                        l.url ? (
                            <Link
                                key={l.label}
                                href={l.url}
                                preserveScroll
                                className={`rounded border px-2 py-1 ${l.active ? 'bg-muted font-medium' : ''}`}
                            >
                                <span
                                    dangerouslySetInnerHTML={{
                                        __html: l.label,
                                    }}
                                />
                            </Link>
                        ) : null,
                    )}
                </nav>
                <Retention retention={retention} holds={holds} />
                <AuditStreams streams={streams} />
            </div>
        </>
    );
}

AuditLog.layout = { breadcrumbs: [{ title: 'Audit log', href: '/audit' }] };
