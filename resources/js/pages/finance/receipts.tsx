import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Receipt = {
    ulid: string;
    amount_cents: number;
    expected_cents: number;
    received_on: string;
    method: string;
    reference: string | null;
    status: string;
    resolution: string | null;
};
type DealRow = {
    ulid: string;
    street: string;
    status: string;
    closing_date: string | null;
    expected_cents: number;
    received_cents: number;
    settled: boolean;
    receipts: Receipt[];
};

const statusText: Record<string, string> = {
    matched: 'Matches the CDA',
    short: 'Short — held',
    over: 'Over — held',
    accepted: 'Difference accepted',
};

function Row({ deal }: { deal: DealRow }) {
    const form = useForm({
        amount: String((deal.expected_cents - deal.received_cents) / 100),
        received_on: new Date().toISOString().slice(0, 10),
        method: 'wire',
        reference: '',
    });
    const [note, setNote] = useState('');
    const problem = deal.receipts.find(
        (r) => r.status === 'short' || r.status === 'over',
    );

    return (
        <li className="grid gap-2 p-3 text-sm">
            <span className="flex flex-wrap items-center justify-between gap-2">
                <span className="font-medium">{deal.street}</span>
                <span>
                    Expected {usd(deal.expected_cents)} · received{' '}
                    {usd(deal.received_cents)}{' '}
                    {deal.settled && <Badge variant="secondary">Settled</Badge>}
                </span>
            </span>
            {deal.receipts.map((r) => (
                <span key={r.ulid} className="text-muted-foreground">
                    {usd(r.amount_cents)} by {r.method} on{' '}
                    {new Date(r.received_on).toLocaleDateString()}
                    {r.reference ? ` (${r.reference})` : ''} —{' '}
                    {statusText[r.status] ?? r.status}
                    {r.resolution ? `: ${r.resolution}` : ''}
                </span>
            ))}
            {problem && (
                <span className="flex flex-wrap gap-2">
                    <Input
                        aria-label="Why the difference is accepted"
                        placeholder="Title deducted a $25 wire fee; agreed with the owner"
                        className="max-w-md"
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                    />
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={note.length < 10}
                        onClick={() =>
                            router.post(
                                `/finance/receipts/${problem.ulid}/accept`,
                                { note },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Accept as final
                    </Button>
                </span>
            )}
            {!deal.settled && (
                <form
                    className="flex flex-wrap gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/finance/receipts/${deal.ulid}`, {
                            preserveScroll: true,
                        });
                    }}
                >
                    <Input
                        aria-label="Amount received ($)"
                        type="number"
                        step="0.01"
                        className="w-32"
                        value={form.data.amount}
                        onChange={(e) => form.setData('amount', e.target.value)}
                    />
                    <Input
                        aria-label="Received on"
                        type="date"
                        className="w-40"
                        value={form.data.received_on}
                        onChange={(e) =>
                            form.setData('received_on', e.target.value)
                        }
                    />
                    <select
                        aria-label="How it arrived"
                        className="rounded border bg-background px-2 py-1.5"
                        value={form.data.method}
                        onChange={(e) => form.setData('method', e.target.value)}
                    >
                        <option value="wire">Wire</option>
                        <option value="ach">ACH</option>
                        <option value="check">Check</option>
                    </select>
                    <Input
                        aria-label="Reference"
                        placeholder="Reference"
                        className="w-36"
                        value={form.data.reference}
                        onChange={(e) =>
                            form.setData('reference', e.target.value)
                        }
                    />
                    <Button type="submit" size="sm">
                        Record money received
                    </Button>
                </form>
            )}
        </li>
    );
}

/** Commission received from title, checked against each deal's CDA. */
export default function Receipts({
    show,
    deals,
}: {
    show: string;
    deals: DealRow[];
}) {
    return (
        <>
            <Head title="Money received" />
            <div className="flex max-w-4xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Money received"
                    description="Record what title paid. It's checked against the CDA; agents are paid only once it matches or a difference is accepted."
                />
                <div
                    role="group"
                    aria-label="Show"
                    className="flex w-fit rounded border text-sm"
                >
                    {[
                        ['open', 'Waiting'],
                        ['problems', 'Differences'],
                        ['all', 'All'],
                    ].map(([k, label]) => (
                        <button
                            key={k}
                            type="button"
                            aria-pressed={show === k}
                            className={`px-3 py-1.5 ${show === k ? 'bg-muted font-medium' : ''}`}
                            onClick={() =>
                                router.get('/finance/receipts', { show: k })
                            }
                        >
                            {label}
                        </button>
                    ))}
                </div>
                <ul className="divide-y rounded-lg border">
                    {deals.length === 0 && (
                        <li className="p-3 text-sm text-muted-foreground">
                            Nothing here.
                        </li>
                    )}
                    {deals.map((d) => (
                        <Row key={d.ulid} deal={d} />
                    ))}
                </ul>
            </div>
        </>
    );
}

Receipts.layout = {
    breadcrumbs: [{ title: 'Money received', href: '/finance/receipts' }],
};
