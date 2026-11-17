import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Row = { code: string; name: string; cents: number; prior_cents?: number };
type Props = {
    pnl: {
        from: string;
        to: string;
        revenue: Row[];
        expenses: Row[];
        revenue_cents: number;
        expense_cents: number;
        net_cents: number;
        prior_revenue_cents: number;
        prior_expense_cents: number;
        prior_net_cents: number;
    };
    balance: {
        as_of: string;
        assets: Row[];
        liabilities: Row[];
        equity: Row[];
        assets_cents: number;
        liabilities_cents: number;
        equity_cents: number;
        balanced: boolean;
    };
    preset: string;
};

const presets: Record<string, string> = {
    ytd: 'This year so far',
    last_month: 'Last month',
    last_quarter: 'Last quarter',
    last_year: 'Last year',
    custom: 'Choose dates',
};

/** AC2: profit and loss beside last year, and the balance sheet, from the books. */
export default function Statements({ pnl, balance, preset }: Props) {
    const [tab, setTab] = useState<'pnl' | 'balance'>('pnl');
    const [from, setFrom] = useState(pnl.from);
    const [to, setTo] = useState(pnl.to);
    const query =
        preset === 'custom'
            ? `preset=custom&from=${pnl.from}&to=${pnl.to}`
            : `preset=${preset}`;

    return (
        <>
            <Head title="Profit and loss" />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Profit and loss, and balance sheet"
                    description="Straight from the books, with the same period last year beside it. Download for your accountant or lender."
                    actions={
                        <div className="flex gap-2">
                            <Button variant="outline" asChild>
                                <a
                                    href={`/finance/statements.pdf?${query}`}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    PDF
                                </a>
                            </Button>
                            <Button variant="outline" asChild>
                                <a href={`/finance/statements.csv?${query}`}>
                                    CSV
                                </a>
                            </Button>
                        </div>
                    }
                />
                <div className="flex flex-wrap items-end gap-2">
                    {Object.entries(presets).map(([k, label]) => (
                        <Button
                            key={k}
                            size="sm"
                            variant={preset === k ? 'default' : 'outline'}
                            onClick={() =>
                                k === 'custom'
                                    ? router.get('/finance/statements', {
                                          preset: 'custom',
                                          from,
                                          to,
                                      })
                                    : router.get('/finance/statements', {
                                          preset: k,
                                      })
                            }
                        >
                            {label}
                        </Button>
                    ))}
                    {preset === 'custom' && (
                        <>
                            <Input
                                aria-label="From"
                                type="date"
                                className="w-40"
                                value={from}
                                onChange={(e) => setFrom(e.target.value)}
                            />
                            <Input
                                aria-label="To"
                                type="date"
                                className="w-40"
                                value={to}
                                onChange={(e) => setTo(e.target.value)}
                            />
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.get('/finance/statements', {
                                        preset: 'custom',
                                        from,
                                        to,
                                    })
                                }
                            >
                                Show
                            </Button>
                        </>
                    )}
                </div>
                <div className="flex gap-2" role="tablist">
                    <Button
                        role="tab"
                        aria-selected={tab === 'pnl'}
                        variant={tab === 'pnl' ? 'default' : 'ghost'}
                        onClick={() => setTab('pnl')}
                    >
                        Profit and loss
                    </Button>
                    <Button
                        role="tab"
                        aria-selected={tab === 'balance'}
                        variant={tab === 'balance' ? 'default' : 'ghost'}
                        onClick={() => setTab('balance')}
                    >
                        Balance sheet
                    </Button>
                </div>
                {tab === 'pnl' ? (
                    <section className="grid gap-4">
                        <p className="text-muted-foreground">
                            {pnl.from} to {pnl.to}
                        </p>
                        <div className="grid gap-3 sm:grid-cols-3">
                            <Big
                                label="Income"
                                cents={pnl.revenue_cents}
                                prior={pnl.prior_revenue_cents}
                            />
                            <Big
                                label="Expenses"
                                cents={pnl.expense_cents}
                                prior={pnl.prior_expense_cents}
                            />
                            <Big
                                label="Net profit"
                                cents={pnl.net_cents}
                                prior={pnl.prior_net_cents}
                            />
                        </div>
                        <Table
                            title="Income"
                            rows={pnl.revenue}
                            total={pnl.revenue_cents}
                            prior
                        />
                        <Table
                            title="Expenses"
                            rows={pnl.expenses}
                            total={pnl.expense_cents}
                            prior
                        />
                    </section>
                ) : (
                    <section className="grid gap-4">
                        <p className="text-muted-foreground">
                            As of {balance.as_of}
                        </p>
                        {!balance.balanced && (
                            <p className="rounded-md border border-destructive p-3 text-destructive">
                                The books don&rsquo;t balance. Please contact
                                support.
                            </p>
                        )}
                        <Table
                            title="Assets"
                            rows={balance.assets}
                            total={balance.assets_cents}
                        />
                        <Table
                            title="Liabilities"
                            rows={balance.liabilities}
                            total={balance.liabilities_cents}
                        />
                        <Table
                            title="Equity"
                            rows={balance.equity}
                            total={balance.equity_cents}
                        />
                        <p className="font-medium">
                            Liabilities and equity:{' '}
                            {usd(
                                balance.liabilities_cents +
                                    balance.equity_cents,
                            )}
                        </p>
                    </section>
                )}
            </div>
        </>
    );
}

function Big({
    label,
    cents,
    prior,
}: {
    label: string;
    cents: number;
    prior: number;
}) {
    const diff = cents - prior;

    return (
        <div className="rounded-lg border p-4">
            <p className="text-muted-foreground">{label}</p>
            <p className="text-2xl font-semibold">{usd(cents)}</p>
            <p className="text-sm text-muted-foreground">
                {prior === 0 && cents === 0
                    ? 'Nothing yet'
                    : `${diff >= 0 ? 'Up' : 'Down'} ${usd(Math.abs(diff))} on last year`}
            </p>
        </div>
    );
}

function Table({
    title,
    rows,
    total,
    prior,
}: {
    title: string;
    rows: Row[];
    total: number;
    prior?: boolean;
}) {
    return (
        <table className="w-full text-left">
            <thead>
                <tr className="border-b">
                    <th className="py-2 font-semibold">{title}</th>
                    <th className="py-2 text-right font-normal text-muted-foreground">
                        This period
                    </th>
                    {prior && (
                        <th className="py-2 text-right font-normal text-muted-foreground">
                            Last year
                        </th>
                    )}
                </tr>
            </thead>
            <tbody>
                {rows.length === 0 && (
                    <tr>
                        <td
                            className="py-2 text-muted-foreground"
                            colSpan={prior ? 3 : 2}
                        >
                            Nothing yet.
                        </td>
                    </tr>
                )}
                {rows.map((r) => (
                    <tr key={r.code + r.name} className="border-b">
                        <td className="py-1.5">
                            {[r.code, r.name].filter(Boolean).join(' ')}
                        </td>
                        <td className="py-1.5 text-right">{usd(r.cents)}</td>
                        {prior && (
                            <td className="py-1.5 text-right text-muted-foreground">
                                {usd(r.prior_cents ?? 0)}
                            </td>
                        )}
                    </tr>
                ))}
                <tr className="font-semibold">
                    <td className="py-2">Total {title.toLowerCase()}</td>
                    <td className="py-2 text-right">{usd(total)}</td>
                    {prior && <td />}
                </tr>
            </tbody>
        </table>
    );
}
