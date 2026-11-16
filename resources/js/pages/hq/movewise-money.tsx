import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Revenue = {
    state: string;
    model: string;
    introductions: number;
    accepted: number;
    credits: number;
    refunded: number;
};

type Invoice = {
    ulid: string;
    number: string;
    account: string | null;
    partner: string;
    amount_cents: number;
    share_pct: number;
    commission_cents: number;
    status: string;
    due_on: string;
    overdue: boolean;
};

type Settlement = {
    id: number;
    partner: string;
    period: string;
    invoices: number;
    collected_cents: number;
    hq_pct: number;
    hq_cents: number;
    status: string;
};

type Props = {
    revenue: Revenue[];
    quality: {
        accepted: number;
        stopped: number;
        rating: number | null;
        ratings: number;
        closed: number;
    };
    invoices: Invoice[];
    settlements: Settlement[];
};

const MODELS: Record<string, string> = {
    connection: 'Per connection',
    appointment: 'Per appointment',
    share: 'Share at close',
};

const usd = (cents: number) =>
    `$${(cents / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

function InvoiceRow({ i }: { i: Invoice }) {
    const [note, setNote] = useState('');
    const mark = (status: string) =>
        router.post(
            `/hq/movewise/money/invoices/${i.ulid}`,
            { status, note },
            { preserveScroll: true },
        );

    return (
        <tr className="border-t">
            <td className="p-2">{i.number}</td>
            <td className="p-2">{i.account}</td>
            <td className="p-2">{i.partner}</td>
            <td className="p-2 text-right">
                {usd(i.amount_cents)}
                <span className="block text-xs text-muted-foreground">
                    {i.share_pct}% of {usd(i.commission_cents)}
                </span>
            </td>
            <td className="p-2">
                <Badge variant={i.status === 'paid' ? 'default' : 'outline'}>
                    {i.overdue ? 'overdue' : i.status}
                </Badge>
                <span className="block text-xs text-muted-foreground">
                    due {i.due_on}
                </span>
            </td>
            <td className="p-2">
                {i.status === 'issued' && (
                    <div className="flex flex-wrap gap-1">
                        <Button size="sm" onClick={() => mark('paid')}>
                            Paid
                        </Button>
                        <Input
                            aria-label="Reason to void"
                            className="h-8 w-36"
                            placeholder="Reason to void"
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                        />
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => mark('void')}
                        >
                            Void
                        </Button>
                    </div>
                )}
            </td>
        </tr>
    );
}

export default function HqMovewiseMoney({
    revenue,
    quality,
    invoices,
    settlements,
}: Props) {
    return (
        <>
            <Head title="Movewise money" />
            <div className="grid gap-6 p-4">
                <PageHeader
                    title="Movewise money"
                    description="The last 90 days: introductions and credits by state and model, referral invoices and partner settlements."
                />
                <section className="grid gap-2">
                    <h2 className="text-lg font-semibold">
                        By state and model
                    </h2>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-2">State</th>
                                    <th className="p-2">Model</th>
                                    <th className="p-2 text-right">
                                        Introductions
                                    </th>
                                    <th className="p-2 text-right">Accepted</th>
                                    <th className="p-2 text-right">
                                        Credits charged
                                    </th>
                                    <th className="p-2 text-right">Refunded</th>
                                </tr>
                            </thead>
                            <tbody>
                                {revenue.map((r) => (
                                    <tr
                                        key={`${r.state}${r.model}`}
                                        className="border-t"
                                    >
                                        <td className="p-2">{r.state}</td>
                                        <td className="p-2">
                                            {MODELS[r.model] ?? r.model}
                                        </td>
                                        <td className="p-2 text-right">
                                            {r.introductions}
                                        </td>
                                        <td className="p-2 text-right">
                                            {r.accepted}
                                        </td>
                                        <td className="p-2 text-right">
                                            {r.credits.toLocaleString()}
                                        </td>
                                        <td className="p-2 text-right">
                                            {r.refunded.toLocaleString()}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Quality: {quality.accepted} accepted, {quality.closed}{' '}
                        closed, {quality.stopped} stopped or changed agent,
                        rating{' '}
                        {quality.rating !== null
                            ? Number(quality.rating).toFixed(2)
                            : 'none yet'}{' '}
                        from {quality.ratings}.
                    </p>
                </section>
                <section className="grid gap-2">
                    <h2 className="text-lg font-semibold">Referral invoices</h2>
                    {invoices.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No referral invoices yet.
                        </p>
                    ) : (
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-2">Invoice</th>
                                        <th className="p-2">Brokerage</th>
                                        <th className="p-2">Partner</th>
                                        <th className="p-2 text-right">
                                            Amount
                                        </th>
                                        <th className="p-2">Status</th>
                                        <th className="p-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {invoices.map((i) => (
                                        <InvoiceRow key={i.ulid} i={i} />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
                <section className="grid gap-2">
                    <h2 className="text-lg font-semibold">
                        Partner settlements
                    </h2>
                    {settlements.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            Built on the 2nd of each month from the invoices
                            paid the month before.
                        </p>
                    )}
                    {settlements.map((s) => (
                        <div
                            key={s.id}
                            className="flex flex-wrap items-center gap-2 rounded-xl border p-3 text-sm"
                        >
                            <strong>{s.partner}</strong>
                            <span className="text-muted-foreground">
                                {s.period} · {s.invoices} invoices · collected{' '}
                                {usd(s.collected_cents)} · HQ {s.hq_pct}% ={' '}
                                {usd(s.hq_cents)}
                            </span>
                            <Badge
                                variant={
                                    s.status === 'settled'
                                        ? 'default'
                                        : 'outline'
                                }
                            >
                                {s.status}
                            </Badge>
                            <span className="grow" />
                            {s.status === 'open' && (
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            `/hq/movewise/money/settlements/${s.id}`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Mark settled
                                </Button>
                            )}
                        </div>
                    ))}
                </section>
            </div>
        </>
    );
}
