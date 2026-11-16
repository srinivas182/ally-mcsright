import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Bar, BarChart, XAxis, YAxis } from 'recharts';

import { DealDrill, type DrillDeal } from '@/components/insights/deal-drill';
import { Button } from '@/components/ui/button';
import { ChartCard } from '@/components/ui/chart-card';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { StatCard } from '@/components/ui/stat-card';
import { usd } from '@/lib/cents';
import { getJson } from '@/lib/http';

type Totals = {
    sides: number;
    volume_cents: number;
    gci_cents: number | null;
    company_cents: number | null;
};
type Props = {
    range: { from: string; to: string };
    preset: string;
    money: boolean;
    scope: string;
    now: Totals;
    last_year: Totals;
    goal: {
        year: number;
        gci_cents: number | null;
        sides: number | null;
        volume_cents: number | null;
        ytd: Totals;
        year_share: number;
    } | null;
    months: {
        month: string;
        label: string;
        sides: number;
        volume_cents: number;
        gci_cents: number | null;
        company_cents: number | null;
    }[];
    pending: {
        deals: number;
        volume_cents: number;
        buckets: {
            days: number;
            deals: number;
            volume_cents: number;
            gci_cents: number | null;
            company_cents: number | null;
        }[];
    };
    agents: {
        ulid: string;
        name: string;
        sides: number;
        volume_cents: number;
        gci_cents: number | null;
    }[];
    people: { active: number; joined: number; left: number };
    sources:
        | {
              key: string;
              leads: number;
              closed: number;
              company_dollar_cents: number;
              spend_cents: number;
              return: number | null;
          }[]
        | null;
    canSetGoal: boolean;
};

const ranges: [string, string][] = [
    ['ytd', 'Year to date'],
    ['quarter', 'This quarter'],
    ['month', 'This month'],
    ['last12', 'Last 12 months'],
    ['last_year', 'Last year'],
];

const change = (a: number | null, b: number | null): number | undefined =>
    a === null || b === null || b === 0 ? undefined : ((a - b) / b) * 100;

