import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Stage = { key: string; label: string };
type Card = {
    ulid: string;
    name: string;
    brokerage: string | null;
    stage: string;
    source: string;
    owner: string | null;
    next_step: string | null;
    next_step_on: string | null;
    overdue: boolean;
    sides: number | null;
    volume_cents: number | null;
    trend: number | null;
};
type Alert = {
    id: number;
    recruit: string;
    name: string;
    kind: string;
    message: string;
    at: string;
};

const OPEN = ['prospect', 'talking', 'meeting', 'offer'];

const money = (cents: number) =>
    (cents / 100).toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
        notation: 'compact',
        maximumFractionDigits: 1,
    });

function RecruitCard({ r }: { r: Card }) {
    return (
        <li>
            <Link
                href={`/recruiting/${r.ulid}`}
                className="grid gap-1 rounded-lg border bg-background p-3 hover:border-primary focus-visible:outline-2"
            >
                <strong className="text-base">{r.name}</strong>
                {r.brokerage && (
                    <span className="text-sm text-muted-foreground">
                        {r.brokerage}
                    </span>
                )}
                {r.sides !== null && (
                    <span className="text-sm">
                        {r.sides} sides · {money(r.volume_cents ?? 0)}
                        {r.trend !== null && (
                            <span
                                className={
                                    r.trend >= 0
                                        ? 'text-emerald-700 dark:text-emerald-300'
                                        : 'text-amber-700 dark:text-amber-300'
                                }
                            >
                                {' '}
                                {r.trend >= 0 ? '▲' : '▼'} {Math.abs(r.trend)}%
                            </span>
                        )}
                    </span>
                )}
                {r.next_step && (
                    <span
                        className={`text-sm ${r.overdue ? 'font-medium text-red-700 dark:text-red-300' : 'text-muted-foreground'}`}
                    >
                        Next: {r.next_step}
                        {r.next_step_on && ` · ${r.next_step_on}`}
                    </span>
                )}
                {r.owner && (
                    <span className="text-xs text-muted-foreground">
                        {r.owner}
                    </span>
                )}
            </Link>
        </li>
    );
}

/** Recruiting pipeline (RC1): recruits by stage, alerts, and adding someone you met. */
export default function Recruiting({
    stages,
    recruits,
    alerts,
    due,
}: {
    stages: Stage[];
    recruits: Card[];
    alerts: Alert[];
    due: number;
}) {
    const [adding, setAdding] = useState(false);
    const form = useForm({
        name: '',
        email: '',
        phone: '',
        brokerage: '',
        source: 'referral',
    });

    return (
        <>
            <Head title="Recruiting" />
            <div className="grid gap-5 p-4 text-base">
                <PageHeader
                    title="Recruiting"
                    description={
                        due > 0
                            ? `${due} recruit(s) to follow up today.`
                            : 'Everyone you are recruiting, by stage.'
                    }
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/recruiting/agents">
                                    Find agents in the MLS
                                </Link>
                            </Button>
                            <Button onClick={() => setAdding(!adding)}>
                                Add someone
                            </Button>
                        </div>
                    }
                />

                {adding && (
                    <form
                        className="grid gap-3 rounded-lg border p-4 sm:grid-cols-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/recruiting');
                        }}
                    >
                        <label className="grid gap-1 text-sm">
                            Name
                            <Input
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                required
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Brokerage today
                            <Input
                                value={form.data.brokerage}
                                onChange={(e) =>
                                    form.setData('brokerage', e.target.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Email
                            <Input
                                type="email"
                                value={form.data.email}
                                onChange={(e) =>
                                    form.setData('email', e.target.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            Phone
                            <Input
                                inputMode="tel"
                                value={form.data.phone}
                                onChange={(e) =>
                                    form.setData('phone', e.target.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            How you know them
                            <select
                                className="h-10 rounded-md border bg-background px-3"
                                value={form.data.source}
                                onChange={(e) =>
                                    form.setData('source', e.target.value)
                                }
                            >
                                <option value="referral">
                                    Referred by someone
                                </option>
                                <option value="manual">Met them myself</option>
                            </select>
                        </label>
                        <div className="flex items-end">
                            <Button
                                disabled={form.processing || !form.data.name}
                            >
                                Add to recruiting
                            </Button>
                        </div>
                        {Object.values(form.errors).map((m) => (
                            <p
                                key={m}
                                className="text-sm text-red-700 sm:col-span-2"
                            >
                                {m}
                            </p>
                        ))}
                    </form>
                )}

                {alerts.length > 0 && (
                    <section
                        className="grid gap-2 rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30"
                        aria-labelledby="alerts"
                    >
                        <h2 id="alerts" className="font-semibold">
                            Alerts
                        </h2>
                        <ul className="grid gap-1">
                            {alerts.map((a) => (
                                <li
                                    key={a.id}
                                    className="flex flex-wrap items-center gap-2 text-sm"
                                >
                                    <Badge variant="outline">
                                        {a.kind === 'moved'
                                            ? 'Changed brokerage'
                                            : 'Production up'}
                                    </Badge>
                                    <Link
                                        className="underline"
                                        href={`/recruiting/${a.recruit}`}
                                    >
                                        {a.message}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {recruits.length === 0 ? (
                    <EmptyState
                        title="Nobody in recruiting yet"
                        description="Find producing agents in the MLS, or add someone you met."
                        action={
                            <Button asChild>
                                <Link href="/recruiting/agents">
                                    Find agents in the MLS
                                </Link>
                            </Button>
                        }
                    />
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        {stages
                            .filter((s) => OPEN.includes(s.key))
                            .map((s) => {
                                const list = recruits.filter(
                                    (r) => r.stage === s.key,
                                );

                                return (
                                    <section
                                        key={s.key}
                                        className="grid content-start gap-2 rounded-lg bg-muted/50 p-3"
                                        aria-label={s.label}
                                    >
                                        <h2 className="flex justify-between font-semibold">
                                            {s.label}{' '}
                                            <span className="text-muted-foreground">
                                                {list.length}
                                            </span>
                                        </h2>
                                        <ul className="grid gap-2">
                                            {list.map((r) => (
                                                <RecruitCard
                                                    key={r.ulid}
                                                    r={r}
                                                />
                                            ))}
                                        </ul>
                                    </section>
                                );
                            })}
                    </div>
                )}

                {stages
                    .filter((s) => !OPEN.includes(s.key))
                    .map((s) => {
                        const list = recruits.filter((r) => r.stage === s.key);

                        return list.length === 0 ? null : (
                            <details
                                key={s.key}
                                className="rounded-lg border p-3"
                            >
                                <summary className="cursor-pointer font-semibold">
                                    {s.label} in the last 90 days ({list.length}
                                    )
                                </summary>
                                <ul className="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    {list.map((r) => (
                                        <RecruitCard key={r.ulid} r={r} />
                                    ))}
                                </ul>
                            </details>
                        );
                    })}
            </div>
        </>
    );
}
