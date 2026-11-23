import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { CopyText } from '@/components/setup-guide';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Signup = {
    ulid: string;
    type: string;
    brokerage: string;
    slug: string;
    owner: string;
    email: string;
    state: string | null;
    agents: number | null;
    package: string | null;
    interval: string;
    price_cents: number;
    boards: number;
    status: 'unfinished' | 'live' | 'failed' | 'replaced';
    stopped: string | null;
    design: string | null;
    last_at: string;
    error: string | null;
    started_at: string;
    reminders: number;
    reminded_at: string | null;
    no_reminders: boolean;
    resume_url: string | null;
    can_resend: boolean;
};

type Props = {
    status: string;
    q: string;
    statuses: Record<string, string>;
    counts: Record<string, number>;
    signups: Signup[];
};

const tone: Record<
    Signup['status'],
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    unfinished: 'outline',
    live: 'default',
    failed: 'destructive',
    replaced: 'secondary',
};

const when = (iso: string) =>
    new Date(
        iso.replace(' ', 'T') + (iso.includes('T') ? '' : 'Z'),
    ).toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });

/** SU1: everyone who started signing up, where they stopped, and a reminder for those who didn't pay. */
export default function HqSignups({
    status,
    q,
    statuses,
    counts,
    signups,
}: Props) {
    const [search, setSearch] = useState(q);
    const [open, setOpen] = useState<string | null>(null);
    const go = (next: { status?: string; q?: string }) =>
        router.get(
            '/hq/signups',
            { status, q: search, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <>
            <Head title="Sign-ups" />
            <div className="flex flex-col gap-5 p-4 md:p-6">
                <PageHeader
                    title="Sign-ups"
                    description="Everyone who started signing up online. People who didn't finish paying get a reminder about an hour later and again a day later, with a link that brings their form back filled in."
                />
                <div className="flex flex-wrap items-center gap-2">
                    {[...Object.entries(statuses), ['all', 'All']].map(
                        ([key, label]) => (
                            <Button
                                key={key}
                                size="sm"
                                variant={status === key ? 'default' : 'outline'}
                                aria-pressed={status === key}
                                onClick={() => go({ status: key })}
                            >
                                {label}
                                {key !== 'all' && ` (${counts[key] ?? 0})`}
                            </Button>
                        ),
                    )}
                    <form
                        className="ml-auto flex gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            go({ q: search });
                        }}
                    >
                        <Input
                            aria-label="Search sign-ups"
                            placeholder="Name, email or address"
                            className="w-64"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                        <Button type="submit" variant="outline">
                            Search
                        </Button>
                    </form>
                </div>

                {signups.length === 0 ? (
                    <p className="rounded-xl border bg-card p-6 text-center text-muted-foreground">
                        {status === 'unfinished'
                            ? 'Nobody is part-way through signing up.'
                            : 'No sign-ups match.'}
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-xl border bg-card">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3">Who</th>
                                    <th className="p-3">Chose</th>
                                    <th className="p-3">Last step</th>
                                    <th className="p-3">Status</th>
                                    <th className="p-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {signups.map((s) => (
                                    <tr
                                        key={s.ulid}
                                        className="border-t align-top"
                                    >
                                        <td className="p-3">
                                            <p className="font-medium">
                                                {s.brokerage}
                                            </p>
                                            <p className="text-muted-foreground">
                                                {s.owner} · {s.email}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {s.type} · {s.slug}
                                                {s.state ? ` · ${s.state}` : ''}
                                                {s.agents
                                                    ? ` · ${s.agents} agents`
                                                    : ''}
                                            </p>
                                        </td>
                                        <td className="p-3">
                                            <p>
                                                {s.package ?? 'Unknown package'}
                                            </p>
                                            <p className="text-muted-foreground">
                                                {s.package
                                                    ? `${usd(s.price_cents)} a ${s.interval}`
                                                    : 'Package not chosen yet'}
                                                {s.design
                                                    ? ` · ${s.design} design`
                                                    : ''}
                                                {s.boards > 0
                                                    ? ` · ${s.boards} MLS board${s.boards > 1 ? 's' : ''}`
                                                    : ''}
                                            </p>
                                        </td>
                                        <td className="p-3 whitespace-nowrap">
                                            {when(s.last_at)}
                                        </td>
                                        <td className="p-3">
                                            <Badge variant={tone[s.status]}>
                                                {statuses[s.status] ?? s.status}
                                            </Badge>
                                            {s.stopped && (
                                                <p className="mt-1 text-xs font-medium">
                                                    {s.stopped}
                                                </p>
                                            )}
                                            {s.status === 'unfinished' && (
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    {s.no_reminders
                                                        ? 'Asked for no reminders'
                                                        : s.reminders === 0
                                                          ? 'No reminder yet'
                                                          : `${s.reminders} reminder${s.reminders > 1 ? 's' : ''} sent`}
                                                </p>
                                            )}
                                            {s.error && (
                                                <p className="mt-1 text-xs text-destructive">
                                                    {s.error}
                                                </p>
                                            )}
                                        </td>
                                        <td className="p-3">
                                            {s.can_resend && (
                                                <div className="grid justify-items-end">
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            router.post(
                                                                `/hq/signups/${s.ulid}/resend`,
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Resend sign-in email
                                                    </Button>
                                                </div>
                                            )}
                                            {s.status === 'unfinished' && (
                                                <div className="grid justify-items-end gap-2">
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            router.post(
                                                                `/hq/signups/${s.ulid}/remind`,
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Send reminder
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            setOpen(
                                                                open === s.ulid
                                                                    ? null
                                                                    : s.ulid,
                                                            )
                                                        }
                                                    >
                                                        {open === s.ulid
                                                            ? 'Hide link'
                                                            : 'Link to finish'}
                                                    </Button>
                                                    {open === s.ulid &&
                                                        s.resume_url && (
                                                            <div className="max-w-xs">
                                                                <CopyText
                                                                    value={
                                                                        s.resume_url
                                                                    }
                                                                    label="link to finish signing up"
                                                                />
                                                            </div>
                                                        )}
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

HqSignups.layout = {
    breadcrumbs: [{ title: 'Sign-ups', href: '/hq/signups' }],
};
