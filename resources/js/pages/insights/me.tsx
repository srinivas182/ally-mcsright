import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { StatCard } from '@/components/ui/stat-card';
import { usd } from '@/lib/cents';

type Totals = {
    sides: number;
    volume_cents: number;
    gci_cents: number | null;
    company_cents: number | null;
};
type Props = {
    agent: { ulid: string; name: string };
    isSelf: boolean;
    year: number;
    ytd: Totals;
    last_year: Totals;
    goal: {
        gci_cents: number | null;
        sides: number | null;
        volume_cents: number | null;
        year_share: number;
    };
    cap: {
        plan: string;
        cap_cents: number | null;
        paid_cents: number;
        to_cap_cents: number | null;
        capped: boolean;
    } | null;
    speed: {
        leads: number;
        median_minutes: number | null;
        within_target: number | null;
    } | null;
    habits: {
        overdue_tasks: number;
        check_ins_due: number;
        leads_waiting: number;
    };
    pipeline: {
        ulid: string;
        street: string;
        status: string;
        closing_date: string | null;
        price_cents: number;
        gci_cents: number | null;
    }[];
    week: { text: string; href: string; urgent: boolean }[];
};

const pct = (a: number | null, b: number | null): number | undefined =>
    a === null || b === null || b === 0 ? undefined : ((a - b) / b) * 100;

