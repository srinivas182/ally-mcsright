import { Head } from '@inertiajs/react';
import { ChevronRightIcon } from 'lucide-react';
import { useState } from 'react';

import { SponsorLink } from '@/components/recruiting/sponsor-link';
import { Badge } from '@/components/ui/badge';
import { PageHeader } from '@/components/ui/page-header';
import { formatMoney } from '@/lib/money';

type Node = {
    ulid: string;
    name: string;
    level: number;
    active: boolean;
    closings: number;
    children: Node[];
};

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
    closed: { deals: number; commission_cents: number };
    tree: Node[];
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

function Branch({ node }: { node: Node }) {
    const [open, setOpen] = useState(node.level === 1);
    const leaf = node.children.length === 0;

    return (
        <li>
            <button
                type="button"
                aria-expanded={leaf ? undefined : open}
                className="flex w-full flex-wrap items-center gap-2 rounded-md px-2 py-1.5 text-left hover:bg-muted/60"
                onClick={() => setOpen(!open)}
                disabled={leaf}
            >
                <ChevronRightIcon
                    aria-hidden="true"
                    className={`size-4 transition-transform ${open ? 'rotate-90' : ''} ${leaf ? 'invisible' : ''}`}
                />
                <span
                    className={
                        node.active
                            ? 'font-medium'
                            : 'text-muted-foreground line-through'
                    }
                >
                    {node.name}
                </span>
                <Badge variant="outline">Level {node.level}</Badge>
                <span className="text-sm text-muted-foreground">
                    {node.closings === 1
                        ? '1 closing recently'
                        : `${node.closings} closings recently`}
                </span>
                {!leaf && (
                    <span className="text-sm text-muted-foreground">
                        · brought in {node.children.length}
                    </span>
                )}
            </button>
            {open && !leaf && (
                <ul className="ml-5 grid gap-0.5 border-l pl-2">
                    {node.children.map((c) => (
                        <Branch key={c.ulid} node={c} />
                    ))}
                </ul>
            )}
        </li>
    );
}

/**
 * AG1: My commissions › My network. The agent's join link and what it brought, their direct referrals and whole
 * tree, deals closed this year with their own commission, and revenue share earned. Never another agent's money.
 */
export default function MyNetwork({
    link,
    year,
    joins,
    direct,
    network,
    revenue_share_cents,
    closed,
    tree,
}: Props) {
    return (
        <>
            <Head title="My network" />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="My network"
                    description={`Agents you brought in, and what your work earned you in ${year}. Only your own money is shown here.`}
                />

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

                <SponsorLink url={link} />

                <section aria-labelledby="link-results" className="grid gap-3">
                    <h2 id="link-results" className="text-lg font-semibold">
                        What your link brought
                    </h2>
                    <ol className="grid gap-3 sm:grid-cols-3">
                        <li>
                            <Stat
                                label="Opened your link"
                                value={String(joins.opens)}
                                note={`${joins.opens_30} in the last 30 days`}
                            />
                        </li>
                        <li>
                            <Stat
                                label="Started joining"
                                value={String(joins.started)}
                                note="Gave their licence"
                            />
                        </li>
                        <li>
                            <Stat
                                label="Joined"
                                value={String(joins.joined)}
                                note="Placed under you"
                            />
                        </li>
                    </ol>
                </section>

                <section
                    aria-labelledby="tree"
                    className="grid gap-3 rounded-xl border bg-card p-5"
                >
                    <div>
                        <h2 id="tree" className="text-lg font-semibold">
                            Your network
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Level 1 is the agents you brought in; level 2 is the
                            agents they brought in, and so on. Open a name to
                            see who they brought in.
                        </p>
                    </div>
                    {tree.length === 0 ? (
                        <p className="rounded-lg bg-muted/40 p-4">
                            Nobody yet. Share your link above: agents who join
                            through it are placed under you.
                        </p>
                    ) : (
                        <ul className="grid gap-0.5">
                            {tree.map((n) => (
                                <Branch key={n.ulid} node={n} />
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

MyNetwork.layout = {
    breadcrumbs: [{ title: 'My network', href: '/my-network' }],
};
