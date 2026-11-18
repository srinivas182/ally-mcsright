import { Head, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Request = {
    ulid: string;
    about: string;
    action: string;
    needed: number;
    status: string;
    result: string | null;
    asked_by: string;
    asked_at: string;
    mine: boolean;
    votes: { name: string; decision: string }[];
};

type Props = {
    rules: { action: string; label: string; needed: number }[];
    waiting: Request[];
    closed: Request[];
    canSetRules: boolean;
    canApprove: boolean;
};

const statusLabel: Record<string, string> = {
    approved: 'Approved',
    declined: 'Declined',
    expired: 'No answer in 7 days',
    failed: 'Could not be done',
};

/** FL1 (specs/core/16): how many people must approve sensitive changes, and the changes waiting for approval. */
export default function Approvals({
    rules,
    waiting,
    closed,
    canSetRules,
    canApprove,
}: Props) {
    const opts = { preserveScroll: true };

    return (
        <>
            <Head title="Approvals" />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Approvals"
                    description="Sensitive changes can wait until other people approve them. The person who asks can never approve their own change."
                />

                <section className="grid gap-3 text-base">
                    <h2 className="text-lg font-semibold">
                        Waiting for approval
                    </h2>
                    {waiting.length === 0 && (
                        <p className="text-muted-foreground">
                            Nothing is waiting.
                        </p>
                    )}
                    {waiting.map((r) => (
                        <div
                            key={r.ulid}
                            className="grid gap-2 rounded-lg border p-4"
                        >
                            <p className="font-medium">{r.about}</p>
                            <p className="text-sm text-muted-foreground">
                                Asked by {r.asked_by} ·{' '}
                                {new Date(r.asked_at).toLocaleString()} ·{' '}
                                {
                                    r.votes.filter(
                                        (v) => v.decision === 'approve',
                                    ).length
                                }{' '}
                                of {r.needed} approvals
                            </p>
                            {canApprove && !r.mine && (
                                <div className="flex gap-2">
                                    <Button
                                        onClick={() =>
                                            router.post(
                                                `/settings/approvals/${r.ulid}`,
                                                { approve: true },
                                                opts,
                                            )
                                        }
                                    >
                                        Approve
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                `/settings/approvals/${r.ulid}`,
                                                { approve: false },
                                                opts,
                                            )
                                        }
                                    >
                                        Decline
                                    </Button>
                                </div>
                            )}
                            {r.mine && (
                                <p className="text-sm">
                                    You asked for this; someone else approves
                                    it.
                                </p>
                            )}
                        </div>
                    ))}
                </section>

                <section className="grid gap-3 text-base">
                    <h2 className="text-lg font-semibold">Rules</h2>
                    <ul className="grid gap-2">
                        {rules.map((rule) => (
                            <li
                                key={rule.action}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3"
                            >
                                <span>{rule.label}</span>
                                {canSetRules ? (
                                    <select
                                        aria-label={`Approvals needed: ${rule.label}`}
                                        className="h-10 rounded-md border bg-background px-3"
                                        value={rule.needed}
                                        onChange={(e) =>
                                            router.post(
                                                '/settings/approvals/rules',
                                                {
                                                    action: rule.action,
                                                    needed: Number(
                                                        e.target.value,
                                                    ),
                                                },
                                                opts,
                                            )
                                        }
                                    >
                                        <option value={0}>
                                            No approval needed
                                        </option>
                                        <option value={1}>
                                            1 person approves
                                        </option>
                                        <option value={2}>
                                            2 people approve
                                        </option>
                                        <option value={3}>
                                            3 people approve
                                        </option>
                                    </select>
                                ) : (
                                    <Badge variant="outline">
                                        {rule.needed === 0
                                            ? 'No approval needed'
                                            : `${rule.needed} must approve`}
                                    </Badge>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>

                {closed.length > 0 && (
                    <section className="grid gap-2 text-base">
                        <h2 className="text-lg font-semibold">Recent</h2>
                        <ul className="divide-y rounded-lg border">
                            {closed.map((r) => (
                                <li key={r.ulid} className="grid gap-1 p-3">
                                    <span className="font-medium">
                                        {r.about}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {statusLabel[r.status] ?? r.status}
                                        {r.result ? ` · ${r.result}` : ''}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}
