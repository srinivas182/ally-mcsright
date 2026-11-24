import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, Plus } from 'lucide-react';
import { useState } from 'react';

import { NewDealForm, typeLabel } from '@/components/deals/new-deal-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Deal = {
    ulid: string;
    street: string;
    city: string | null;
    type: string;
    price_cents: number;
    stage: string;
    client: string | null;
    agent: string | null;
    days: number;
};

type Props = {
    stages: { key: string; label: string }[];
    deals: Deal[];
    canCreateFor: { ulid: string; name: string }[];
};

/**
 * PG1: the CRM pipeline. Deals that are not under contract yet, by stage; drag a card or use its buttons to move
 * it. When the contract is signed, the deal goes under contract and moves to Transactions.
 */
export default function Pipeline({ stages, deals, canCreateFor }: Props) {
    const [adding, setAdding] = useState(false);
    const [dragging, setDragging] = useState<string | null>(null);
    const [over, setOver] = useState<string | null>(null);
    const move = (deal: string, stage: string) =>
        router.post(
            `/pipeline/${deal}/stage`,
            { stage },
            { preserveScroll: true, preserveState: true },
        );
    const total = deals.reduce((s, d) => s + d.price_cents, 0);

    return (
        <>
            <Head title="Pipeline" />
            <div className="flex flex-col gap-5 p-4 md:p-6">
                <PageHeader
                    title="Pipeline"
                    description="Deals you are working on that are not under contract yet. When the contract is signed, open the deal and put it under contract: it moves to Transactions."
                    actions={
                        <Button onClick={() => setAdding(!adding)}>
                            <Plus aria-hidden="true" /> Add a deal
                        </Button>
                    }
                />
                {adding && <NewDealForm canCreateFor={canCreateFor} />}
                <p className="text-sm text-muted-foreground">
                    {deals.length} {deals.length === 1 ? 'deal' : 'deals'} in
                    the pipeline{total > 0 ? `, ${usd(total)} in prices` : ''}.
                    Drag a card to move it, or use the arrow on the card.
                </p>
                <div className="grid gap-4 lg:grid-cols-3">
                    {stages.map((s, i) => {
                        const list = deals.filter((d) => d.stage === s.key);
                        const next = stages[i + 1];

                        return (
                            <div
                                key={s.key}
                                onDragOver={(e) => {
                                    e.preventDefault();
                                    setOver(s.key);
                                }}
                                onDragLeave={() => setOver(null)}
                                onDrop={(e) => {
                                    e.preventDefault();
                                    setOver(null);
                                    if (dragging) {
                                        move(dragging, s.key);
                                    }
                                    setDragging(null);
                                }}
                                className={`grid content-start gap-2 rounded-xl p-3 transition-colors ${
                                    over === s.key
                                        ? 'bg-primary/10 ring-2 ring-primary/40'
                                        : 'bg-muted/40'
                                }`}
                            >
                                <h2
                                    id={`stage-${s.key}`}
                                    className="flex items-center justify-between px-1 font-semibold"
                                >
                                    {s.label}
                                    <Badge variant="secondary">
                                        {list.length}
                                    </Badge>
                                </h2>
                                {list.length === 0 && (
                                    <p className="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">
                                        {i === 0
                                            ? 'New deals start here.'
                                            : 'Drag a deal here.'}
                                    </p>
                                )}
                                {list.map((d) => (
                                    <div
                                        key={d.ulid}
                                        draggable
                                        onDragStart={() => setDragging(d.ulid)}
                                        onDragEnd={() => setDragging(null)}
                                        className="grid cursor-grab gap-1 rounded-lg border bg-card p-3 text-sm shadow-xs active:cursor-grabbing"
                                    >
                                        <Link
                                            href={`/deals/${d.ulid}`}
                                            className="font-semibold hover:underline"
                                        >
                                            {d.street}
                                            {d.city ? `, ${d.city}` : ''}
                                        </Link>
                                        <span className="text-muted-foreground">
                                            {typeLabel[d.type] ?? d.type}
                                            {d.client ? ` · ${d.client}` : ''}
                                            {d.price_cents
                                                ? ` · ${usd(d.price_cents)}`
                                                : ''}
                                        </span>
                                        <span className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                                            <span>
                                                {d.agent ? `${d.agent} · ` : ''}
                                                {d.days === 0
                                                    ? 'Moved here today'
                                                    : `${d.days} ${d.days === 1 ? 'day' : 'days'} in this stage`}
                                            </span>
                                            {next ? (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    className="h-7 px-2"
                                                    aria-label={`Move ${d.street} to ${next.label}`}
                                                    onClick={() =>
                                                        move(d.ulid, next.key)
                                                    }
                                                >
                                                    <ArrowRight aria-hidden="true" />
                                                </Button>
                                            ) : (
                                                <Link
                                                    href={`/deals/${d.ulid}`}
                                                    className="font-medium text-primary hover:underline"
                                                >
                                                    Signed? Put it under
                                                    contract
                                                </Link>
                                            )}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        );
                    })}
                </div>
            </div>
        </>
    );
}

Pipeline.layout = { breadcrumbs: [{ title: 'Pipeline', href: '/pipeline' }] };
