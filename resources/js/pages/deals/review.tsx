import { Head, Link, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Props = {
    sla_hours: number;
    items: {
        ulid: string;
        label: string;
        deal: string;
        street: string;
        agent: string | null;
        submitted_at: string | null;
        overdue: boolean;
    }[];
    reasons: string[];
};

/** Documents waiting for review across all deals, oldest first. */
export default function ReviewQueue({ sla_hours, items }: Props) {
    return (
        <>
            <Head title="Review queue" />
            <div className="flex max-w-4xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Review queue"
                    description={`${items.length} waiting · reviews are due within ${sla_hours} hours of upload.`}
                />
                <ul className="divide-y rounded-lg border text-sm">
                    {items.length === 0 && (
                        <li className="p-3 text-muted-foreground">
                            Nothing waiting. Nice.
                        </li>
                    )}
                    {items.map((i) => (
                        <li
                            key={i.ulid}
                            className="flex flex-wrap items-center justify-between gap-2 p-3"
                        >
                            <span className="grid">
                                <Link
                                    className="font-medium hover:underline"
                                    href={`/deals/${i.deal}`}
                                >
                                    {i.label}
                                </Link>
                                <span className="text-muted-foreground">
                                    {i.street} · {i.agent} ·{' '}
                                    {i.submitted_at
                                        ? new Date(
                                              i.submitted_at,
                                          ).toLocaleString()
                                        : ''}
                                </span>
                            </span>
                            <span className="flex items-center gap-2">
                                {i.overdue && (
                                    <Badge variant="destructive">Overdue</Badge>
                                )}
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            `/deals/items/${i.ulid}/review`,
                                            { action: 'approve' },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Approve
                                </Button>
                                <Button size="sm" variant="outline" asChild>
                                    <Link href={`/deals/${i.deal}`}>Open</Link>
                                </Button>
                            </span>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

ReviewQueue.layout = {
    breadcrumbs: [{ title: 'Review queue', href: '/deals/review' }],
};
