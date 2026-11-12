import { Head, router } from '@inertiajs/react';
import { ChevronRightIcon } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Node = {
    ulid: string;
    name: string;
    level: number;
    active: boolean;
    qualifying: boolean;
    closings: number;
    children: Node[];
};
type Props = {
    wallet_cents: number;
    year_cents: number;
    coaching: {
        open_levels: number;
        all_open: boolean;
        next_level?: number;
        qualifying: number;
        needed?: number | null;
        monthly_estimate_cents?: number;
    } | null;
    network: Node[];
    statement: {
        date: string;
        level: number;
        kind: string;
        amount_cents: number;
        explained: string;
    }[];
    missed: {
        date: string;
        level: number;
        amount_cents: number;
        why: string;
    }[];
    retention: {
        name: string;
        level: number;
        why: string;
        suggestion: string;
    }[];
    beneficiary: string | null;
    people: { ulid: string; name: string }[];
};

function Tree({ nodes }: { nodes: Node[] }) {
    return (
        <ul className="grid gap-1 pl-3">
            {nodes.map((n) => (
                <Branch key={n.ulid} node={n} />
            ))}
        </ul>
    );
}

function Branch({ node }: { node: Node }) {
    const [open, setOpen] = useState(node.level === 1);

    return (
        <li>
            <button
                type="button"
                aria-expanded={open}
                className="flex items-center gap-2 py-0.5 text-left text-sm"
                onClick={() => setOpen(!open)}
                disabled={node.children.length === 0}
            >
                <ChevronRightIcon
                    aria-hidden="true"
                    className={`size-3 transition-transform ${open ? 'rotate-90' : ''} ${node.children.length === 0 ? 'invisible' : ''}`}
                />
                <span
                    className={
                        node.active ? '' : 'text-muted-foreground line-through'
                    }
                >
                    {node.name}
                </span>
                <Badge variant="outline">Level {node.level}</Badge>
                {node.qualifying && (
                    <Badge variant="secondary">Qualifying</Badge>
                )}
                <span className="text-xs text-muted-foreground">
                    {node.closings} closing(s) recently
                </span>
            </button>
            {open && node.children.length > 0 && <Tree nodes={node.children} />}
        </li>
    );
}

/** An agent's revenue share: wallet, coaching, network, statement, missed earnings, retention alerts. */
export default function RevenueShare({
    wallet_cents,
    year_cents,
    coaching,
    network,
    statement,
    missed,
    retention,
    beneficiary,
    people,
}: Props) {
    const [chosen, setChosen] = useState(beneficiary ?? '');

    return (
        <>
            <Head title="Revenue share" />
            <div className="flex max-w-4xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Revenue share"
                    description="Paid only from closed deals in your network. Never for sign-ups."
                />
                <section className="grid gap-3 md:grid-cols-3">
                    <div className="rounded-lg border p-4 text-sm">
                        <p className="text-muted-foreground">Wallet</p>
                        <p className="text-2xl font-semibold">
                            {usd(wallet_cents)}
                        </p>
                    </div>
                    <div className="rounded-lg border p-4 text-sm">
                        <p className="text-muted-foreground">This year</p>
                        <p className="text-2xl font-semibold">
                            {usd(year_cents)}
                        </p>
                    </div>
                    <div className="rounded-lg border p-4 text-sm">
                        <p className="text-muted-foreground">Levels open</p>
                        <p className="text-2xl font-semibold">
                            {coaching ? coaching.open_levels : '—'}
                        </p>
                    </div>
                </section>
                {coaching && !coaching.all_open && (
                    <section className="rounded-lg border border-primary/40 bg-primary/5 p-4 text-sm">
                        <p className="font-medium">
                            Unlock level {coaching.next_level}
                        </p>
                        <p>
                            {coaching.needed !== null &&
                            coaching.needed !== undefined
                                ? `${coaching.needed} more qualifying agent(s) on your front line unlocks it.`
                                : 'It unlocks with your own production (for example, capping).'}
                            {coaching.monthly_estimate_cents
                                ? ` At your network's recent pace that's about ${usd(coaching.monthly_estimate_cents)} a month you're not receiving yet.`
                                : ''}
                        </p>
                        <p className="text-muted-foreground">
                            Help producing agents join and succeed — revenue
                            share comes only from closed deals.
                        </p>
                    </section>
                )}
                {retention.length > 0 && (
                    <section className="grid gap-2">
                        <h2 className="font-semibold">
                            People who might need a hand
                        </h2>
                        <ul className="divide-y rounded-lg border text-sm">
                            {retention.map((r) => (
                                <li key={r.name} className="grid gap-0.5 p-3">
                                    <span className="font-medium">
                                        {r.name}{' '}
                                        <span className="font-normal text-muted-foreground">
                                            · level {r.level}
                                        </span>
                                    </span>
                                    <span>{r.why}</span>
                                    <span className="text-muted-foreground">
                                        {r.suggestion}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                <section className="grid gap-2">
                    <h2 className="font-semibold">Your network</h2>
                    {network.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No one yet.
                        </p>
                    ) : (
                        <div className="rounded-lg border p-3">
                            <Tree nodes={network} />
                        </div>
                    )}
                </section>
                <section className="grid gap-2">
                    <h2 className="font-semibold">Every payment</h2>
                    <ul className="divide-y rounded-lg border text-sm">
                        {statement.length === 0 && (
                            <li className="p-3 text-muted-foreground">
                                Nothing yet.
                            </li>
                        )}
                        {statement.map((s, i) => (
                            <li key={i} className="p-3">
                                {s.explained}
                            </li>
                        ))}
                    </ul>
                </section>
                {missed.length > 0 && (
                    <section className="grid gap-2">
                        <h2 className="font-semibold">
                            What you missed, and why
                        </h2>
                        <ul className="divide-y rounded-lg border text-sm">
                            {missed.map((m, i) => (
                                <li
                                    key={i}
                                    className="flex flex-wrap justify-between gap-2 p-3"
                                >
                                    <span>
                                        {usd(m.amount_cents)} · level {m.level}{' '}
                                        ·{' '}
                                        {new Date(m.date).toLocaleDateString()}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {m.why}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                <section className="flex flex-wrap items-center gap-2 rounded-lg border p-4 text-sm">
                    Beneficiary (receives your revenue share if you are vested
                    and pass away):
                    <select
                        aria-label="Beneficiary"
                        className="rounded border bg-background px-2 py-1.5"
                        value={chosen}
                        onChange={(e) => setChosen(e.target.value)}
                    >
                        <option value="">Choose…</option>
                        {people.map((p) => (
                            <option key={p.ulid} value={p.ulid}>
                                {p.name}
                            </option>
                        ))}
                    </select>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={!chosen || chosen === beneficiary}
                        onClick={() =>
                            router.post(
                                '/revenue-share/beneficiary',
                                { beneficiary: chosen },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Save
                    </Button>
                </section>
            </div>
        </>
    );
}

RevenueShare.layout = {
    breadcrumbs: [{ title: 'Revenue share', href: '/revenue-share' }],
};
