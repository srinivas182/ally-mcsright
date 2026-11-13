import { router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usd } from '@/lib/cents';

export type Deposit = {
    ulid: string;
    amount_cents: number;
    held_by: string;
    held_by_name: string | null;
    due_on: string | null;
    status: string;
    received_on: string | null;
    released_on: string | null;
    released_to: string | null;
    overdue: boolean;
};
export type Referral = {
    ulid: string;
    direction: string;
    brokerage: string;
    agent: string;
    percent: string;
    w9: boolean;
    paid_by?: string;
};
export type Cda = {
    ulid: string;
    version: number;
    status: string;
    payload: {
        gci_cents: number;
        payees: {
            name: string;
            role: string;
            amount_cents: number;
            account?: string | null;
        }[];
    };
    sent_at: string | null;
    viewed_at: string | null;
    acknowledged_at: string | null;
    acknowledged_by: string | null;
};

type Props = {
    deal: { ulid: string; status: string; locked: boolean };
    deposits: Deposit[];
    referrals: Referral[];
    cdas: Cda[];
    titles: { ulid: string; name: string }[];
    reviewer: boolean;
    can: { trust: boolean; approveCda: boolean; export: boolean };
};

const heldBy: Record<string, string> = {
    trust: 'Brokerage trust account',
    title: 'Title company',
    other: 'Other',
};

