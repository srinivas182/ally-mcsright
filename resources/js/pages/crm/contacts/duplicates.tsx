import { Head, router } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';

type Side = {
    ulid: string;
    name: string;
    email: string | null;
    phone: string | null;
};
type Props = { pairs: { reason: string; keep: Side; duplicate: Side }[] };

function Card({ side }: { side: Side }) {
    return (
        <span className="grid gap-0.5 text-sm">
            <span className="font-medium">{side.name}</span>
            <span className="text-muted-foreground">
                {[side.email, side.phone].filter(Boolean).join(' · ') || '—'}
            </span>
        </span>
    );
}

export default function Duplicates({ pairs }: Props) {
    const merge = (keep: string, duplicate: string) =>
        router.post(
            '/contacts/merge',
            { keep, duplicate },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Possible duplicates" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Possible duplicates"
                    description="Merging keeps everything: emails, phones, timeline, tasks, tags. Any opt-out or Do Not Call is kept."
                />
                {pairs.length === 0 ? (
                    <EmptyState title="No duplicates found" />
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {pairs.map((p) => (
                            <li
                                key={`${p.keep.ulid}-${p.duplicate.ulid}`}
                                className="grid gap-3 p-4 md:grid-cols-[1fr_1fr_auto] md:items-center"
                            >
                                <Card side={p.keep} />
                                <Card side={p.duplicate} />
                                <span className="flex flex-wrap gap-2">
                                    <span className="self-center text-xs text-muted-foreground">
                                        {p.reason}
                                    </span>
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            merge(p.keep.ulid, p.duplicate.ulid)
                                        }
                                    >
                                        Keep first
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            merge(p.duplicate.ulid, p.keep.ulid)
                                        }
                                    >
                                        Keep second
                                    </Button>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

Duplicates.layout = {
    breadcrumbs: [
        { title: 'Contacts', href: '/contacts' },
        { title: 'Duplicates', href: '/contacts/duplicates' },
    ],
};
