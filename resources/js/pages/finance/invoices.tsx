import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Invoice = {
    ulid: string;
    number: number;
    customer: string;
    issue_date: string;
    due_date: string;
    total_cents: number;
    status: string;
    paid_on: string | null;
    overdue: boolean;
};
type Props = {
    invoices: Invoice[];
    open_cents: number;
    overdue_cents: number;
    accounts: { code: string; name: string }[];
};

const today = () => new Date().toISOString().slice(0, 10);
const inDays = (n: number) =>
    new Date(Date.now() + n * 86400000).toISOString().slice(0, 10);

/** AC1: invoices to builders, relocation companies, sponsors: issued, sent as a PDF, marked paid. */
export default function Invoices({
    invoices,
    open_cents,
    overdue_cents,
    accounts,
}: Props) {
    const form = useForm({
        customer: '',
        customer_email: '',
        customer_address: '',
        issue_date: today(),
        due_date: inDays(30),
        account_code:
            accounts.find((a) => a.code === '4200')?.code ??
            accounts[0]?.code ??
            '',
        memo: '',
        lines: [{ description: '', amount: '' }],
    });
    const setLine = (i: number, key: 'description' | 'amount', value: string) =>
        form.setData(
            'lines',
            form.data.lines.map((l, j) =>
                j === i ? { ...l, [key]: value } : l,
            ),
        );

    return (
        <>
            <Head title="Invoices" />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Invoices"
                    description="Money owed to the brokerage by others: a builder's co-op, a relocation company's referral fee, a sponsor. Agents' fees are billed in Agent billing."
                />
                <section className="grid gap-3 sm:grid-cols-2">
                    <div className="rounded-lg border p-4">
                        <p className="text-muted-foreground">
                            Waiting to be paid
                        </p>
                        <p className="text-2xl font-semibold">
                            {usd(open_cents)}
                        </p>
                    </div>
                    <div className="rounded-lg border p-4">
                        <p className="text-muted-foreground">Overdue</p>
                        <p
                            className={`text-2xl font-semibold ${overdue_cents > 0 ? 'text-destructive' : ''}`}
                        >
                            {usd(overdue_cents)}
                        </p>
                    </div>
                </section>
                <form
                    className="grid gap-3 rounded-lg border p-4 md:grid-cols-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/finance/invoices', {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <h2 className="font-semibold md:col-span-3">New invoice</h2>
                    <label className="grid gap-1">
                        Bill to
                        <Input
                            value={form.data.customer}
                            onChange={(e) =>
                                form.setData('customer', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1">
                        Their email
                        <Input
                            type="email"
                            value={form.data.customer_email}
                            onChange={(e) =>
                                form.setData('customer_email', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1">
                        Kind of income
                        <select
                            className="rounded-md border bg-background p-2"
                            value={form.data.account_code}
                            onChange={(e) =>
                                form.setData('account_code', e.target.value)
                            }
                        >
                            {accounts.map((a) => (
                                <option key={a.code} value={a.code}>
                                    {a.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 md:col-span-3">
                        Their address
                        <Input
                            value={form.data.customer_address}
                            onChange={(e) =>
                                form.setData('customer_address', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1">
                        Date
                        <Input
                            type="date"
                            value={form.data.issue_date}
                            onChange={(e) =>
                                form.setData('issue_date', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1">
                        Due
                        <Input
                            type="date"
                            value={form.data.due_date}
                            onChange={(e) =>
                                form.setData('due_date', e.target.value)
                            }
                        />
                    </label>
                    <div />
                    {form.data.lines.map((l, i) => (
                        <div
                            key={i}
                            className="grid gap-2 md:col-span-3 md:grid-cols-[1fr_10rem]"
                        >
                            <Input
                                aria-label={`Line ${i + 1} description`}
                                placeholder="Description"
                                value={l.description}
                                onChange={(e) =>
                                    setLine(i, 'description', e.target.value)
                                }
                            />
                            <Input
                                aria-label={`Line ${i + 1} amount`}
                                placeholder="Amount ($)"
                                inputMode="decimal"
                                value={l.amount}
                                onChange={(e) =>
                                    setLine(i, 'amount', e.target.value)
                                }
                            />
                        </div>
                    ))}
                    <div className="flex flex-wrap gap-2 md:col-span-3">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                form.setData('lines', [
                                    ...form.data.lines,
                                    { description: '', amount: '' },
                                ])
                            }
                        >
                            Add a line
                        </Button>
                        <Button disabled={form.processing}>
                            Issue invoice
                        </Button>
                    </div>
                    {Object.values(form.errors).length > 0 && (
                        <p className="text-destructive md:col-span-3">
                            {Object.values(form.errors)[0]}
                        </p>
                    )}
                </form>
                <ul className="grid gap-2">
                    {invoices.length === 0 && (
                        <li className="text-muted-foreground">
                            No invoices yet.
                        </li>
                    )}
                    {invoices.map((inv) => (
                        <InvoiceRow key={inv.ulid} inv={inv} />
                    ))}
                </ul>
            </div>
        </>
    );
}

function InvoiceRow({ inv }: { inv: Invoice }) {
    const [paying, setPaying] = useState(false);
    const pay = useForm({ paid_on: today(), reference: '' });

    return (
        <li className="grid gap-2 rounded-lg border p-3">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">#{inv.number}</span>
                <span>{inv.customer}</span>
                <span className="font-semibold">{usd(inv.total_cents)}</span>
                <span className="text-muted-foreground">
                    due {inv.due_date}
                </span>
                <span className="ml-auto flex flex-wrap items-center gap-2">
                    {inv.status === 'paid' && <Badge>Paid {inv.paid_on}</Badge>}
                    {inv.status === 'void' && (
                        <Badge variant="outline">Void</Badge>
                    )}
                    {inv.overdue && (
                        <Badge variant="destructive">Overdue</Badge>
                    )}
                    <a
                        className="underline"
                        href={`/finance/invoices/${inv.ulid}.pdf`}
                        target="_blank"
                        rel="noreferrer"
                    >
                        PDF
                    </a>
                    {inv.status === 'open' && !paying && (
                        <>
                            <Button size="sm" onClick={() => setPaying(true)}>
                                Mark paid
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => {
                                    const reason = window.prompt(
                                        'Why void this invoice?',
                                    );
                                    if (reason)
                                        router.post(
                                            `/finance/invoices/${inv.ulid}/void`,
                                            { reason },
                                            { preserveScroll: true },
                                        );
                                }}
                            >
                                Void
                            </Button>
                        </>
                    )}
                </span>
            </div>
            {paying && (
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        pay.post(`/finance/invoices/${inv.ulid}/paid`, {
                            preserveScroll: true,
                            onSuccess: () => setPaying(false),
                        });
                    }}
                >
                    <label className="grid gap-1">
                        Paid on
                        <Input
                            type="date"
                            value={pay.data.paid_on}
                            onChange={(e) =>
                                pay.setData('paid_on', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1">
                        Check or reference
                        <Input
                            value={pay.data.reference}
                            onChange={(e) =>
                                pay.setData('reference', e.target.value)
                            }
                        />
                    </label>
                    <Button disabled={pay.processing}>Save payment</Button>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => setPaying(false)}
                    >
                        Cancel
                    </Button>
                </form>
            )}
        </li>
    );
}
