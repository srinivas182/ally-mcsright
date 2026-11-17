import { Head, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Week = {
    week_of: string;
    company_cents: number;
    gci_cents: number;
    deals: {
        ulid: string;
        street: string;
        agent: string;
        closing_date: string;
        company_cents: number;
        gci_cents: number;
        at_risk: boolean;
    }[];
};
type Props = {
    pending: {
        buckets: Record<
            string,
            {
                days: number;
                deals: number;
                company_cents: number;
                gci_cents: number;
            }
        >;
        agents: { name: string; deals: number; company_cents: number }[];
    };
    weekly: Week[];
    royalties: {
        from: string;
        to: string;
        rows: {
            closed_on: string;
            street: string;
            agent: string;
            gci_cents: number;
            fee: string;
            code: string;
            cents: number;
        }[];
        by_fee: { fee: string; code: string; deals: number; cents: number }[];
        total_cents: number;
        gci_cents: number;
        deals: number;
    };
    month: string;
};

/** LM1: money coming in from deals under contract (30/60/90 days and week by week), and the franchise and royalty report. */
export default function Money({ pending, weekly, royalties, month }: Props) {
    const peak = Math.max(1, ...weekly.map((w) => w.company_cents));

    return (
        <>
            <Head title="Money coming in" />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Money coming in"
                    description="Company dollar expected from deals under contract, by closing date, and what you owe your franchisor."
                    actions={
                        <Button variant="outline" asChild>
                            <a href="/deals/money/forecast.csv">
                                Download forecast (CSV)
                            </a>
                        </Button>
                    }
                />
                <section className="grid gap-3 sm:grid-cols-3">
                    {Object.values(pending.buckets).map((b) => (
                        <div key={b.days} className="rounded-lg border p-4">
                            <p className="text-muted-foreground">
                                Next {b.days} days
                            </p>
                            <p className="text-2xl font-semibold">
                                {usd(b.company_cents)}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {b.deals} {b.deals === 1 ? 'deal' : 'deals'} ·{' '}
                                {usd(b.gci_cents)} GCI
                            </p>
                        </div>
                    ))}
                </section>

                <section className="grid gap-2">
                    <h2 className="font-semibold">Week by week</h2>
                    <ul className="grid gap-2">
                        {weekly.map((w) => (
                            <li
                                key={w.week_of}
                                className="grid gap-1 rounded-lg border p-3 text-sm"
                            >
                                <div className="flex flex-wrap items-center gap-3">
                                    <span className="w-32 font-medium">
                                        Week of {w.week_of.slice(5)}
                                    </span>
                                    <div
                                        className="h-3 min-w-1 rounded bg-primary/70"
                                        style={{
                                            width: `${(w.company_cents / peak) * 50}%`,
                                        }}
                                        aria-hidden
                                    />
                                    <span className="font-semibold">
                                        {usd(w.company_cents)}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {w.deals.length}{' '}
                                        {w.deals.length === 1
                                            ? 'closing'
                                            : 'closings'}
                                    </span>
                                </div>
                                {w.deals.length > 0 && (
                                    <ul className="ml-4 grid gap-0.5 text-muted-foreground">
                                        {w.deals.map((d) => (
                                            <li key={d.ulid}>
                                                <a
                                                    className="underline"
                                                    href={`/deals/${d.ulid}`}
                                                >
                                                    {d.street}
                                                </a>{' '}
                                                · {d.agent} · {d.closing_date} ·{' '}
                                                {usd(d.company_cents)}{' '}
                                                {d.at_risk && (
                                                    <Badge variant="destructive">
                                                        Dates overdue
                                                    </Badge>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="grid gap-3 rounded-lg border p-4 text-sm">
                    <div className="flex flex-wrap items-end gap-3">
                        <h2 className="mr-auto font-semibold">
                            Franchise and royalty report
                        </h2>
                        <label className="grid gap-1">
                            Month
                            <Input
                                type="month"
                                value={month}
                                onChange={(e) =>
                                    router.get(
                                        '/deals/money',
                                        { month: e.target.value },
                                        { preserveScroll: true },
                                    )
                                }
                            />
                        </label>
                        <Button variant="outline" asChild>
                            <a
                                href={`/deals/money/royalties.csv?month=${month}`}
                            >
                                Download for your franchisor (CSV)
                            </a>
                        </Button>
                    </div>
                    <p className="text-muted-foreground">
                        Fees your commission plans say are payable to the
                        franchisor, on deals closed {royalties.from} to{' '}
                        {royalties.to}.
                    </p>
                    <p className="text-lg">
                        <strong>{usd(royalties.total_cents)}</strong> owed on{' '}
                        {royalties.deals}{' '}
                        {royalties.deals === 1 ? 'deal' : 'deals'} (
                        {usd(royalties.gci_cents)} GCI)
                    </p>
                    {royalties.by_fee.length > 0 ? (
                        <table className="w-full text-left">
                            <thead className="text-muted-foreground">
                                <tr>
                                    <th className="py-1 font-normal">Fee</th>
                                    <th className="py-1 font-normal">Deals</th>
                                    <th className="py-1 text-right font-normal">
                                        Amount
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {royalties.by_fee.map((f) => (
                                    <tr key={f.code} className="border-t">
                                        <td className="py-1">{f.fee}</td>
                                        <td className="py-1">{f.deals}</td>
                                        <td className="py-1 text-right font-medium">
                                            {usd(f.cents)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    ) : (
                        <p className="text-muted-foreground">
                            No franchisor fees on deals closed that month. If
                            you are a franchise, add your royalty to your
                            commission plans as a fee payable to the franchisor.
                        </p>
                    )}
                </section>
            </div>
        </>
    );
}
