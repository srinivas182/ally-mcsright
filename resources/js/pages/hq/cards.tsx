import { Head, router } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Card = {
    id: number;
    to: string;
    city: string;
    from: string;
    occasion: string;
    message: string;
    created_at: string;
};

/** HQ → Handwritten cards: send the queue to the handwriting partner, then mark them mailed. */
export default function HqCards({ queued }: { queued: Card[] }) {
    return (
        <>
            <Head title="Handwritten cards" />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Handwritten cards"
                    description="Cards waiting for the handwriting partner. Download the CSV, upload it to the partner, then mark them mailed."
                    actions={
                        <div className="flex gap-2">
                            <Button variant="outline" asChild>
                                <a href="/hq/cards/export.csv">Download CSV</a>
                            </Button>
                            <Button
                                disabled={queued.length === 0}
                                onClick={() =>
                                    router.post('/hq/cards/sent', {
                                        ids: queued.map((c) => c.id),
                                    })
                                }
                            >
                                Mark all mailed
                            </Button>
                        </div>
                    }
                />
                {queued.length === 0 && (
                    <p className="text-muted-foreground">Nothing waiting.</p>
                )}
                <ul className="grid gap-2 text-sm">
                    {queued.map((c) => (
                        <li
                            key={c.id}
                            className="grid gap-1 rounded-lg border p-3"
                        >
                            <span className="font-medium">
                                {c.to} · {c.city}
                            </span>
                            <span className="text-muted-foreground">
                                {c.occasion} · from {c.from} · {c.created_at}
                            </span>
                            <span>{c.message}</span>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}
