import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Item = {
    ulid: string;
    agent: string;
    moment: string;
    image: string | null;
    caption: string;
    post_at: string | null;
};

function ReviewItem({ item }: { item: Item }) {
    const [why, setWhy] = useState('');
    const decide = (ok: boolean) =>
        router.post(
            `/studio/review/${item.ulid}`,
            { ok, why },
            { preserveScroll: true },
        );

    return (
        <li className="grid gap-3 rounded-xl border p-3 sm:grid-cols-[180px_1fr]">
            {item.image && (
                <img
                    src={item.image}
                    alt={`${item.moment} design`}
                    className="aspect-[4/5] w-full rounded-lg object-cover"
                />
            )}
            <div className="grid content-start gap-2">
                <p className="font-medium">
                    {item.agent} · {item.moment} · {item.post_at}
                </p>
                <p className="text-sm whitespace-pre-wrap">{item.caption}</p>
                <Input
                    placeholder="Why not (only if sending back)"
                    value={why}
                    onChange={(e) => setWhy(e.target.value)}
                />
                <div className="flex gap-2">
                    <Button onClick={() => decide(true)}>Approve</Button>
                    <Button variant="outline" onClick={() => decide(false)}>
                        Send back
                    </Button>
                </div>
            </div>
        </li>
    );
}

/** Ally Studio → Review (specs/grow/13, SA2b): agents' posts waiting for the brokerage before they go out. */
export default function StudioReview({ items }: { items: Item[] }) {
    return (
        <>
            <Head title="Review Studio posts" />
            <div className="grid max-w-4xl gap-6 p-4 text-base">
                <PageHeader
                    title="Review"
                    description="Agents' Ally Studio posts, waiting for the brokerage's OK."
                />
                {items.length === 0 ? (
                    <EmptyState
                        title="Nothing to review"
                        description="New agent posts appear here and in your daily brief."
                    />
                ) : (
                    <ul className="grid gap-3">
                        {items.map((i) => (
                            <ReviewItem key={i.ulid} item={i} />
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
