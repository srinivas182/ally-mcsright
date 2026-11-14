import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

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
        mine: boolean;
        reviewer: string | null;
    }[];
    reasons: string[];
};

/** Documents waiting for review, oldest first: assigned to the reviewer with the lightest load. */
export default function ReviewQueue({ sla_hours, items }: Props) {
    const [onlyMine, setOnlyMine] = useState(true);
    const [picked, setPicked] = useState<string[]>([]);
    const shown =
        onlyMine && items.some((i) => i.mine)
            ? items.filter((i) => i.mine)
            : items;
    const toggle = (ulid: string) =>
        setPicked(
            picked.includes(ulid)
                ? picked.filter((p) => p !== ulid)
                : [...picked, ulid],
        );

    return (
        <>
            <Head title="Review queue" />
            <div className="flex max-w-4xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Review queue"
                    description={`${items.length} waiting · reviews are due within ${sla_hours} hours of upload.`}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/deals/compliance">
                                Compliance checks and rules
                            </Link>
                        </Button>
                    }
                />
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <div
                        role="group"
                        aria-label="Whose reviews"
                        className="flex rounded border"
                    >
                        <button
                            type="button"
                            aria-pressed={onlyMine}
                            className={`px-3 py-1.5 ${onlyMine ? 'bg-muted font-medium' : ''}`}
                            onClick={() => setOnlyMine(true)}
                        >
                            Mine
                        </button>
                        <button
                            type="button"
                            aria-pressed={!onlyMine}
                            className={`px-3 py-1.5 ${!onlyMine ? 'bg-muted font-medium' : ''}`}
                            onClick={() => setOnlyMine(false)}
                        >
                            Everyone
                        </button>
                    </div>
                    <Button
                        size="sm"
                        disabled={picked.length === 0}
                        onClick={() =>
                            router.post(
                                '/deals/review/bulk',
                                { items: picked },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setPicked([]),
                                },
                            )
                        }
                    >
                        Approve {picked.length || ''} selected
                    </Button>
                </div>
                <ul className="divide-y rounded-lg border text-sm">
                    {shown.length === 0 && (
                        <li className="p-3 text-muted-foreground">
                            Nothing waiting. Nice.
                        </li>
                    )}
                    {shown.map((i) => (
                        <li
                            key={i.ulid}
                            className="flex flex-wrap items-center justify-between gap-2 p-3"
                        >
                            <label className="flex items-start gap-2">
                                <input
                                    type="checkbox"
                                    className="mt-1 size-4"
                                    checked={picked.includes(i.ulid)}
                                    onChange={() => toggle(i.ulid)}
                                    aria-label={`Select ${i.label}`}
                                />
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
                                        {i.reviewer
                                            ? ` · for ${i.reviewer}`
                                            : ''}
                                    </span>
                                </span>
                            </label>
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
