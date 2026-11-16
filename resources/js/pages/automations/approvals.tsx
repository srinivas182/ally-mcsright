import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Props = {
    approvals: {
        ulid: string;
        question: string;
        workflow: string;
        subject: string;
        needed: number;
        votes: number;
        asked_at: string;
    }[];
};

/** Approvals automations are waiting for (specs/core/06, W1). */
export default function Approvals({ approvals }: Props) {
    const [comment, setComment] = useState<Record<string, string>>({});
    const [error, setError] = useState<string | null>(null);
    const decide = (ulid: string, approve: boolean) =>
        router.post(
            `/automations/approvals/${ulid}`,
            { approve, comment: comment[ulid] ?? '' },
            {
                preserveScroll: true,
                onError: (e) => setError(Object.values(e)[0] ?? null),
            },
        );

    return (
        <>
            <Head title="Approvals" />
            <div className="flex flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Approvals"
                    description="Automations waiting for your decision. Whoever set one off can never approve it."
                />
                {error && (
                    <p role="alert" className="text-sm text-destructive">
                        {error}
                    </p>
                )}
                {approvals.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        Nothing is waiting for you.
                    </p>
                )}
                <ul className="grid gap-3">
                    {approvals.map((a) => (
                        <li
                            key={a.ulid}
                            className="grid gap-2 rounded-lg border p-4 text-sm"
                        >
                            <p className="font-medium">{a.question}</p>
                            <p className="text-muted-foreground">
                                {a.subject} · {a.workflow} · {a.votes} of{' '}
                                {a.needed} approvals so far ·{' '}
                                {new Date(a.asked_at).toLocaleString()}
                            </p>
                            <Input
                                aria-label="Comment (optional)"
                                placeholder="Comment (optional)"
                                value={comment[a.ulid] ?? ''}
                                onChange={(e) =>
                                    setComment({
                                        ...comment,
                                        [a.ulid]: e.target.value,
                                    })
                                }
                            />
                            <span className="flex gap-2">
                                <Button onClick={() => decide(a.ulid, true)}>
                                    Approve
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => decide(a.ulid, false)}
                                >
                                    Decline
                                </Button>
                            </span>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

Approvals.layout = {
    breadcrumbs: [{ title: 'Approvals', href: '/automations/approvals' }],
};