/** An agent's success dashboard (specs/insights/01, K1). */
export default function MyNumbers(p: Props) {
    const [gci, setGci] = useState(
        p.goal.gci_cents ? String(p.goal.gci_cents / 100) : '',
    );
    const [sides, setSides] = useState(
        p.goal.sides ? String(p.goal.sides) : '',
    );
    const pace = (goal: number | null, actual: number | null) =>
        goal === null || goal === 0 || actual === null
            ? null
            : actual >= goal * p.goal.year_share
              ? 'On pace'
              : 'Behind pace';

    return (
        <>
            <Head title={p.isSelf ? 'My numbers' : p.agent.name} />
            <div className="flex flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title={p.isSelf ? 'My numbers' : p.agent.name}
                    description={`${p.year} so far, against ${p.isSelf ? 'your' : 'their'} goal and last year.`}
                />
                <section className="grid gap-2 rounded-lg border p-4 text-sm">
                    <p className="font-medium">This week</p>
                    {p.week.length === 0 ? (
                        <p className="text-muted-foreground">
                            Nothing urgent. A good week to call your sphere.
                        </p>
                    ) : (
                        <ul className="grid gap-1">
                            {p.week.map((w) => (
                                <li
                                    key={w.text}
                                    className="flex items-center gap-2"
                                >
                                    {w.urgent && (
                                        <Badge variant="destructive">Now</Badge>
                                    )}
                                    <Link
                                        href={w.href}
                                        className="underline-offset-2 hover:underline"
                                    >
                                        {w.text}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
                <div className="grid gap-3 sm:grid-cols-3">
                    <StatCard
                        label="Closed sides"
                        value={`${p.ytd.sides}${p.goal.sides ? ` of ${p.goal.sides}` : ''}`}
                        change={pct(p.ytd.sides, p.last_year.sides)}
                        comparison="vs this time last year"
                    />
                    <StatCard
                        label="Volume"
                        value={usd(p.ytd.volume_cents)}
                        change={pct(
                            p.ytd.volume_cents,
                            p.last_year.volume_cents,
                        )}
                        comparison="vs this time last year"
                    />
                    <StatCard
                        label="GCI"
                        value={`${usd(p.ytd.gci_cents)}${p.goal.gci_cents ? ` of ${usd(p.goal.gci_cents)}` : ''}`}
                        change={pct(p.ytd.gci_cents, p.last_year.gci_cents)}
                        comparison="vs this time last year"
                    />
                </div>
                {(pace(p.goal.gci_cents, p.ytd.gci_cents) ??
                    pace(p.goal.sides, p.ytd.sides)) && (
                    <p className="text-sm">
                        {pace(p.goal.gci_cents, p.ytd.gci_cents) ??
                            pace(p.goal.sides, p.ytd.sides)}
                        : by today, the goal pace is{' '}
                        {Math.round(p.goal.year_share * 100)}% of the year.
                    </p>
                )}
                {p.isSelf && (
                    <span className="flex flex-wrap items-end gap-2 text-sm">
                        <Input
                            aria-label="My yearly GCI goal ($)"
                            placeholder="GCI goal ($)"
                            className="w-40"
                            type="number"
                            value={gci}
                            onChange={(e) => setGci(e.target.value)}
                        />
                        <Input
                            aria-label="My yearly closings goal"
                            placeholder="Closings goal"
                            className="w-32"
                            type="number"
                            value={sides}
                            onChange={(e) => setSides(e.target.value)}
                        />
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    '/insights/goals',
                                    {
                                        for: 'me',
                                        year: p.year,
                                        gci: gci || null,
                                        sides: sides || null,
                                    },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Save my goal
                        </Button>
                    </span>
                )}
                <div className="grid gap-4 lg:grid-cols-2">
                    <section className="grid content-start gap-2 rounded-lg border p-4 text-sm">
                        <p className="font-medium">Cap</p>
                        {p.cap === null || p.cap.cap_cents === null ? (
                            <p className="text-muted-foreground">
                                No cap on this plan.
                            </p>
                        ) : p.cap.capped ? (
                            <p>Capped for this period. Well done.</p>
                        ) : (
                            <>
                                <p>
                                    {usd(p.cap.paid_cents)} of{' '}
                                    {usd(p.cap.cap_cents)} paid toward the cap (
                                    {usd(p.cap.to_cap_cents)} to go).
                                </p>
                                <div
                                    className="h-2 rounded bg-muted"
                                    aria-hidden="true"
                                >
                                    <div
                                        className="h-2 rounded bg-primary"
                                        style={{
                                            width: `${Math.min(100, (100 * p.cap.paid_cents) / p.cap.cap_cents)}%`,
                                        }}
                                    />
                                </div>
                            </>
                        )}
                        <p className="mt-2 font-medium">Habits</p>
                        <p>
                            {p.speed && p.speed.leads > 0
                                ? `Speed to lead: median ${p.speed.median_minutes ?? '—'} minutes, ${p.speed.within_target ?? 0}% within target (last 90 days).`
                                : 'No new leads in the last 90 days.'}
                        </p>
                        <p>
                            {p.habits.overdue_tasks} overdue tasks ·{' '}
                            {p.habits.check_ins_due} check-ins due ·{' '}
                            {p.habits.leads_waiting} leads waiting
                        </p>
                    </section>
                    <section className="grid content-start gap-2 rounded-lg border p-4 text-sm">
                        <p className="font-medium">On the way</p>
                        {p.pipeline.length === 0 && (
                            <p className="text-muted-foreground">
                                No open deals.
                            </p>
                        )}
                        {p.pipeline.map((d) => (
                            <p
                                key={d.ulid}
                                className="flex justify-between gap-2 border-t pt-2"
                            >
                                <Link
                                    href={`/deals/${d.ulid}`}
                                    className="underline-offset-2 hover:underline"
                                >
                                    {d.street}
                                </Link>
                                <span className="text-muted-foreground">
                                    {d.status === 'under_contract'
                                        ? 'Under contract'
                                        : 'Before contract'}
                                    {d.closing_date
                                        ? ` · closing ${d.closing_date}`
                                        : ''}{' '}
                                    · {usd(d.price_cents)}
                                </span>
                            </p>
                        ))}
                    </section>
                </div>
            </div>
        </>
    );
}

MyNumbers.layout = {
    breadcrumbs: [{ title: 'My numbers', href: '/insights/me' }],
};
