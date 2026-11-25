import { Head, Link, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';

import { FromTheMls, type MlsBoardData } from '@/components/deals/from-the-mls';
import { NewDealForm } from '@/components/deals/new-deal-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';
import { cn } from '@/lib/utils';

/** One colour per stage, on the column dot and each card's edge. */
const DOT: Record<string, string> = {
    getting_ready: 'bg-slate-400',
    active: 'bg-emerald-500',
    under_contract: 'bg-amber-500',
    sold: 'bg-blue-600',
};
const BORDER: Record<string, string> = {
    getting_ready: 'border-l-slate-400',
    active: 'border-l-emerald-500',
    under_contract: 'border-l-amber-500',
    sold: 'border-l-blue-600',
};

type Card = {
    ulid: string;
    street: string;
    city: string | null;
    price_cents: number;
    agent: string;
    days: number | null;
    expires_on: string | null;
    expires_in: number | null;
    closing_date: string | null;
    warning: string | null;
};
type Props = {
    board: {
        columns: Record<string, Card[]>;
        totals: Record<string, { count: number; volume_cents: number }>;
        expiring: number;
    };
    columns: Record<string, string>;
    mine: boolean;
    reviewer: boolean;
    canCreateFor: { ulid: string; name: string }[];
    mls?: MlsBoardData;
    isOwner?: boolean;
};

/** LM1: the listing inventory board, from getting ready to sold, with listing agreements about to end. */
export default function ListingsBoard({
    board,
    columns,
    mine,
    reviewer,
    canCreateFor,
    mls,
    isOwner = false,
}: Props) {
    const [adding, setAdding] = useState(false);

    return (
        <>
            <Head title="My listings" />
            <div className="mx-auto grid w-full max-w-7xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="My listings"
                    description="Every listing from getting ready to sold. Add each listing agreement's end date so none expires without a talk with the seller."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {reviewer && (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={
                                            mine
                                                ? '/deals/listings'
                                                : '/deals/listings?mine=1'
                                        }
                                    >
                                        {mine
                                            ? 'Show the whole brokerage'
                                            : 'Show only mine'}
                                    </Link>
                                </Button>
                            )}
                            <Button onClick={() => setAdding(!adding)}>
                                <Plus aria-hidden="true" /> Add a listing
                            </Button>
                        </div>
                    }
                />
                {adding && (
                    <div className="grid gap-2">
                        <p className="text-sm text-muted-foreground">
                            A new listing starts in Getting ready. Add its MLS
                            number when it goes live and it moves to On the
                            market.
                        </p>
                        <NewDealForm
                            canCreateFor={canCreateFor}
                            type="listing"
                        />
                    </div>
                )}
                {mls && (
                    <FromTheMls
                        data={mls}
                        isOwner={isOwner}
                        reviewer={reviewer}
                    />
                )}
                {board.expiring > 0 && (
                    <p
                        role="status"
                        className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-base text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100"
                    >
                        {board.expiring} listing agreement
                        {board.expiring === 1 ? '' : 's'} end
                        {board.expiring === 1 ? 's' : ''} within 30 days. Talk
                        to the seller about extending.
                    </p>
                )}
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    {Object.entries(columns).map(([key, label]) => (
                        <section
                            key={key}
                            aria-labelledby={`col-${key}`}
                            className="grid content-start gap-3 rounded-xl border bg-muted/40 p-3"
                        >
                            <h2
                                id={`col-${key}`}
                                className="flex items-center gap-2 px-1 font-semibold"
                            >
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'size-2.5 rounded-full',
                                        DOT[key],
                                    )}
                                />
                                {label}
                                <span className="ml-auto rounded-full bg-background px-2 py-0.5 text-xs font-medium text-muted-foreground">
                                    {board.totals[key]?.count ?? 0}
                                </span>
                            </h2>
                            <p className="-mt-2 px-1 text-sm text-muted-foreground tabular-nums">
                                {usd(board.totals[key]?.volume_cents ?? 0)} in
                                prices
                            </p>
                            {(board.columns[key] ?? []).length === 0 && (
                                <p className="rounded-lg border border-dashed bg-background/60 px-3 py-6 text-center text-sm text-muted-foreground">
                                    None right now.
                                </p>
                            )}
                            {(board.columns[key] ?? []).map((c) => (
                                <ListingCard
                                    key={c.ulid}
                                    card={c}
                                    column={key}
                                />
                            ))}
                        </section>
                    ))}
                </div>
            </div>
        </>
    );
}

function ListingCard({ card, column }: { card: Card; column: string }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({
        listing_expires_on: card.expires_on ?? '',
        mls_number: '',
    });

    return (
        <article
            className={cn(
                'grid gap-1.5 rounded-lg border border-l-4 bg-background p-3 text-sm shadow-xs',
                BORDER[column],
            )}
        >
            <Link
                href={`/deals/${card.ulid}`}
                className="text-base font-medium underline-offset-2 hover:underline"
            >
                {card.street}
            </Link>
            <p className="text-muted-foreground">
                {[card.city, card.agent].filter(Boolean).join(' · ')}
            </p>
            <p>
                <span className="text-base font-semibold tabular-nums">
                    {usd(card.price_cents)}
                </span>
                {card.days !== null && (
                    <span className="text-muted-foreground">
                        {' '}
                        ·{' '}
                        {column === 'under_contract'
                            ? `${card.days} days under contract`
                            : `${card.days} days listed`}
                    </span>
                )}
            </p>
            {card.closing_date && column === 'under_contract' && (
                <p className="text-muted-foreground">
                    Closing {card.closing_date}
                </p>
            )}
            {card.warning && (
                <Badge variant="destructive" className="w-fit">
                    {card.warning}
                </Badge>
            )}
            {(column === 'getting_ready' || column === 'active') &&
                (editing ? (
                    <form
                        className="grid gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`/deals/${card.ulid}/listing`, {
                                preserveScroll: true,
                                onSuccess: () => setEditing(false),
                            });
                        }}
                    >
                        <label className="grid gap-1">
                            Listing agreement ends
                            <Input
                                type="date"
                                value={form.data.listing_expires_on}
                                onChange={(e) =>
                                    form.setData(
                                        'listing_expires_on',
                                        e.target.value,
                                    )
                                }
                            />
                        </label>
                        {column === 'getting_ready' && (
                            <label className="grid gap-1">
                                MLS number (moves it to On the market)
                                <Input
                                    value={form.data.mls_number}
                                    onChange={(e) =>
                                        form.setData(
                                            'mls_number',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                        )}
                        <div className="flex gap-2">
                            <Button size="sm" disabled={form.processing}>
                                Save
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                type="button"
                                onClick={() => setEditing(false)}
                            >
                                Cancel
                            </Button>
                        </div>
                    </form>
                ) : (
                    <Button
                        size="sm"
                        variant="ghost"
                        className="w-fit"
                        onClick={() => setEditing(true)}
                    >
                        {card.expires_on
                            ? `Agreement ends ${card.expires_on} (change)`
                            : 'Add agreement end date'}
                    </Button>
                ))}
        </article>
    );
}