/** The broker dashboard (specs/insights/01, K1): every number opens the deals behind it. */
export default function Numbers(props: Props) {
    const { now, last_year: ly, money } = props;
    const [drill, setDrill] = useState<{
        title: string;
        deals: DrillDeal[] | null;
    } | null>(null);
    const [goal, setGoal] = useState({
        gci: props.goal?.gci_cents ? String(props.goal.gci_cents / 100) : '',
        sides: props.goal?.sides ? String(props.goal.sides) : '',
    });
    const open = (title: string, query: Record<string, string>) => {
        setDrill({ title, deals: null });
        const q = new URLSearchParams({
            from: props.range.from,
            to: props.range.to,
            range: 'custom',
            ...query,
        });
        void getJson<{ deals: DrillDeal[] }>(`/insights/deals?${q.toString()}`)
            .then((r) => setDrill({ title, deals: r.deals }))
            .catch(() => setDrill({ title, deals: [] }));
    };
    const tile = (
        label: string,
        value: string,
        a: number | null,
        b: number | null,
    ) => (
        <button
            type="button"
            className="text-left"
            onClick={() => open(`Closed deals: ${label}`, { metric: 'closed' })}
        >
            <StatCard
                label={label}
                value={value}
                change={change(a, b)}
                comparison="vs same time last year"
            />
        </button>
    );

    return (
        <>
            <Head title="Numbers" />
            <div className="flex flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Numbers"
                    description={`Closed sales, pipeline and people for ${props.scope}, ${props.range.from} to ${props.range.to}. Click any number to see the deals behind it.`}
                />
                <div
                    className="flex flex-wrap gap-2"
                    role="group"
                    aria-label="Period"
                >
                    {ranges.map(([key, label]) => (
                        <Button
                            key={key}
                            size="sm"
                            variant={
                                props.preset === key ? 'default' : 'outline'
                            }
                            onClick={() =>
                                router.get(
                                    '/insights',
                                    { range: key },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {label}
                        </Button>
                    ))}
                </div>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {tile(
                        'Closed sides',
                        String(now.sides),
                        now.sides,
                        ly.sides,
                    )}
                    {tile(
                        'Volume',
                        usd(now.volume_cents),
                        now.volume_cents,
                        ly.volume_cents,
                    )}
                    {money &&
                        tile(
                            'GCI',
                            usd(now.gci_cents),
                            now.gci_cents,
                            ly.gci_cents,
                        )}
                    {money &&
                        tile(
                            'Company dollar',
                            usd(now.company_cents),
                            now.company_cents,
                            ly.company_cents,
                        )}
                </div>
                {!money && (
                    <p className="text-sm text-muted-foreground">
                        Commission figures are hidden for your role.
                    </p>
                )}
                {props.goal && (
                    <section className="grid gap-2 rounded-lg border p-4 text-sm">
                        <p className="font-medium">{props.goal.year} goal</p>
                        {props.goal.gci_cents || props.goal.sides ? (
                            <p>
                                {props.goal.gci_cents !== null &&
                                    props.goal.gci_cents > 0 && (
                                        <>
                                            GCI {usd(props.goal.ytd.gci_cents)}{' '}
                                            of {usd(props.goal.gci_cents)} (
                                            {Math.round(
                                                (100 *
                                                    (props.goal.ytd.gci_cents ??
                                                        0)) /
                                                    props.goal.gci_cents,
                                            )}
                                            %).{' '}
                                        </>
                                    )}
                                {props.goal.sides !== null &&
                                    props.goal.sides > 0 && (
                                        <>
                                            Sides {props.goal.ytd.sides} of{' '}
                                            {props.goal.sides}.{' '}
                                        </>
                                    )}
                                On pace means{' '}
                                {Math.round(props.goal.year_share * 100)}% of
                                the year's goal by today.
                            </p>
                        ) : (
                            <p className="text-muted-foreground">
                                No goal set yet.
                            </p>
                        )}
                        {props.canSetGoal && (
                            <span className="flex flex-wrap items-end gap-2">
                                <Input
                                    aria-label="Yearly GCI goal ($)"
                                    placeholder="GCI goal ($)"
                                    className="w-40"
                                    type="number"
                                    value={goal.gci}
                                    onChange={(e) =>
                                        setGoal({
                                            ...goal,
                                            gci: e.target.value,
                                        })
                                    }
                                />
                                <Input
                                    aria-label="Yearly closed sides goal"
                                    placeholder="Sides goal"
                                    className="w-32"
                                    type="number"
                                    value={goal.sides}
                                    onChange={(e) =>
                                        setGoal({
                                            ...goal,
                                            sides: e.target.value,
                                        })
                                    }
                                />
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            '/insights/goals',
                                            {
                                                for: 'brokerage',
                                                year:
                                                    props.goal?.year ??
                                                    new Date().getFullYear(),
                                                gci: goal.gci || null,
                                                sides: goal.sides || null,
                                            },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Save goal
                                </Button>
                            </span>
                        )}
                    </section>
                )}
                {drill && (
                    <DealDrill
                        title={drill.title}
                        deals={drill.deals}
                        onClose={() => setDrill(null)}
                    />
                )}
                <ChartCard
                    title="Closed by month"
                    description="The last 12 months. Click a bar for its deals."
                    table={{
                        headers: [
                            'Month',
                            'Sides',
                            'Volume',
                            ...(money ? ['GCI'] : []),
                        ],
                        rows: props.months.map((m) => [
                            m.month,
                            m.sides,
                            usd(m.volume_cents),
                            ...(money ? [usd(m.gci_cents)] : []),
                        ]),
                    }}
                >
                    <BarChart
                        data={props.months.map((m) => ({
                            ...m,
                            value: (money ? m.gci_cents : m.volume_cents) ?? 0,
                        }))}
                    >
                        <XAxis dataKey="label" />
                        <YAxis hide />
                        <Bar
                            dataKey="value"
                            fill="var(--color-primary)"
                            onClick={(d: { payload?: { month: string } }) =>
                                d.payload &&
                                open(`Closed in ${d.payload.month}`, {
                                    metric: 'closed',
                                    month: d.payload.month,
                                })
                            }
                        />
                    </BarChart>
                </ChartCard>
                <section className="grid gap-2 rounded-lg border p-4 text-sm">
                    <p className="font-medium">
                        Pending: {props.pending.deals} under contract (
                        {usd(props.pending.volume_cents)})
                    </p>
                    <div className="grid gap-2 sm:grid-cols-3">
                        {props.pending.buckets.map((b) => (
                            <button
                                key={b.days}
                                type="button"
                                className="grid gap-0.5 rounded-md border p-3 text-left hover:bg-muted"
                                onClick={() =>
                                    open(`Closing in the next ${b.days} days`, {
                                        metric: 'pending',
                                        within: String(b.days),
                                    })
                                }
                            >
                                <span className="text-muted-foreground">
                                    Next {b.days} days
                                </span>
                                <span className="font-medium">
                                    {b.deals} deals · {usd(b.volume_cents)}
                                </span>
                                {money && (
                                    <span className="text-muted-foreground">
                                        Company dollar expected{' '}
                                        {usd(b.company_cents)}
                                    </span>
                                )}
                            </button>
                        ))}
                    </div>
                </section>
                <div className="grid gap-4 lg:grid-cols-2">
                    <section className="grid content-start gap-2 rounded-lg border p-4 text-sm">
                        <p className="font-medium">Top agents</p>
                        {props.agents.length === 0 && (
                            <p className="text-muted-foreground">
                                No closings in this period.
                            </p>
                        )}
                        {props.agents.map((a) => (
                            <div
                                key={a.ulid}
                                className="flex flex-wrap items-center justify-between gap-2 border-t pt-2"
                            >
                                <Link
                                    href={`/insights/agents/${a.ulid}`}
                                    className="underline-offset-2 hover:underline"
                                >
                                    {a.name}
                                </Link>
                                <button
                                    type="button"
                                    className="text-muted-foreground hover:underline"
                                    onClick={() =>
                                        open(`${a.name}: closed deals`, {
                                            metric: 'closed',
                                            agent: a.ulid,
                                        })
                                    }
                                >
                                    {a.sides} sides ·{' '}
                                    {money
                                        ? usd(a.gci_cents)
                                        : usd(a.volume_cents)}
                                </button>
                            </div>
                        ))}
                    </section>
                    <section className="grid content-start gap-2 rounded-lg border p-4 text-sm">
                        <p className="font-medium">People</p>
                        <p>
                            {props.people.active} active agents ·{' '}
                            {props.people.joined} joined · {props.people.left}{' '}
                            left in this period
                        </p>
                        {props.sources && (
                            <>
                                <p className="mt-2 font-medium">
                                    Lead sources back to commission
                                </p>
                                {props.sources.map((s) => (
                                    <p
                                        key={s.key}
                                        className="flex justify-between gap-2 border-t pt-2"
                                    >
                                        <span className="capitalize">
                                            {s.key.replace('_', ' ')}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {s.leads} leads · {s.closed} closed
                                            · {usd(s.company_dollar_cents)}
                                            {s.return !== null &&
                                                ` · ${s.return}× spend`}
                                        </span>
                                    </p>
                                ))}
                                <Link
                                    href="/leads/report"
                                    className="underline"
                                >
                                    Full lead source report
                                </Link>
                            </>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

Numbers.layout = { breadcrumbs: [{ title: 'Numbers', href: '/insights' }] };
