import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Bill = {
    ulid: string;
    vendor: string;
    bill_number: string | null;
    bill_date: string;
    due_date: string;
    account_code: string;
    amount_cents: number;
    memo: string | null;
    status: string;
    paid_on: string | null;
    overdue: boolean;
    has_file: boolean;
};
type Props = {
    bills: Bill[];
    due_cents: number;
    overdue_cents: number;
    due_week_cents: number;
    accounts: { code: string; name: string }[];
};

const today = () => new Date().toISOString().slice(0, 10);

/** AC1: bills to pay, entered once, paid with one click, all in the books. */
export default function Bills({
    bills,
    due_cents,
    overdue_cents,
    due_week_cents,
    accounts,
}: Props) {
    const form = useForm<{
        vendor: string;
        bill_number: string;
        bill_date: string;
        due_date: string;
        account_code: string;
        amount: string;
        memo: string;
        file: File | null;
    }>({
        vendor: '',
        bill_number: '',
        bill_date: today(),
        due_date: today(),
        account_code: accounts[0]?.code ?? '',
        amount: '',
        memo: '',
        file: null,
    });
    const names = Object.fromEntries(accounts.map((a) => [a.code, a.name]));

    return (
        <>
            <Head title="Bills" />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Bills"
                    description="What the brokerage owes: rent, MLS dues, software, marketing. Enter a bill once; paying it updates the books."
                />
                <section className="grid gap-3 sm:grid-cols-3">
                    <Tile label="Unpaid" cents={due_cents} />
                    <Tile
                        label="Due in the next 7 days"
                        cents={due_week_cents}
                    />
                    <Tile label="Overdue" cents={overdue_cents} warn />
                </section>
                <form
                    className="grid gap-3 rounded-lg border p-4 md:grid-cols-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/finance/bills', {
                            forceFormData: true,
                            preserveScroll: true,
                            onSuccess: () =>
                                form.reset(
                                    'vendor',
                                    'bill_number',
                                    'amount',
                                    'memo',
                                    'file',
                                ),
                        });
                    }}
                >
                    <h2 className="font-semibold md:col-span-4">Add a bill</h2>
                    <label className="grid gap-1 md:col-span-2">
                        Who it&rsquo;s from
                        <Input
                            value={form.data.vendor}
                            onChange={(e) =>
                                form.setData('vendor', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1">
                        Bill number
                        <Input
                            value={form.data.bill_number}
                            onChange={(e) =>
                                form.setData('bill_number', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1">
                        Amount ($)
                        <Input
                            inputMode="decimal"
                            value={form.data.amount}
                            onChange={(e) =>
                                form.setData('amount', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1">
                        What it&rsquo;s for
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
                    <label className="grid gap-1">
                        Bill date
                        <Input
                            type="date"
                            value={form.data.bill_date}
                            onChange={(e) =>
                                form.setData('bill_date', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1">
                        Due date
                        <Input
                            type="date"
                            value={form.data.due_date}
                            onChange={(e) =>
                                form.setData('due_date', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1">
                        The bill (PDF or photo)
                        <input
                            type="file"
                            accept="application/pdf,image/png,image/jpeg"
                            onChange={(e) =>
                                form.setData(
                                    'file',
                                    e.target.files?.[0] ?? null,
                                )
                            }
                        />
                    </label>
                    <label className="grid gap-1 md:col-span-3">
                        Note
                        <Input
                            value={form.data.memo}
                            onChange={(e) =>
                                form.setData('memo', e.target.value)
                            }
                        />
                    </label>
                    <div className="flex items-end">
                        <Button disabled={form.processing}>Add bill</Button>
                    </div>
                    {Object.values(form.errors).length > 0 && (
                        <p className="text-destructive md:col-span-4">
                            {Object.values(form.errors)[0]}
                        </p>
                    )}
                </form>
                <ul className="grid gap-2">
                    {bills.length === 0 && (
                        <li className="text-muted-foreground">No bills yet.</li>
                    )}
                    {bills.map((b) => (
                        <BillRow
                            key={b.ulid}
                            bill={b}
                            category={names[b.account_code] ?? b.account_code}
                        />
                    ))}
                </ul>
            </div>
        </>
    );
}

function Tile({
    label,
    cents,
    warn,
}: {
    label: string;
    cents: number;
    warn?: boolean;
}) {
    return (
        <div className="rounded-lg border p-4">
            <p className="text-muted-foreground">{label}</p>
            <p
                className={`text-2xl font-semibold ${warn && cents > 0 ? 'text-destructive' : ''}`}
            >
                {usd(cents)}
            </p>
        </div>
    );
}

function BillRow({ bill, category }: { bill: Bill; category: string }) {
    const [paying, setPaying] = useState(false);
    const pay = useForm({ paid_on: today(), reference: '' });

    return (
        <li className="grid gap-2 rounded-lg border p-3">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">{bill.vendor}</span>
                {bill.bill_number && (
                    <span className="text-muted-foreground">
                        #{bill.bill_number}
                    </span>
                )}
                <span className="text-muted-foreground">{category}</span>
                <span className="font-semibold">{usd(bill.amount_cents)}</span>
                <span className="text-muted-foreground">
                    due {bill.due_date}
                </span>
                <span className="ml-auto flex flex-wrap items-center gap-2">
                    {bill.status === 'paid' && (
                        <Badge>Paid {bill.paid_on}</Badge>
                    )}
                    {bill.status === 'void' && (
                        <Badge variant="outline">Void</Badge>
                    )}
                    {bill.overdue && (
                        <Badge variant="destructive">Overdue</Badge>
                    )}
                    {bill.has_file && (
                        <a
                            className="underline"
                            href={`/finance/bills/${bill.ulid}/file`}
                            target="_blank"
                            rel="noreferrer"
                        >
                            View bill
                        </a>
                    )}
                    {bill.status === 'open' && !paying && (
                        <>
                            <Button size="sm" onClick={() => setPaying(true)}>
                                Mark paid
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => {
                                    const reason = window.prompt(
                                        'Why void this bill?',
                                    );
                                    if (reason)
                                        router.post(
                                            `/finance/bills/${bill.ulid}/void`,
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
                        pay.post(`/finance/bills/${bill.ulid}/pay`, {
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
                        Check or payment number
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
