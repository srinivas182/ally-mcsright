import { Link } from '@inertiajs/react';

import { usd } from '@/lib/cents';

export type Wallet = {
    year: number;
    earned_ytd_cents: number;
    paid_ytd_cents: number;
    months: { month: string; earned_cents: number; paid_cents: number }[];
    cap: {
        plan: string;
        cap_cents: number | null;
        paid_cents: number;
        to_cap_cents: number | null;
        capped: boolean;
        forecast: string | null;
    } | null;
    pending_deals: {
        ulid: string | null;
        street: string;
        status: string | null;
        closing_date: string | null;
        expected_cents: number;
        paid_outside: boolean;
    }[];
    revenue_share: { earned_ytd_cents: number; pending_cents: number };
    owed_cents: number;
};

const monthName = (ym: string) =>
    new Date(`${ym}-15T12:00:00`).toLocaleDateString('en-US', {
        month: 'long',
        year: 'numeric',
    });

/**
 * The Agent Wallet (specs/finance/03): this year's earnings, cap progress, pending deals, revenue share and what
 * is owed, all from the ledger and the monthly statements, on one phone-sized screen.
 */
export function AgentWallet({ wallet }: { wallet: Wallet }) {
    const cap = wallet.cap;
    const capShare =
        cap && cap.cap_cents
            ? Math.min(100, Math.round((cap.paid_cents * 100) / cap.cap_cents))
            : null;

    return (
        <section className="grid gap-3" aria-labelledby="wallet-title">
            <h2 id="wallet-title" className="sr-only">
                Your wallet
            </h2>
            <div className="grid grid-cols-2 gap-3">
                <div className="rounded-lg border p-3">
                    <p className="text-sm text-muted-foreground">
                        Earned in {wallet.year}
                    </p>
                    <p className="text-2xl font-semibold">
                        {usd(wallet.earned_ytd_cents)}
                    </p>
                </div>
                <div className="rounded-lg border p-3">
                    <p className="text-sm text-muted-foreground">
                        Paid to you in {wallet.year}
                    </p>
                    <p className="text-2xl font-semibold">
                        {usd(wallet.paid_ytd_cents)}
                    </p>
                </div>
            </div>
            {cap && cap.cap_cents !== null && (
                <div className="grid gap-1 rounded-lg border p-3 text-sm">
                    <p className="font-medium">
                        {cap.capped
                            ? `You've capped on ${cap.plan}`
                            : `${usd(cap.to_cap_cents ?? 0)} to your cap`}
                    </p>
                    <div
                        className="h-2 overflow-hidden rounded bg-muted"
                        role="progressbar"
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-valuenow={capShare ?? 0}
                        aria-label="Progress to cap"
                    >
                        <div
                            className="h-full bg-primary"
                            style={{ width: `${capShare ?? 0}%` }}
                        />
                    </div>
                    <p className="text-muted-foreground">
                        {usd(cap.paid_cents)} of {usd(cap.cap_cents)} paid to
                        the company this cap year.
                        {cap.forecast ? ` ${cap.forecast}` : ''}
                    </p>
                </div>
            )}
            <div className="grid grid-cols-2 gap-3 text-sm">
                <div className="rounded-lg border p-3">
                    <p className="text-muted-foreground">Revenue share</p>
                    <p className="font-medium">
                        {usd(wallet.revenue_share.earned_ytd_cents)} earned this
                        year
                    </p>
                    <p className="text-muted-foreground">
                        {usd(wallet.revenue_share.pending_cents)} pending
                    </p>
                </div>
                <div className="rounded-lg border p-3">
                    <p className="text-muted-foreground">You owe</p>
                    <p className="font-medium">{usd(wallet.owed_cents)}</p>
                    <p className="text-muted-foreground">
                        {wallet.owed_cents > 0
                            ? 'Open invoices, below'
                            : 'Nothing open'}
                    </p>
                </div>
            </div>
            {wallet.pending_deals.length > 0 && (
                <div className="grid gap-1 rounded-lg border p-3 text-sm">
                    <p className="font-medium">Deals on their way</p>
                    <ul className="grid gap-1">
                        {wallet.pending_deals.map((d, i) => (
                            <li
                                key={d.ulid ?? i}
                                className="flex flex-wrap justify-between gap-2"
                            >
                                <span>
                                    {d.ulid ? (
                                        <Link
                                            href={`/deals/${d.ulid}`}
                                            className="underline"
                                        >
                                            {d.street}
                                        </Link>
                                    ) : (
                                        d.street
                                    )}
                                    {d.closing_date
                                        ? ` · closes ${d.closing_date}`
                                        : ''}
                                </span>
                                <span className="font-medium">
                                    {usd(d.expected_cents)}
                                    {d.paid_outside ? ' (paid at closing)' : ''}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {wallet.months.length > 0 && (
                <details className="rounded-lg border p-3 text-sm">
                    <summary className="cursor-pointer font-medium">
                        Monthly statements
                    </summary>
                    <ul className="mt-2 grid gap-1">
                        {wallet.months.map((m) => (
                            <li
                                key={m.month}
                                className="flex justify-between gap-2"
                            >
                                <a
                                    href={`/my-money/statement?month=${m.month}`}
                                    className="underline"
                                >
                                    {monthName(m.month)}
                                </a>
                                <span className="text-muted-foreground">
                                    earned {usd(m.earned_cents)} · paid{' '}
                                    {usd(m.paid_cents)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </section>
    );
}
