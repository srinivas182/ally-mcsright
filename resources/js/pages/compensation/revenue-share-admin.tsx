import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { RulesForm, type Rules } from '@/components/compensation/rules-form';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';
import { postJson } from '@/lib/http';

const reasonText: Record<string, string> = {
    same_phone: 'Same phone number as the agent',
    shared_device: 'Signed in from the same device as the agent',
    recent_sponsor_change: 'Sponsor changed shortly before the deal',
    tiny_deal: 'Very small deal',
};

type Props = {
    holds: {
        id: number;
        reasons: string[];
        amount_cents: number;
        recipient: string | null;
        source: string | null;
        date: string;
        level: number;
    }[];
    disputes: {
        ulid: string;
        agent: string | null;
        claimant: string | null;
        current: string | null;
        evidence: string;
        opened: string;
    }[];
    settings: { budget_percent: string | null; root: string | null };
    budget: { paid_cents: number; room_cents: number | null };
    disclosure: {
        year: number;
        agents: number;
        earning_nothing: number;
        median_cents: number;
        average_cents: number;
        p90_cents: number;
        top_cents: number;
    };
    people: { ulid: string; name: string }[];
};

type Sim = {
    deals: number;
    paid_cents: number;
    missed_cents: number;
    company_cents: number;
    share_of_company: string;
    by_level: { level: number; cents: number }[];
};

function Hold({ hold }: { hold: Props['holds'][number] }) {
    const [note, setNote] = useState('');

    return (
        <li className="grid gap-2 p-3 text-sm">
            <span>
                <strong>{usd(hold.amount_cents)}</strong> to {hold.recipient}{' '}
                from {hold.source}'s closing on{' '}
                {new Date(hold.date).toLocaleDateString()} (level {hold.level})
            </span>
            <span className="text-muted-foreground">
                {hold.reasons.map((r) => reasonText[r] ?? r).join(' · ')}
            </span>
            <span className="flex flex-wrap gap-2">
                <Input
                    aria-label="Note"
                    placeholder="Note (needed to reject)"
                    className="max-w-sm"
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                />
                <Button
                    size="sm"
                    onClick={() =>
                        router.post(
                            `/revenue-share/holds/${hold.id}/release`,
                            { note: note || 'Reviewed and released' },
                            { preserveScroll: true },
                        )
                    }
                >
                    Release
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    disabled={note.length < 10}
                    onClick={() =>
                        router.post(
                            `/revenue-share/holds/${hold.id}/reject`,
                            { note },
                            { preserveScroll: true },
                        )
                    }
                >
                    Reject
                </Button>
            </span>
        </li>
    );
}

function Dispute({ dispute }: { dispute: Props['disputes'][number] }) {
    const form = useForm({
        upheld: true,
        effective_from: new Date().toISOString().slice(0, 10),
        note: '',
    });

    return (
        <li className="grid gap-2 p-3 text-sm">
            <span>
                <strong>{dispute.claimant}</strong> says they recruited{' '}
                <strong>{dispute.agent}</strong> (sponsor today:{' '}
                {dispute.current ?? 'none'})
            </span>
            <span className="text-muted-foreground">"{dispute.evidence}"</span>
            <span className="flex flex-wrap items-center gap-2">
                <Input
                    aria-label="From"
                    type="date"
                    className="w-40"
                    value={form.data.effective_from}
                    onChange={(e) =>
                        form.setData('effective_from', e.target.value)
                    }
                />
                <Input
                    aria-label="Reason for the decision"
                    placeholder="Reason for the decision"
                    className="max-w-sm"
                    value={form.data.note}
                    onChange={(e) => form.setData('note', e.target.value)}
                />
                <Button
                    size="sm"
                    disabled={form.data.note.length < 10}
                    onClick={() =>
                        router.post(
                            `/revenue-share/disputes/${dispute.ulid}/decide`,
                            { ...form.data, upheld: true },
                            { preserveScroll: true },
                        )
                    }
                >
                    Uphold
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    disabled={form.data.note.length < 10}
                    onClick={() =>
                        router.post(
                            `/revenue-share/disputes/${dispute.ulid}/decide`,
                            { ...form.data, upheld: false },
                            { preserveScroll: true },
                        )
                    }
                >
                    Reject
                </Button>
            </span>
        </li>
    );
}