/** Earnest money, referrals, CDA and export on the deal page (Transactions core part 2). */
export function MoneyDesk({
    deal,
    deposits,
    referrals,
    cdas,
    titles,
    reviewer,
    can,
}: Props) {
    const deposit = useForm({
        amount: '',
        held_by: 'title',
        held_by_name: '',
        due_on: '',
    });
    const referral = useForm({
        direction: 'out',
        brokerage: '',
        agent: '',
        email: '',
        percent: '25',
        paid_by: 'title',
    });
    const opts = { preserveScroll: true };
    const today = new Date().toISOString().slice(0, 10);

    return (
        <>
            <section className="grid gap-2 rounded-lg border p-4 text-sm">
                <h2 className="font-semibold">Earnest money</h2>
                {deposits.length === 0 && (
                    <p className="text-muted-foreground">
                        No deposits recorded.
                    </p>
                )}
                <ul className="grid gap-2">
                    {deposits.map((d) => (
                        <li
                            key={d.ulid}
                            className="flex flex-wrap items-center gap-2"
                        >
                            <strong>{usd(d.amount_cents)}</strong>
                            <span>
                                {heldBy[d.held_by]}
                                {d.held_by_name ? ` (${d.held_by_name})` : ''}
                            </span>
                            <Badge
                                variant={d.overdue ? 'destructive' : 'outline'}
                                className="capitalize"
                            >
                                {d.overdue ? 'Overdue' : d.status}
                            </Badge>
                            {d.due_on && d.status === 'expected' && (
                                <span className="text-muted-foreground">
                                    due{' '}
                                    {new Date(d.due_on).toLocaleDateString()}
                                </span>
                            )}
                            {can.trust && d.status === 'expected' && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            `/deals/deposits/${d.ulid}/receive`,
                                            {
                                                received_on: today,
                                                method: 'wire',
                                            },
                                            opts,
                                        )
                                    }
                                >
                                    Mark received today
                                </Button>
                            )}
                            {can.trust && d.status === 'received' && (
                                <>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                `/deals/deposits/${d.ulid}/release`,
                                                {
                                                    refund: false,
                                                    to: 'Closing (applied to purchase)',
                                                    on: today,
                                                },
                                                opts,
                                            )
                                        }
                                    >
                                        Release to closing
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => {
                                            const to =
                                                window.prompt(
                                                    'Refund to (name)?',
                                                );
                                            if (to)
                                                router.post(
                                                    `/deals/deposits/${d.ulid}/release`,
                                                    {
                                                        refund: true,
                                                        to,
                                                        on: today,
                                                    },
                                                    opts,
                                                );
                                        }}
                                    >
                                        Refund
                                    </Button>
                                </>
                            )}
                            {d.released_to && (
                                <span className="text-muted-foreground">
                                    → {d.released_to}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
                {!deal.locked && (
                    <form
                        className="flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            deposit.post(`/deals/${deal.ulid}/deposits`, {
                                ...opts,
                                onSuccess: () => deposit.reset(),
                            });
                        }}
                    >
                        <Input
                            aria-label="Deposit amount ($)"
                            type="number"
                            min={1}
                            placeholder="Amount $"
                            className="w-32"
                            value={deposit.data.amount}
                            onChange={(e) =>
                                deposit.setData('amount', e.target.value)
                            }
                        />
                        <select
                            aria-label="Held by"
                            className="rounded border bg-background px-2 py-1.5"
                            value={deposit.data.held_by}
                            onChange={(e) =>
                                deposit.setData('held_by', e.target.value)
                            }
                        >
                            {Object.entries(heldBy).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </select>
                        <Input
                            aria-label="Due on"
                            type="date"
                            className="w-40"
                            value={deposit.data.due_on}
                            onChange={(e) =>
                                deposit.setData('due_on', e.target.value)
                            }
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={!deposit.data.amount}
                        >
                            Add deposit
                        </Button>
                    </form>
                )}
            </section>

            <section className="grid gap-2 rounded-lg border p-4 text-sm">
                <h2 className="font-semibold">Referrals</h2>
                <ul className="grid gap-1">
                    {referrals.map((r) => (
                        <li key={r.ulid}>
                            {r.direction === 'out'
                                ? 'Paid to'
                                : 'Received from'}{' '}
                            {r.agent}, {r.brokerage} — {r.percent}%
                            {r.direction === 'out'
                                ? r.paid_by === 'brokerage'
                                    ? ' · we pay it'
                                    : ' · title pays it'
                                : ''}
                            {r.direction === 'out' && !r.w9
                                ? ' · W-9 needed'
                                : ''}
                        </li>
                    ))}
                </ul>
                {!deal.locked && (
                    <form
                        className="flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            referral.post(`/deals/${deal.ulid}/referrals`, {
                                ...opts,
                                onSuccess: () => referral.reset(),
                            });
                        }}
                    >
                        <select
                            aria-label="Direction"
                            className="rounded border bg-background px-2 py-1.5"
                            value={referral.data.direction}
                            onChange={(e) =>
                                referral.setData('direction', e.target.value)
                            }
                        >
                            <option value="out">We pay a referral fee</option>
                            <option value="in">
                                We receive a referral fee
                            </option>
                        </select>
                        <Input
                            aria-label="Other brokerage"
                            placeholder="Brokerage"
                            className="w-44"
                            value={referral.data.brokerage}
                            onChange={(e) =>
                                referral.setData('brokerage', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Agent"
                            placeholder="Agent"
                            className="w-40"
                            value={referral.data.agent}
                            onChange={(e) =>
                                referral.setData('agent', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Percent"
                            type="number"
                            min={1}
                            max={100}
                            className="w-20"
                            value={referral.data.percent}
                            onChange={(e) =>
                                referral.setData('percent', e.target.value)
                            }
                        />
                        {referral.data.direction === 'out' && (
                            <select
                                aria-label="Who pays the referral fee"
                                className="rounded border bg-background px-2 py-1.5"
                                value={referral.data.paid_by}
                                onChange={(e) =>
                                    referral.setData('paid_by', e.target.value)
                                }
                            >
                                <option value="title">Title pays it</option>
                                <option value="brokerage">We pay it</option>
                            </select>
                        )}
                        <Button type="submit" variant="outline">
                            Add referral
                        </Button>
                        {Object.values(referral.errors)[0] && (
                            <p role="alert" className="w-full text-destructive">
                                {Object.values(referral.errors)[0]}
                            </p>
                        )}
                    </form>
                )}
            </section>

            {reviewer && (
                <section className="grid gap-2 rounded-lg border p-4 text-sm">
                    <div className="flex items-center justify-between">
                        <h2 className="font-semibold">
                            Commission disbursement (CDA)
                        </h2>
                        {deal.status !== 'pre_contract' && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        `/deals/${deal.ulid}/cdas`,
                                        {},
                                        opts,
                                    )
                                }
                            >
                                {cdas.length ? 'Regenerate' : 'Generate CDA'}
                            </Button>
                        )}
                    </div>
                    {cdas
                        .filter((c) => c.status !== 'superseded')
                        .map((c) => (
                            <div
                                key={c.ulid}
                                className="grid gap-1 rounded-md bg-muted/30 p-3"
                            >
                                <span className="flex items-center gap-2">
                                    Version {c.version}{' '}
                                    <Badge
                                        variant="outline"
                                        className="capitalize"
                                    >
                                        {c.status}
                                    </Badge>
                                </span>
                                <span>
                                    Commission {usd(c.payload.gci_cents)}:
                                </span>
                                <ul className="list-disc pl-5">
                                    {c.payload.payees.map((p, i) => (
                                        <li key={i}>
                                            {usd(p.amount_cents)} to {p.name} —{' '}
                                            {p.role}
                                            {p.account ? ` (${p.account})` : ''}
                                        </li>
                                    ))}
                                </ul>
                                {c.status === 'draft' && can.approveCda && (
                                    <Button
                                        size="sm"
                                        className="justify-self-start"
                                        onClick={() =>
                                            router.post(
                                                `/deals/cdas/${c.ulid}/approve`,
                                                {},
                                                opts,
                                            )
                                        }
                                    >
                                        Approve
                                    </Button>
                                )}
                                {c.status === 'approved' &&
                                    (titles.length === 0 ? (
                                        <span className="text-muted-foreground">
                                            Add and verify a title company in
                                            Settings › Transactions first.
                                        </span>
                                    ) : (
                                        <span className="flex flex-wrap gap-2">
                                            {titles.map((t) => (
                                                <Button
                                                    key={t.ulid}
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        router.post(
                                                            `/deals/cdas/${c.ulid}/send`,
                                                            { title: t.ulid },
                                                            opts,
                                                        )
                                                    }
                                                >
                                                    Send to {t.name}
                                                </Button>
                                            ))}
                                        </span>
                                    ))}
                                {c.sent_at && (
                                    <span className="text-muted-foreground">
                                        Sent{' '}
                                        {new Date(c.sent_at).toLocaleString()} ·{' '}
                                        {c.viewed_at
                                            ? 'opened'
                                            : 'not opened yet'}
                                        {c.acknowledged_at
                                            ? ` · acknowledged by ${c.acknowledged_by}`
                                            : ''}
                                    </span>
                                )}
                            </div>
                        ))}
                </section>
            )}

            {can.export && (
                <a
                    href={`/deals/${deal.ulid}/export`}
                    className="text-sm underline"
                >
                    Download the complete transaction file (documents, checklist
                    decisions, audit trail and certificate)
                </a>
            )}
        </>
    );
}
