import { Head } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Check = {
    key: string;
    label: string;
    count: number;
    fix: string;
    href: string;
};
type Report = {
    health: { score: number; total: number; checks: Check[]; to_fix: number };
    forecast: {
        buckets: { days: number; low_cents: number; high_cents: number }[];
        reasons: string[];
        average_gci_cents: number;
    };
    value: {
        people: number;
        deals_a_year: number;
        value_cents: number;
        rate: number;
        from_history: boolean;
        basis: string;
    };
    proof: { group: string; deals: number; gci_cents: number }[];
};

const usd = (cents: number) =>
    (cents / 100).toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 0,
    });

/** The database report: health with one-click fixes, the commission forecast, what the database is worth, and proof. */
export default function DatabaseReport({
    broker,
    report,
    agents,
}: {
    broker: boolean;
    report: Report;
    agents: {
        name: string;
        score: number;
        value_cents: number;
        forecast_high_cents: number;
    }[];
}) {
    const { health, forecast, value, proof } = report;
    const tone =
        health.score >= 90
            ? 'border-emerald-500'
            : health.score >= 70
              ? 'border-amber-400'
              : 'border-red-400';
    const proofTotal = proof.reduce((n, p) => n + p.gci_cents, 0);

    return (
        <>
            <Head title="Database" />
            <div className="grid gap-5 p-4 text-base">
                <PageHeader
                    title={broker ? 'Brokerage database' : 'My database'}
                    description="How healthy your database is, what it is likely to earn soon, and what it is worth."
                />

                <section
                    className="grid gap-3 rounded-lg border p-4"
                    aria-labelledby="health"
                >
                    <div className="flex flex-wrap items-center gap-4">
                        <div
                            className={`grid size-20 place-items-center rounded-full border-8 text-xl font-semibold ${tone}`}
                            aria-label={`Database health ${health.score}%`}
                        >
                            {health.score}%
                        </div>
                        <div>
                            <h2 id="health" className="text-lg font-semibold">
                                Database health
                            </h2>
                            <p className="text-muted-foreground">
                                {health.total.toLocaleString()} people ·{' '}
                                {health.to_fix === 0
                                    ? 'nothing to fix'
                                    : `${health.to_fix} thing(s) to fix`}
                            </p>
                        </div>
                    </div>
                    <ul className="grid gap-2">
                        {health.checks.map((c) => (
                            <li
                                key={c.key}
                                className="flex flex-wrap items-center gap-3 rounded border p-3"
                            >
                                <span className="min-w-0 flex-1">
                                    <strong>{c.label}</strong>{' '}
                                    <span
                                        className={
                                            c.count > 0
                                                ? 'text-amber-700 dark:text-amber-300'
                                                : 'text-emerald-700 dark:text-emerald-300'
                                        }
                                    >
                                        {c.count > 0
                                            ? c.count.toLocaleString()
                                            : '✓ none'}
                                    </span>
                                </span>
                                {c.count > 0 && (
                                    <Button variant="outline" asChild>
                                        <a href={c.href}>{c.fix}</a>
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>

                <section
                    className="grid gap-3 rounded-lg border p-4"
                    aria-labelledby="forecast"
                >
                    <h2 id="forecast" className="text-lg font-semibold">
                        Commission forecast
                    </h2>
                    <div className="grid gap-3 sm:grid-cols-3">
                        {forecast.buckets.map((b) => (
                            <div key={b.days} className="rounded border p-3">
                                <p className="text-sm text-muted-foreground">
                                    Next {b.days} days
                                </p>
                                <p className="text-lg font-semibold">
                                    {usd(b.low_cents)} – {usd(b.high_cents)}
                                </p>
                            </div>
                        ))}
                    </div>
                    <ul className="list-disc pl-5 text-sm text-muted-foreground">
                        {forecast.reasons.map((r) => (
                            <li key={r}>{r}</li>
                        ))}
                    </ul>
                </section>

                <section
                    className="grid gap-2 rounded-lg border p-4"
                    aria-labelledby="value"
                >
                    <h2 id="value" className="text-lg font-semibold">
                        What your database is worth
                    </h2>
                    <p>
                        <strong className="text-lg">
                            {usd(value.value_cents)}
                        </strong>{' '}
                        a year in repeat and referral business from{' '}
                        {value.people.toLocaleString()} past clients and sphere
                        (about {value.deals_a_year} deals).
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {value.basis}
                    </p>
                </section>

                <section
                    className="grid gap-2 rounded-lg border p-4"
                    aria-labelledby="proof"
                >
                    <h2 id="proof" className="text-lg font-semibold">
                        Where the last 12 months' business came from
                    </h2>
                    <ul className="grid gap-2">
                        {proof.map((p) => (
                            <li key={p.group} className="grid gap-1">
                                <span className="flex justify-between gap-3 text-sm">
                                    <span>{p.group}</span>
                                    <span>
                                        {p.deals} deal(s) · {usd(p.gci_cents)}
                                    </span>
                                </span>
                                <span className="h-2 overflow-hidden rounded-full bg-muted">
                                    <span
                                        className="block h-full bg-primary"
                                        style={{
                                            width: `${proofTotal > 0 ? (100 * p.gci_cents) / proofTotal : 0}%`,
                                        }}
                                    />
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>

                {broker && agents.length > 0 && (
                    <section
                        className="grid gap-2 rounded-lg border p-4"
                        aria-labelledby="agents"
                    >
                        <h2 id="agents" className="text-lg font-semibold">
                            By agent
                        </h2>
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="text-muted-foreground">
                                    <tr>
                                        <th className="py-2 font-normal">
                                            Agent
                                        </th>
                                        <th className="py-2 font-normal">
                                            Health
                                        </th>
                                        <th className="py-2 font-normal">
                                            Database value / year
                                        </th>
                                        <th className="py-2 font-normal">
                                            Up to, next 90 days
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {agents.map((a) => (
                                        <tr key={a.name} className="border-t">
                                            <td className="py-2">{a.name}</td>
                                            <td className="py-2">{a.score}%</td>
                                            <td className="py-2">
                                                {usd(a.value_cents)}
                                            </td>
                                            <td className="py-2">
                                                {usd(a.forecast_high_cents)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}
