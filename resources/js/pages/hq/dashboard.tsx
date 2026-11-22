import { Head, Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Legend,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

import { ChartCard } from '@/components/ui/chart-card';
import { PageHeader } from '@/components/ui/page-header';
import { StatCard } from '@/components/ui/stat-card';
import { usd } from '@/lib/cents';

type Kpis = {
    mrr_cents: number | null;
    paying_brokerages: number | null;
    paying_agents: number | null;
    live_brokerages: number | null;
    new_brokerages_month: number | null;
    new_brokerages_last_month: number | null;
    people: number | null;
    signups_30: number | null;
    health_checks_30: number | null;
    ai_requests_30: number | null;
    ai_cost_cents_30: number | null;
};
type Month = {
    month: string;
    label: string;
    brokerages: number;
    signups: number;
    health_checks: number;
};
type AiDay = {
    day: string;
    label: string;
    requests: number;
    cost_cents: number;
};
type Attention = { label: string; count: number; href: string };

const n = (v: number | null) => (v === null ? '—' : v.toLocaleString('en-US'));
const money = (v: number | null) => (v === null ? '—' : usd(v));

/** HQ2: the HQ console's home page: how the platform is doing, and what needs attention. */
export default function HqDashboard({
    kpis,
    months,
    ai_days,
    attention,
    at,
}: {
    kpis: Kpis;
    months: Month[];
    ai_days: AiDay[];
    attention: Attention[];
    at: string;
}) {
    const change =
        kpis.new_brokerages_month !== null && kpis.new_brokerages_last_month
            ? ((kpis.new_brokerages_month - kpis.new_brokerages_last_month) /
                  kpis.new_brokerages_last_month) *
              100
            : undefined;

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Dashboard"
                    description={`How RightAlly is doing across every brokerage. Totals only, no client details. Updated ${new Date(at).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}.`}
                />

                <section aria-labelledby="attention" className="grid gap-2">
                    <h2 id="attention" className="text-base font-semibold">
                        Needs your attention
                    </h2>
                    {attention.length === 0 ? (
                        <p className="rounded-xl border bg-card p-4 text-sm text-muted-foreground">
                            Nothing is waiting. Failed sign-ups, overdue
                            payments, call requests, support questions and
                            serious errors show here when there are any.
                        </p>
                    ) : (
                        <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            {attention.map((a) => (
                                <li key={a.label}>
                                    <Link
                                        href={a.href}
                                        className="flex items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-950/40"
                                    >
                                        <span>
                                            <span className="block text-2xl font-semibold">
                                                {a.count}
                                            </span>
                                            {a.label}
                                        </span>
                                        <ChevronRight
                                            aria-hidden="true"
                                            className="size-4 shrink-0"
                                        />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section aria-labelledby="money" className="grid gap-2">
                    <h2 id="money" className="text-base font-semibold">
                        Money
                    </h2>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <StatCard
                            label="Monthly recurring revenue"
                            value={money(kpis.mrr_cents)}
                        />
                        <StatCard
                            label="Paying brokerages"
                            value={n(kpis.paying_brokerages)}
                        />
                        <StatCard
                            label="Agents paying for a plan"
                            value={n(kpis.paying_agents)}
                        />
                    </div>
                </section>

                <section aria-labelledby="growth" className="grid gap-2">
                    <h2 id="growth" className="text-base font-semibold">
                        Growth
                    </h2>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard
                            label="Brokerages live"
                            value={n(kpis.live_brokerages)}
                        />
                        <StatCard
                            label="New brokerages this month"
                            value={n(kpis.new_brokerages_month)}
                            change={change}
                            comparison="vs last month"
                        />
                        <StatCard
                            label="Sign-ups, last 30 days"
                            value={n(kpis.signups_30)}
                        />
                        <StatCard
                            label="Health Checks, last 30 days"
                            value={n(kpis.health_checks_30)}
                        />
                    </div>
                    <ChartCard
                        title="Last 12 months"
                        description="New brokerages, online sign-ups and Health Checks each month."
                        table={{
                            headers: [
                                'Month',
                                'New brokerages',
                                'Sign-ups',
                                'Health Checks',
                            ],
                            rows: months.map((m) => [
                                m.month,
                                m.brokerages,
                                m.signups,
                                m.health_checks,
                            ]),
                        }}
                    >
                        <BarChart data={months}>
                            <CartesianGrid
                                vertical={false}
                                strokeOpacity={0.3}
                            />
                            <XAxis dataKey="label" tickLine={false} />
                            <YAxis
                                allowDecimals={false}
                                width={32}
                                tickLine={false}
                            />
                            <Tooltip />
                            <Legend />
                            <Bar
                                dataKey="brokerages"
                                name="New brokerages"
                                fill="var(--color-primary)"
                            />
                            <Bar
                                dataKey="signups"
                                name="Sign-ups"
                                fill="#0EA5A4"
                            />
                            <Bar
                                dataKey="health_checks"
                                name="Health Checks"
                                fill="#F59E0B"
                            />
                        </BarChart>
                    </ChartCard>
                </section>

                <section aria-labelledby="use" className="grid gap-2">
                    <h2 id="use" className="text-base font-semibold">
                        Use
                    </h2>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <StatCard
                            label="People with an account"
                            value={n(kpis.people)}
                        />
                        <StatCard
                            label="AI requests, last 30 days"
                            value={n(kpis.ai_requests_30)}
                        />
                        <StatCard
                            label="AI cost, last 30 days"
                            value={money(kpis.ai_cost_cents_30)}
                        />
                    </div>
                    <ChartCard
                        title="AI requests per day"
                        description="The last 30 days, across every brokerage. Cost per feature and brokerage is on AI cost."
                        table={{
                            headers: ['Day', 'Requests', 'Cost'],
                            rows: ai_days.map((d) => [
                                d.day,
                                d.requests,
                                usd(d.cost_cents),
                            ]),
                        }}
                        height={200}
                    >
                        <BarChart data={ai_days}>
                            <CartesianGrid
                                vertical={false}
                                strokeOpacity={0.3}
                            />
                            <XAxis
                                dataKey="label"
                                tickLine={false}
                                interval={4}
                            />
                            <YAxis
                                allowDecimals={false}
                                width={32}
                                tickLine={false}
                            />
                            <Tooltip />
                            <Bar
                                dataKey="requests"
                                name="Requests"
                                fill="var(--color-primary)"
                            />
                        </BarChart>
                    </ChartCard>
                </section>
            </div>
        </>
    );
}

HqDashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: '/hq/dashboard' }],
};
