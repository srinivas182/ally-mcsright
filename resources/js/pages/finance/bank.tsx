import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { BankFeedPanel, type Feed } from '@/components/finance/bank-feed';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Line = {
    id: number;
    date: string;
    description: string;
    amount_cents: number;
};
type Summary = {
    month: string;
    statement: {
        ulid: string;
        status: string;
        prepared_by: number;
        approved_at: string | null;
    } | null;
    bank_cents: number | null;
    book_cents: number;
    in_transit_cents: number;
    outstanding_cents: number;
    adjusted_cents: number | null;
    difference_cents: number | null;
    unmatched_bank: Line[];
    unmatched_books: Line[];
    matched: number;
    can_approve: boolean;
};

/** AC1: the operating bank account, reconciled month by month. */
export default function Bank({
    summary,
    accounts,
    feed,
}: {
    summary: Summary;
    accounts: { code: string; name: string }[];
    feed: Feed;
}) {
    const s = summary;
    const imp = useForm<{
        month: string;
        ending_balance: string;
        file: File | null;
    }>({ month: s.month, ending_balance: '', file: null });
    const locked = s.statement?.status === 'approved';

    return (
        <>
            <Head title="Bank reconciliation" />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Bank reconciliation"
                    description="Each month, download the statement from your bank (CSV or Quicken/OFX), add it here, and we match it to the books. Anything the bank shows that the books don't, add with one click."
                />
                <BankFeedPanel feed={feed} month={s.month} locked={locked} />
                <div className="flex flex-wrap items-end gap-3">
                    <label className="grid gap-1">
                        Month
                        <Input
                            type="month"
                            value={s.month}
                            onChange={(e) =>
                                router.get('/finance/bank', {
                                    month: e.target.value,
                                })
                            }
                        />
                    </label>
                    {s.statement && (
                        <Badge variant={locked ? 'default' : 'outline'}>
                            {locked
                                ? `Approved ${s.statement.approved_at?.slice(0, 10)}`
                                : 'In progress'}
                        </Badge>
                    )}
                </div>
                {!locked && (
                    <form
                        className="grid gap-3 rounded-lg border p-4 sm:grid-cols-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            imp.post('/finance/bank/import', {
                                forceFormData: true,
                            });
                        }}
                    >
                        <h2 className="font-semibold sm:col-span-3">
                            {s.statement
                                ? 'Replace the statement'
                                : 'Add the statement'}
                        </h2>
                        <label className="grid gap-1">
                            Ending balance on the statement ($)
                            <Input
                                inputMode="decimal"
                                value={imp.data.ending_balance}
                                onChange={(e) =>
                                    imp.setData(
                                        'ending_balance',
                                        e.target.value,
                                    )
                                }
                            />
                        </label>
                        <label className="grid gap-1">
                            Statement file
                            <input
                                type="file"
                                accept=".csv,.ofx,.qfx,text/csv"
                                onChange={(e) =>
                                    imp.setData(
                                        'file',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                        </label>
                        <div className="flex items-end">
                            <Button
                                disabled={
                                    imp.processing || imp.data.file === null
                                }
                            >
                                Add and match
                            </Button>
                        </div>
                        {Object.values(imp.errors).length > 0 && (
                            <p className="text-destructive sm:col-span-3">
                                {Object.values(imp.errors)[0]}
                            </p>
                        )}
                    </form>
                )}
                {s.statement && (
                    <section className="grid gap-2 rounded-lg border p-4">
                        <h2 className="font-semibold">Does it balance?</h2>
                        <dl className="grid gap-1 sm:grid-cols-2">
                            <dt className="text-muted-foreground">
                                Bank&rsquo;s ending balance
                            </dt>
                            <dd className="font-medium">
                                {usd(s.bank_cents ?? 0)}
                            </dd>
                            <dt className="text-muted-foreground">
                                + deposits not on the statement yet
                            </dt>
                            <dd>{usd(s.in_transit_cents)}</dd>
                            <dt className="text-muted-foreground">
                                − payments not cleared yet
                            </dt>
                            <dd>{usd(-s.outstanding_cents)}</dd>
                            <dt className="text-muted-foreground">
                                = adjusted bank balance
                            </dt>
                            <dd className="font-medium">
                                {usd(s.adjusted_cents ?? 0)}
                            </dd>
                            <dt className="text-muted-foreground">
                                Operating cash in the books
                            </dt>
                            <dd className="font-medium">{usd(s.book_cents)}</dd>
                            <dt className="text-muted-foreground">
                                Difference
                            </dt>
                            <dd
                                className={`text-lg font-semibold ${s.difference_cents === 0 ? 'text-green-700 dark:text-green-400' : 'text-destructive'}`}
                            >
                                {s.difference_cents === 0
                                    ? 'Balanced'
                                    : usd(s.difference_cents ?? 0)}
                            </dd>
                        </dl>
                        <p className="text-sm text-muted-foreground">
                            {s.matched} bank lines matched to the books.
                        </p>
                        {!locked && (
                            <div>
                                <Button
                                    disabled={!s.can_approve}
                                    onClick={() =>
                                        router.post(
                                            '/finance/bank/approve',
                                            { month: s.month },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Approve and lock
                                </Button>
                                {!s.can_approve && (
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        It can be approved once it balances,
                                        every bank line is matched, by someone
                                        other than the person who added it.
                                    </p>
                                )}
                            </div>
                        )}
                    </section>
                )}
                {s.unmatched_bank.length > 0 && (
                    <section className="grid gap-2">
                        <h2 className="font-semibold">
                            On the bank statement, not in the books (
                            {s.unmatched_bank.length})
                        </h2>
                        {s.unmatched_bank.map((l) => (
                            <BankLine
                                key={l.id}
                                line={l}
                                accounts={accounts}
                                books={s.unmatched_books}
                                locked={locked}
                            />
                        ))}
                    </section>
                )}
                {s.unmatched_books.length > 0 && (
                    <section className="grid gap-2">
                        <h2 className="font-semibold">
                            In the books, not on the statement yet (
                            {s.unmatched_books.length})
                        </h2>
                        <ul className="grid gap-1 text-sm">
                            {s.unmatched_books.map((b) => (
                                <li
                                    key={b.id}
                                    className="flex flex-wrap gap-2 rounded border p-2"
                                >
                                    <span>{b.date}</span>
                                    <span className="text-muted-foreground">
                                        {b.description}
                                    </span>
                                    <span className="ml-auto font-medium">
                                        {usd(b.amount_cents)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

function BankLine({
    line,
    accounts,
    books,
    locked,
}: {
    line: Line;
    accounts: { code: string; name: string }[];
    books: Line[];
    locked: boolean;
}) {
    const [code, setCode] = useState(accounts[0]?.code ?? '');
    const same = books.filter((b) => b.amount_cents === line.amount_cents);

    return (
        <div className="flex flex-wrap items-center gap-2 rounded-lg border p-3">
            <span>{line.date}</span>
            <span className="text-muted-foreground">{line.description}</span>
            <span className="font-semibold">{usd(line.amount_cents)}</span>
            {!locked && (
                <span className="ml-auto flex flex-wrap items-center gap-2">
                    {same.map((b) => (
                        <Button
                            key={b.id}
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    `/finance/bank/lines/${line.id}/match`,
                                    { ledger_line_id: b.id },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Match to {b.date} {b.description.slice(0, 30)}
                        </Button>
                    ))}
                    <select
                        aria-label="Category"
                        className="rounded-md border bg-background p-1.5"
                        value={code}
                        onChange={(e) => setCode(e.target.value)}
                    >
                        {accounts.map((a) => (
                            <option key={a.code} value={a.code}>
                                {a.name}
                            </option>
                        ))}
                    </select>
                    <Button
                        size="sm"
                        onClick={() =>
                            router.post(
                                `/finance/bank/lines/${line.id}/add`,
                                { account_code: code },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Add to the books
                    </Button>
                </span>
            )}
        </div>
    );
}
