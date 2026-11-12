import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Line = {
    agent: string;
    plan: string | null;
    price_cents?: number;
    floor_cents?: number;
    commission_cents?: number;
    markup_cents?: number;
    adjustment_cents?: number;
};
type Statement = {
    ulid: string;
    month: string;
    paying_agents: number;
    commission_cents: number;
    markup_cents: number;
    adjustments_cents: number;
    carried_in_cents: number;
    payable_cents: number;
    status: string;
    lines: Line[];
};
type Props = {
    connect: { account: boolean; ready: boolean };
    byPlan: { plan: string | null; agents: number; monthly_cents: number }[];
    thisMonth: {
        collected_cents: number;
        commission_cents: number;
        markup_cents: number;
        percent: string;
    };
    statements: Statement[];
    minimum_cents: number;
};

const statusLabel: Record<string, string> = {
    open: 'Ready',
    paid: 'Paid',
    carried: 'Carried to next month',
    rolled: 'Included in a later month',
};

/** What the brokerage earns from its agents paying RightAlly, and its payouts. */
export default function Partner({
    connect,
    byPlan,
    thisMonth,
    statements,
    minimum_cents,
}: Props) {
    const [open, setOpen] = useState<string | null>(null);

    return (
        <>
            <Head title="Partner earnings" />
            <div className="flex max-w-4xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Partner earnings"
                    description={`You earn ${thisMonth.percent}% of the floor price plus all of your markup, for every agent who pays. Paid monthly.`}
                />
                <section className="flex flex-wrap items-center gap-3 rounded-lg border p-4 text-sm">
                    <span className="font-medium">Payouts</span>
                    {connect.ready ? (
                        <Badge variant="secondary">Ready</Badge>
                    ) : (
                        <Badge variant="outline">Not set up</Badge>
                    )}
                    {!connect.ready && (
                        <Button
                            size="sm"
                            onClick={() =>
                                router.post('/settings/partner/connect')
                            }
                        >
                            {connect.account
                                ? 'Finish setting up payouts'
                                : 'Connect with Stripe'}
                        </Button>
                    )}
                    <span className="text-muted-foreground">
                        Payouts go out monthly from {usd(minimum_cents)};
                        smaller amounts roll into the next month.
                    </span>
                </section>
                <section className="grid gap-3 md:grid-cols-3">
                    <div className="rounded-lg border p-4 text-sm">
                        <p className="text-muted-foreground">
                            This month so far
                        </p>
                        <p className="text-2xl font-semibold">
                            {usd(
                                thisMonth.commission_cents +
                                    thisMonth.markup_cents,
                            )}
                        </p>
                    </div>
                    <div className="rounded-lg border p-4 text-sm">
                        <p className="text-muted-foreground">Commission</p>
                        <p className="text-2xl font-semibold">
                            {usd(thisMonth.commission_cents)}
                        </p>
                    </div>
                    <div className="rounded-lg border p-4 text-sm">
                        <p className="text-muted-foreground">Markup</p>
                        <p className="text-2xl font-semibold">
                            {usd(thisMonth.markup_cents)}
                        </p>
                    </div>
                </section>
                <ul className="divide-y rounded-lg border text-sm">
                    {byPlan.length === 0 && (
                        <li className="p-3 text-muted-foreground">
                            No paying agents yet.
                        </li>
                    )}
                    {byPlan.map((p) => (
                        <li key={p.plan} className="flex justify-between p-3">
                            <span>{p.plan}</span>
                            <span className="text-muted-foreground">
                                {p.agents} agent(s) · {usd(p.monthly_cents)} a
                                month
                            </span>
                        </li>
                    ))}
                </ul>
                <section className="grid gap-2">
                    <h2 className="font-semibold">Statements</h2>
                    {statements.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            Your first statement arrives after your first full
                            month.
                        </p>
                    )}
                    {statements.map((s) => (
                        <div key={s.ulid} className="rounded-lg border text-sm">
                            <button
                                type="button"
                                aria-expanded={open === s.ulid}
                                className="flex w-full flex-wrap items-center justify-between gap-2 p-3 text-left"
                                onClick={() =>
                                    setOpen(open === s.ulid ? null : s.ulid)
                                }
                            >
                                <span className="font-medium">{s.month}</span>
                                <span>
                                    {usd(s.payable_cents)} · {s.paying_agents}{' '}
                                    paying agent(s)
                                </span>
                                <Badge
                                    variant={
                                        s.status === 'paid'
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                >
                                    {statusLabel[s.status] ?? s.status}
                                </Badge>
                            </button>
                            {open === s.ulid && (
                                <div className="overflow-x-auto border-t p-3">
                                    <table className="w-full">
                                        <thead className="text-left text-muted-foreground">
                                            <tr>
                                                <th className="p-1">Agent</th>
                                                <th className="p-1">Plan</th>
                                                <th className="p-1">Price</th>
                                                <th className="p-1">
                                                    Commission
                                                </th>
                                                <th className="p-1">Markup</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {s.lines.map((l, i) => (
                                                <tr
                                                    key={i}
                                                    className="border-t"
                                                >
                                                    <td className="p-1">
                                                        {l.agent}
                                                    </td>
                                                    <td className="p-1">
                                                        {l.plan}
                                                    </td>
                                                    <td className="p-1">
                                                        {usd(l.price_cents)}
                                                    </td>
                                                    <td className="p-1">
                                                        {l.adjustment_cents !==
                                                        undefined
                                                            ? usd(
                                                                  l.adjustment_cents,
                                                              )
                                                            : usd(
                                                                  l.commission_cents,
                                                              )}
                                                    </td>
                                                    <td className="p-1">
                                                        {usd(l.markup_cents)}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                    <p className="mt-2 text-muted-foreground">
                                        Commission {usd(s.commission_cents)} +
                                        markup {usd(s.markup_cents)}{' '}
                                        {s.adjustments_cents
                                            ? `− refunds ${usd(-s.adjustments_cents)} `
                                            : ''}
                                        {s.carried_in_cents
                                            ? `+ carried in ${usd(s.carried_in_cents)} `
                                            : ''}
                                        = {usd(s.payable_cents)}
                                    </p>
                                </div>
                            )}
                        </div>
                    ))}
                </section>
            </div>
        </>
    );
}

Partner.layout = {
    breadcrumbs: [{ title: 'Partner earnings', href: '/settings/partner' }],
};
