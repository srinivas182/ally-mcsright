import { Head, Link, router } from '@inertiajs/react';

import { AuditEventRow, type AuditRow } from '@/components/audit-row';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Filters = {
    area: string | null;
    actor: string | null;
    from: string | null;
    to: string | null;
    severity: string | null;
    q: string | null;
};
type Props = {
    events: {
        data: AuditRow[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: Filters;
    people: { ulid: string; name: string }[];
};

const areas: [string, string][] = [
    ['core.auth', 'Sign-ins'],
    ['core.impersonation', 'Log in as'],
    ['core.user', 'People'],
    ['crm.contact', 'Contacts'],
    ['crm.task', 'Tasks'],
    ['crm.consent', 'Consent'],
];

export default function AuditLog({ events, filters, people }: Props) {
    const apply = (patch: Partial<Filters>) =>
        router.get(
            '/audit',
            { ...filters, ...patch },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <>
            <Head title="Audit log" />
            <div className="flex flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Audit log"
                    description="Every sign-in, view and change, sealed so it can't be altered."
                />
                <div className="flex flex-wrap gap-2 text-sm">
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
                    <select
                        aria-label="Importance"
                        className="rounded border bg-background px-2 py-1.5"
                        value={filters.severity ?? ''}
                        onChange={(e) =>
                            apply({ severity: e.target.value || null })
                        }
                    >
                        <option value="">All</option>
                        <option value="notice">Notable and above</option>
                        <option value="warning">Important only</option>
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
                {events.data.length === 0 ? (
                    <EmptyState title="Nothing recorded for these filters" />
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {events.data.map((e) => (
                            <AuditEventRow key={e.ulid} e={e} />
                        ))}
                    </ul>
                )}
                <nav
                    aria-label="Pages"
                    className="flex flex-wrap gap-1 text-sm"
                >
                    {events.links.map((l) =>
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
            </div>
        </>
    );
}

AuditLog.layout = { breadcrumbs: [{ title: 'Audit log', href: '/audit' }] };
