import { Head, router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { StatCard } from '@/components/ui/stat-card';

type Props = {
    overview: {
        review: {
            ulid: string;
            mls: string;
            id: string;
            name: string;
            listings: number;
            sample: string[];
            typed_by: string | null;
        }[];
        waiting: { name: string; id: string }[];
        missing: { ulid: string; name: string }[];
        matched: number;
        people: { ulid: string; name: string }[];
    };
    boards: { id: number; name: string }[];
};

/**
 * ML1: agents' MLS IDs. Found in the MLS feed every night and confirmed by each agent; the owner only checks the ones
 * whose name did not match anyone, and sees who still has none.
 */
export default function MlsIds({ overview, boards }: Props) {
    return (
        <>
            <Head title="Agents' MLS IDs" />
            <div className="mx-auto grid w-full max-w-5xl gap-5 p-4 md:p-6">
                <PageHeader
                    title="Agents' MLS IDs"
                    description="Found on your listings in the MLS every night. Each agent confirms their own with one click; you only check the ones we could not match."
                    actions={
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    '/roster/mls-ids/scan',
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <RefreshCw aria-hidden="true" /> Check the MLS now
                        </Button>
                    }
                />
                {boards.length === 0 ? (
                    <p className="rounded-xl border bg-card p-5">
                        Your brokerage has no MLS board yet. Add one in Settings
                        › MLS, then come back here.
                    </p>
                ) : (
                    <>
                        <div className="grid gap-3 sm:grid-cols-4">
                            <StatCard label="Saved" value={overview.matched} />
                            <StatCard
                                label="Waiting for the agent"
                                value={overview.waiting.length}
                            />
                            <StatCard
                                label="Please check"
                                value={overview.review.length}
                            />
                            <StatCard
                                label="No MLS ID yet"
                                value={overview.missing.length}
                            />
                        </div>

                        <section aria-labelledby="check" className="grid gap-3">
                            <h2 id="check" className="text-lg font-semibold">
                                Please check
                            </h2>
                            {overview.review.length === 0 && (
                                <p className="text-muted-foreground">
                                    Nothing to check.
                                </p>
                            )}
                            {overview.review.map((r) => (
                                <ReviewRow
                                    key={r.ulid}
                                    row={r}
                                    people={overview.people}
                                />
                            ))}
                        </section>

                        {overview.waiting.length > 0 && (
                            <section
                                aria-labelledby="waiting"
                                className="grid gap-2"
                            >
                                <h2
                                    id="waiting"
                                    className="text-lg font-semibold"
                                >
                                    Waiting for the agent to confirm
                                </h2>
                                <ul className="grid gap-1 rounded-xl border bg-card p-4 text-sm">
                                    {overview.waiting.map((w) => (
                                        <li
                                            key={w.id}
                                            className="flex justify-between gap-3"
                                        >
                                            <span>{w.name}</span>
                                            <span className="text-muted-foreground">
                                                MLS ID {w.id}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}

                        <section
                            aria-labelledby="missing"
                            className="grid gap-2"
                        >
                            <h2 id="missing" className="text-lg font-semibold">
                                No MLS ID yet
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                These agents have no listings in the MLS yet.
                                Their dashboard asks them to type their MLS ID
                                once.
                            </p>
                            {overview.missing.length === 0 ? (
                                <p className="text-muted-foreground">
                                    Everyone has one.
                                </p>
                            ) : (
                                <ul className="flex flex-wrap gap-2">
                                    {overview.missing.map((m) => (
                                        <li key={m.ulid}>
                                            <Badge variant="outline">
                                                {m.name}
                                            </Badge>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    </>
                )}
            </div>
        </>
    );
}

function ReviewRow({
    row,
    people,
}: {
    row: Props['overview']['review'][number];
    people: Props['overview']['people'];
}) {
    const [who, setWho] = useState('');

    return (
        <article className="grid gap-3 rounded-xl border bg-card p-4">
            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <p className="font-semibold">
                    {row.name || 'No name in the MLS'}
                </p>
                <p className="text-sm text-muted-foreground">
                    {row.mls} · MLS ID {row.id} · {row.listings}{' '}
                    {row.listings === 1 ? 'listing' : 'listings'}
                </p>
            </div>
            {row.typed_by && (
                <p className="text-sm">
                    {row.typed_by} typed this ID, but the MLS shows another name
                    on its listings.
                </p>
            )}
            {row.sample.length > 0 && (
                <p className="text-sm text-muted-foreground">
                    {row.sample.join(' · ')}
                </p>
            )}
            <div className="flex flex-wrap items-center gap-2">
                <select
                    aria-label={`Who is ${row.name}?`}
                    className="h-10 min-w-56 rounded-md border bg-background px-3"
                    value={who}
                    onChange={(e) => setWho(e.target.value)}
                >
                    <option value="">Who is this?</option>
                    {people.map((p) => (
                        <option key={p.ulid} value={p.ulid}>
                            {p.name}
                        </option>
                    ))}
                </select>
                <Button
                    disabled={who === ''}
                    onClick={() =>
                        router.post(
                            `/roster/mls-ids/${row.ulid}/assign`,
                            { agent: who },
                            { preserveScroll: true },
                        )
                    }
                >
                    Save for this person
                </Button>
                <Button
                    variant="ghost"
                    onClick={() =>
                        router.post(
                            `/roster/mls-ids/${row.ulid}/assign`,
                            { agent: null },
                            { preserveScroll: true },
                        )
                    }
                >
                    Not one of ours
                </Button>
            </div>
        </article>
    );
}

MlsIds.layout = {
    breadcrumbs: [{ title: "Agents' MLS IDs", href: '/roster/mls-ids' }],
};
