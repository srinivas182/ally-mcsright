import { Head, Link } from '@inertiajs/react';

import { ReferralHero } from '@/components/recruiting/referral-hero';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { formatMoney } from '@/lib/money';

type Joins = {
    opens: number;
    opens_30: number;
    started: number;
    joined: number;
};

type Props = {
    link: string;
    year: number;
    joins: Joins;
    direct: number;
    network: number;
    revenue_share_cents: number;
    revenue_share_on: boolean;
    closed: { deals: number; commission_cents: number };
    paid_cents: number;
    waiting_cents: number;
    waiting_deals: number;
    cap: { cap_cents: number; paid_cents: number; capped: boolean } | null;
};

const usd = (cents: number) => formatMoney({ cents, currency: 'USD' });

function Stat({
    label,
    value,
    note,
}: {
    label: string;
    value: string;
    note?: string;
}) {
    return (
        <div className="grid gap-1 rounded-xl border bg-card p-4">
            <p className="text-sm text-muted-foreground">{label}</p>
            <p className="text-3xl font-semibold tabular-nums">{value}</p>
            {note && <p className="text-xs text-muted-foreground">{note}</p>}
        </div>
    );
}

/**
 * AG1/AG1b: My commissions › Overview. Everything the agent earned this year and what is waiting, their cap, their
 * join link and what it brought, and their network in numbers (the tree has its own tab). Never another agent's money.
 */
export default function MyNetwork({
    link,
    year,
    joins,
    direct,
    network,
    revenue_share_cents,
    revenue_share_on,
    closed,
    paid_cents,
    waiting_cents,
    waiting_deals,
    cap,
}: Props) {
    return (
        <>
            <Head title="Overview" />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Overview"
                    description={`What you earned in ${year}, what is on its way, and the agents you brought in. Only your own money is shown here.`}
                />

                <ReferralHero
                    url={link}
                    joins={joins}
                    revenueShare={revenue_share_on}
                />
                <p className="-mt-3 text-sm text-muted-foreground">
                    Know a good agent who might not click a link?{' '}
                    <Link className="underline" href="/recruiting/refer">
                        Tell your broker about them
                    </Link>
                    .
                </p>

                <section aria-labelledby="this-year" className="grid gap-3">
                    <h2 id="this-year" className="text-lg font-semibold">
                        This year
                    </h2>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <Stat
                            label="Deals you closed"
                            value={String(closed.deals)}
                            note="As the agent or a co-agent"
                        />
                        <Stat
                            label="Your commission on them"
                            value={usd(closed.commission_cents)}
                            note="What you keep, after splits and fees"
                        />
                        <Stat
                            label="Revenue share earned"
                            value={usd(revenue_share_cents)}
                            note="From deals closed by agents in your network"
                        />
                        <Stat
                            label="Agents you brought in"
                            value={String(direct)}
                            note={`${network} in your whole network`}
                        />
                    </div>
                </section>

                <section aria-labelledby="money" className="grid gap-3">
                    <h2 id="money" className="text-lg font-semibold">
                        Your money
                    </h2>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Stat
                            label="Paid to you this year"
                            value={usd(paid_cents)}
                        />
                        <Stat
                            label="On its way"
                            value={usd(waiting_cents)}
                            note={
                                waiting_deals === 0
                                    ? 'No deals waiting'
                                    : `From ${waiting_deals} ${waiting_deals === 1 ? 'deal' : 'deals'} not paid yet`
                            }
                        />
                        <Stat
                            label="Your cap"
                            value={
                                cap === null
                                    ? 'No cap'
                                    : cap.capped
                                      ? 'Capped'
                                      : `${usd(cap.paid_cents)} of ${usd(cap.cap_cents)}`
                            }
                            note={
                                cap === null
                                    ? 'Your plan has no cap'
                                    : cap.capped
                                      ? 'You keep more on every deal now'
                                      : 'Paid to the brokerage so far'
                            }
                        />
                    </div>
                    <p className="text-sm">
                        <Link className="underline" href="/my-money">
                            Payments, statements and your bank account
                        </Link>
                    </p>
                </section>

                <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-5">
                    <div>
                        <h2 className="text-lg font-semibold">Your network</h2>
                        <p className="text-sm text-muted-foreground">
                            {network === 0
                                ? 'Nobody yet. Agents who join through your link show here.'
                                : `${direct} brought in by you, ${network} in your whole network.`}
                        </p>
                    </div>
                    <Button asChild variant="outline">
                        <Link href="/my-network/tree">Open network tree</Link>
                    </Button>
                </section>
            </div>
        </>
    );
}

MyNetwork.layout = {
    breadcrumbs: [{ title: 'Overview', href: '/my-network' }],
};
