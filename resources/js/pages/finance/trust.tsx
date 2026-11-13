import { Head, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Item = { id: number; date: string; description: string; amount: number };
type Props = {
    summary: {
        month: string;
        status: string;
        bank_cents: number | null;
        adjusted_bank_cents: number | null;
        book_cents: number;
        clients_cents: number;
        balanced: boolean;
        outstanding: Item[];
        unexplained: {
            id: number;
            date: string;
            description: string;
            cents: number;
        }[];
        clients: {
            deal: string;
            cents: number;
            negative: boolean;
            stale: boolean;
        }[];
        negative_clients: number;
        prepared_by: string | null;
        approved_by: string | null;
        approved_at: string | null;
    };
    ledger: Item[];
};

const signed = (c: number) => `${c < 0 ? '−' : ''}${usd(Math.abs(c))}`;

/** Monthly three-way trust reconciliation: bank, book and clients must agree to the cent. */
export default function Trust({ summary: s, ledger }: Props) {
    const upload = useForm<{
        month: string;
        ending: string;
        file: File | null;
    }>({ month: s.month, ending: '', file: null });
    const locked = s.status === 'approved';

    return (
        <>
            <Head title="Trust reconciliation" />
            <div className="flex max-w-5xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Trust reconciliation"
                    description="Each month, the adjusted bank balance, the trust ledger and every deal's balance must agree to the cent."
                    actions={
                        s.status !== 'missing' && (
                            <a
                                className="text-sm underline"
                                href={`/finance/trust?month=${s.month}&format=pdf`}
                            >
                                Report (PDF)
                            </a>
                        )
                    }
                />
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <Input
                        aria-label="Month"
                        type="month"
                        className="w-44"
                        value={s.month}
                        onChange={(e) =>
                            router.get('/finance/trust', {
                                month: e.target.value,
                            })
                        }
                    />
                    {locked ? (
                        <Badge>Approved by {s.approved_by}</Badge>
                    ) : (
                        <Badge variant="outline">
                            {s.status === 'missing'
                                ? 'No statement yet'
                                : `Prepared by ${s.prepared_by}`}
                        </Badge>
                    )}
                </div>
                {!locked && (
                    <form
                        className="flex flex-wrap items-center gap-2 rounded-lg border p-4 text-sm"
                        onSubmit={(e) => {
                            e.preventDefault();
                            upload.post('/finance/trust/import', {
                                forceFormData: true,
                            });
                        }}
                    >
                        Trust bank statement (CSV):
                        <input
                            aria-label="Statement file"
                            type="file"
                            accept=".csv,text/csv"
                            onChange={(e) =>
                                upload.setData(
                                    'file',
                                    e.target.files?.[0] ?? null,
                                )
                            }
                        />
                        ending balance $
                        <Input
                            aria-label="Statement ending balance ($)"
                            type="number"
                            step="0.01"
                            className="w-36"
                            value={upload.data.ending}
                            onChange={(e) =>
                                upload.setData('ending', e.target.value)
                            }
                        />
                        <Button
                            type="submit"
                            size="sm"
                            disabled={
                                !upload.data.file || upload.data.ending === ''
                            }
                        >
                            Import and match
                        </Button>
                        {Object.values(upload.errors)[0] && (
                            <p role="alert" className="w-full text-destructive">
                                {Object.values(upload.errors)[0]}
                            </p>
                        )}
                    </form>
                )}
                <section className="grid gap-3 sm:grid-cols-4">
                    {[
                        ['Bank (statement)', s.bank_cents],
                        ['Adjusted bank', s.adjusted_bank_cents],
                        ['Book (trust ledger)', s.book_cents],
                        ['Clients (deals)', s.clients_cents],
                    ].map(([label, v]) => (
                        <div
                            key={label as string}
                            className="rounded-lg border p-3 text-sm"
                        >
                            <p className="text-muted-foreground">{label}</p>
                            <p className="text-lg font-semibold">
                                {v === null ? '—' : signed(v as number)}
                            </p>
                        </div>
                    ))}
                </section>
                <p
                    role="status"
                    className={`rounded-md p-3 text-sm font-medium ${s.balanced && s.negative_clients === 0 ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-900'}`}
                >
                    {s.balanced && s.negative_clients === 0
                        ? 'Balanced: all three agree.'
                        : s.negative_clients > 0
                          ? `${s.negative_clients} deal(s) have a negative trust balance — resolve before approving.`
                          : 'Not balanced yet: check the items below.'}
                </p>
                {s.unexplained.length > 0 && (
                    <section className="grid gap-2 rounded-lg border border-amber-300 p-4 text-sm">
                        <h2 className="font-semibold">
                            Bank lines with no trust entry
                        </h2>
                        {s.unexplained.map((u) => (
                            <div
                                key={u.id}
                                className="flex flex-wrap items-center gap-2"
                            >
                                {new Date(u.date).toLocaleDateString()} ·{' '}
                                {u.description} ·{' '}
                                <strong>{signed(u.cents)}</strong>
                                {!locked &&
                                    ledger.filter((l) => l.amount === u.cents)
                                        .length > 0 && (
                                        <select
                                            aria-label="Match to trust entry"
                                            className="rounded border bg-background px-2 py-1"
                                            defaultValue=""
                                            onChange={(e) =>
                                                e.target.value &&
                                                router.post(
                                                    '/finance/trust/match',
                                                    {
                                                        statement_line: u.id,
                                                        ledger_line: Number(
                                                            e.target.value,
                                                        ),
                                                    },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <option value="">Match to…</option>
                                            {ledger
                                                .filter(
                                                    (l) => l.amount === u.cents,
                                                )
                                                .map((l) => (
                                                    <option
                                                        key={l.id}
                                                        value={l.id}
                                                    >
                                                        {l.date} {l.description}
                                                    </option>
                                                ))}
                                        </select>
                                    )}
                            </div>
                        ))}
                    </section>
                )}
                <section className="grid gap-2 rounded-lg border p-4 text-sm">
                    <h2 className="font-semibold">
                        Outstanding (in the ledger, not yet on the bank
                        statement)
                    </h2>
                    {s.outstanding.length === 0 ? (
                        <p className="text-muted-foreground">None.</p>
                    ) : (
                        s.outstanding.map((o) => (
                            <p key={o.id}>
                                {o.date} · {o.description} · {signed(o.amount)}
                            </p>
                        ))
                    )}
                </section>
                <section className="grid gap-2 rounded-lg border p-4 text-sm">
                    <h2 className="font-semibold">Money held per deal</h2>
                    {s.clients.length === 0 ? (
                        <p className="text-muted-foreground">Nothing held.</p>
                    ) : (
                        s.clients.map((c, i) => (
                            <p
                                key={i}
                                className="flex flex-wrap items-center gap-2"
                            >
                                {c.deal} · {signed(c.cents)}{' '}
                                {c.negative && (
                                    <Badge variant="destructive">
                                        Negative
                                    </Badge>
                                )}{' '}
                                {c.stale && (
                                    <Badge variant="outline">
                                        Still held after closing
                                    </Badge>
                                )}
                            </p>
                        ))
                    )}
                </section>
                {!locked && s.status === 'draft' && (
                    <Button
                        className="justify-self-start"
                        disabled={!s.balanced || s.negative_clients > 0}
                        onClick={() =>
                            router.post(
                                '/finance/trust/approve',
                                { month: s.month },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Approve and lock {s.month}
                    </Button>
                )}
                {!locked && s.status === 'draft' && (
                    <p className="text-xs text-muted-foreground">
                        Someone other than {s.prepared_by} must approve.
                    </p>
                )}
            </div>
        </>
    );
}

Trust.layout = {
    breadcrumbs: [{ title: 'Trust reconciliation', href: '/finance/trust' }],
};
