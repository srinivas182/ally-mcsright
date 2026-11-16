import { Head, router, useForm } from '@inertiajs/react';
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
    ads: {
        monthly_budget_cents: number;
        weekly_topup_cents: number;
        target_cents: number;
        stop_loss_cents: number;
        status: string;
        reason: string | null;
        summary: {
            spend_7d: number;
            accepted_7d: number;
            cost_7d: number | null;
            spend_month: number;
            accepted_month: number;
            cost_month: number | null;
        };
    };
    spend: {
        day: string;
        channel: string;
        state: string | null;
        spend_cents: number;
    }[];
    reviews: {
        ulid: string;
        agent: string;
        rating: number;
        text: string | null;
        date: string | null;
    }[];
    removal_reasons: Record<string, string>;
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
    ads,
    spend,
    reviews,
    removal_reasons,
}: Props) {
    const settings = useForm({
        kind: 'settings',
        monthly_budget: String(ads.monthly_budget_cents / 100),
        weekly_topup: String(ads.weekly_topup_cents / 100),
        target: String(ads.target_cents / 100),
        stop_loss: String(ads.stop_loss_cents / 100),
    });
    const entry = useForm({
        kind: 'spend',
        day: new Date().toISOString().slice(0, 10),
        channel: 'meta',
        state: '',
        spend: '',
        note: '',
    });
    const [removal, setRemoval] = useState({ reason: 'abuse', note: '' });
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
                <section className="grid gap-2 text-sm">
                    <h2 className="text-lg font-semibold">Movewise ads</h2>
                    <p>
                        <Badge
                            variant={
                                ads.status === 'held'
                                    ? 'destructive'
                                    : 'default'
                            }
                        >
                            {ads.status === 'held'
                                ? 'Top-up held'
                                : 'OK to top up'}
                        </Badge>{' '}
                        {ads.reason}
                    </p>
                    <p className="text-muted-foreground">
                        Last 7 days: {usd(ads.summary.spend_7d)} for{' '}
                        {ads.summary.accepted_7d} accepted introductions
                        {ads.summary.cost_7d !== null
                            ? ` (${usd(ads.summary.cost_7d)} each)`
                            : ''}
                        . This month: {usd(ads.summary.spend_month)} for{' '}
                        {ads.summary.accepted_month}
                        {ads.summary.cost_month !== null
                            ? ` (${usd(ads.summary.cost_month)} each)`
                            : ''}
                        .
                    </p>
                    <form
                        className="flex flex-wrap items-end gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            settings.post('/hq/movewise/money/ads', {
                                preserveScroll: true,
                            });
                        }}
                    >
                        {(
                            [
                                ['monthly_budget', 'Monthly budget ($)'],
                                ['weekly_topup', 'Weekly top-up ($)'],
                                ['target', 'Target per introduction ($)'],
                                ['stop_loss', 'Stop-loss per introduction ($)'],
                            ] as const
                        ).map(([k, label]) => (
                            <label key={k} className="grid gap-1">
                                {label}
                                <Input
                                    className="w-36"
                                    inputMode="decimal"
                                    value={settings.data[k]}
                                    onChange={(e) =>
                                        settings.setData(k, e.target.value)
                                    }
                                />
                            </label>
                        ))}
                        <Button size="sm">Save</Button>
                        {settings.errors.stop_loss && (
                            <span className="text-destructive">
                                {settings.errors.stop_loss}
                            </span>
                        )}
                    </form>
                    <form
                        className="flex flex-wrap items-end gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            entry.post('/hq/movewise/money/ads', {
                                preserveScroll: true,
                                onSuccess: () => entry.setData('spend', ''),
                            });
                        }}
                    >
                        <Input
                            aria-label="Day"
                            type="date"
                            className="w-40"
                            value={entry.data.day}
                            onChange={(e) =>
                                entry.setData('day', e.target.value)
                            }
                        />
                        <select
                            aria-label="Channel"
                            className="h-9 rounded-md border bg-background px-2"
                            value={entry.data.channel}
                            onChange={(e) =>
                                entry.setData('channel', e.target.value)
                            }
                        >
                            <option value="meta">Meta</option>
                            <option value="google">Google</option>
                            <option value="other">Other</option>
                        </select>
                        <Input
                            aria-label="State (optional)"
                            placeholder="TX"
                            className="w-20"
                            value={entry.data.state}
                            onChange={(e) =>
                                entry.setData('state', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Spend"
                            placeholder="Spend ($)"
                            className="w-32"
                            inputMode="decimal"
                            value={entry.data.spend}
                            onChange={(e) =>
                                entry.setData('spend', e.target.value)
                            }
                        />
                        <Button size="sm">Record spend</Button>
                        {entry.errors.spend && (
                            <span className="text-destructive">
                                {entry.errors.spend}
                            </span>
                        )}
                    </form>
                    {spend.length > 0 && (
                        <p className="text-muted-foreground">
                            Recent:{' '}
                            {spend
                                .slice(0, 7)
                                .map(
                                    (s) =>
                                        `${s.day} ${s.channel}${s.state ? ` ${s.state}` : ''} ${usd(s.spend_cents)}`,
                                )
                                .join(' · ')}
                        </p>
                    )}
                </section>
                <section className="grid gap-2 text-sm">
                    <h2 className="text-lg font-semibold">Published reviews</h2>
                    <p className="text-muted-foreground">
                        Remove a review only for abuse, personal information or
                        a proven falsehood. The reason is logged.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <select
                            aria-label="Reason"
                            className="h-9 rounded-md border bg-background px-2"
                            value={removal.reason}
                            onChange={(e) =>
                                setRemoval({
                                    ...removal,
                                    reason: e.target.value,
                                })
                            }
                        >
                            {Object.entries(removal_reasons).map(([k, l]) => (
                                <option key={k} value={k}>
                                    {l}
                                </option>
                            ))}
                        </select>
                        <Input
                            aria-label="Why"
                            placeholder="Why (required)"
                            className="w-64"
                            value={removal.note}
                            onChange={(e) =>
                                setRemoval({ ...removal, note: e.target.value })
                            }
                        />
                    </div>
                    {reviews.map((r) => (
                        <div
                            key={r.ulid}
                            className="flex flex-wrap items-center gap-2 rounded-xl border p-2"
                        >
                            <strong>{'★'.repeat(r.rating)}</strong>
                            <span>{r.agent}</span>
                            <span className="text-muted-foreground">
                                {r.date}
                            </span>
                            <span className="grow">{r.text}</span>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        `/hq/movewise/money/reviews/${r.ulid}/remove`,
                                        removal,
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Remove
                            </Button>
                        </div>
                    ))}
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