/** Brokerage revenue share: reviews, budget and root account, income disclosure, simulator. */
export default function RevenueShareAdmin({
    holds,
    disputes,
    settings,
    budget,
    disclosure,
    people,
}: Props) {
    const form = useForm({
        budget_percent: settings.budget_percent ?? '',
        root: settings.root ?? '',
    });
    const [rules, setRules] = useState<Rules>({
        split: { mode: 'percentage', agent_percent: '80' },
        revenue_share: { basis: 'net_profit', levels: ['50', '30', '20'] },
    });
    const [sim, setSim] = useState<Sim | null>(null);
    const [error, setError] = useState<string | null>(null);

    return (
        <>
            <Head title="Revenue share" />
            <div className="flex max-w-5xl flex-col gap-5 p-4 md:p-6">
                <PageHeader
                    title="Revenue share"
                    description={`Paid this year: ${usd(budget.paid_cents)}${budget.room_cents !== null ? ` · ${usd(budget.room_cents)} left in the budget` : ''}`}
                />
                <section className="grid gap-2">
                    <h2 className="font-semibold">
                        Held for review ({holds.length})
                    </h2>
                    <ul className="divide-y rounded-lg border">
                        {holds.length === 0 ? (
                            <li className="p-3 text-sm text-muted-foreground">
                                Nothing held.
                            </li>
                        ) : (
                            holds.map((h) => <Hold key={h.id} hold={h} />)
                        )}
                    </ul>
                </section>
                <section className="grid gap-2">
                    <h2 className="font-semibold">
                        Sponsorship disputes ({disputes.length})
                    </h2>
                    <ul className="divide-y rounded-lg border">
                        {disputes.length === 0 ? (
                            <li className="p-3 text-sm text-muted-foreground">
                                No open disputes.
                            </li>
                        ) : (
                            disputes.map((d) => (
                                <Dispute key={d.ulid} dispute={d} />
                            ))
                        )}
                    </ul>
                </section>
                <form
                    className="flex flex-wrap items-center gap-2 rounded-lg border p-4 text-sm"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/compensation/revenue-share/settings', {
                            preserveScroll: true,
                        });
                    }}
                >
                    Yearly budget:
                    <Input
                        aria-label="Budget percent of company dollar"
                        type="number"
                        min={1}
                        max={100}
                        className="w-24"
                        placeholder="none"
                        value={form.data.budget_percent}
                        onChange={(e) =>
                            form.setData('budget_percent', e.target.value)
                        }
                    />
                    % of company dollar · Root account:
                    <select
                        aria-label="Root account"
                        className="rounded border bg-background px-2 py-1.5"
                        value={form.data.root}
                        onChange={(e) => form.setData('root', e.target.value)}
                    >
                        <option value="">First owner (default)</option>
                        {people.map((p) => (
                            <option key={p.ulid} value={p.ulid}>
                                {p.name}
                            </option>
                        ))}
                    </select>
                    <Button type="submit" variant="outline">
                        Save
                    </Button>
                </form>
                <section className="grid gap-2 rounded-lg border p-4 text-sm">
                    <div className="flex items-center justify-between">
                        <h2 className="font-semibold">
                            Income disclosure {disclosure.year}
                        </h2>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => window.print()}
                        >
                            Print
                        </Button>
                    </div>
                    <p>
                        Of {disclosure.agents} agents,{' '}
                        <strong>{disclosure.earning_nothing}</strong> received
                        no revenue share. The median was{' '}
                        <strong>{usd(disclosure.median_cents)}</strong>, the
                        average {usd(disclosure.average_cents)}, the 90th
                        percentile {usd(disclosure.p90_cents)}, and the highest{' '}
                        {usd(disclosure.top_cents)}.
                    </p>
                    <p className="text-muted-foreground">
                        Share this with recruits: revenue share comes only from
                        closed deals, and most agents earn little or nothing
                        from it.
                    </p>
                </section>
                <section className="grid gap-3 rounded-lg border p-4">
                    <h2 className="font-semibold">
                        Simulator: what would a plan have paid over the last 12
                        months?
                    </h2>
                    <RulesForm value={rules} onChange={setRules} fees={[]} />
                    <Button
                        className="justify-self-start"
                        onClick={() => {
                            setError(null);
                            postJson<Sim>(
                                '/compensation/revenue-share/simulate',
                                { rules },
                            )
                                .then(setSim)
                                .catch((e: Error) => setError(e.message));
                        }}
                    >
                        Run simulation
                    </Button>
                    <p className="text-xs text-muted-foreground">
                        Use Advanced (JSON) to set the revenue_share levels and
                        unlocking. Uses today's network; nothing is saved.
                    </p>
                    {error && (
                        <p role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    )}
                    {sim && (
                        <p className="text-sm">
                            {sim.deals} deals: revenue share would be{' '}
                            <strong>{usd(sim.paid_cents)}</strong> (
                            {sim.share_of_company}% of {usd(sim.company_cents)}{' '}
                            company dollar), with {usd(sim.missed_cents)}{' '}
                            missed. By level:{' '}
                            {sim.by_level
                                .map((l) => `${l.level}: ${usd(l.cents)}`)
                                .join(' · ')}
                            .
                        </p>
                    )}
                </section>
            </div>
        </>
    );
}

RevenueShareAdmin.layout = {
    breadcrumbs: [
        { title: 'Revenue share', href: '/compensation/revenue-share' },
    ],
};
