import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { Textarea } from '@/components/ui/textarea';
import { usd } from '@/lib/cents';

type Adjustment = {
    ulid: string;
    description: string;
    effective_date: string;
    total_cents: number;
    status: 'waiting' | 'approved' | 'declined' | 'withdrawn';
    requested_by: string;
    mine: boolean;
    decided_by: string | null;
    decided_at: string | null;
    decision_note: string | null;
    self_approved: boolean;
    journal: string | null;
    lines: {
        account: string;
        side: 'debit' | 'credit';
        amount: string;
        memo: string | null;
    }[];
};

type Props = {
    adjustments: Adjustment[];
    can: { adjust: boolean; approve: boolean; sole_approver: boolean };
};

const STATUS: Record<Adjustment['status'], string> = {
    waiting: 'Waiting for approval',
    approved: 'Approved',
    declined: 'Declined',
    withdrawn: 'Withdrawn',
};

/** FH1: manual adjustments change the books only after a second person approves them. */
export default function AdjustmentsIndex({ adjustments, can }: Props) {
    return (
        <>
            <Head title="Adjustments" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Adjustments"
                    description="Corrections to the books entered by hand. Each one needs a second person to approve it before the books change."
                    actions={
                        can.adjust && (
                            <Button asChild size="lg">
                                <Link href="/finance/adjustments/create">
                                    New adjustment
                                </Link>
                            </Button>
                        )
                    }
                />
                {adjustments.length === 0 && (
                    <p className="text-muted-foreground">No adjustments yet.</p>
                )}
                <ul className="grid gap-4">
                    {adjustments.map((a) => (
                        <AdjustmentCard key={a.ulid} adjustment={a} can={can} />
                    ))}
                </ul>
            </div>
        </>
    );
}

function AdjustmentCard({
    adjustment: a,
    can,
}: {
    adjustment: Adjustment;
    can: Props['can'];
}) {
    const [note, setNote] = useState('');
    const decide = (decision: 'approve' | 'decline' | 'withdraw') =>
        router.post(
            `/finance/adjustments/${a.ulid}/decide`,
            { decision, note: note || null },
            { preserveScroll: true },
        );
    const canDecide =
        a.status === 'waiting' && can.approve && (!a.mine || can.sole_approver);

    return (
        <li className="grid gap-3 rounded-lg border p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="text-lg font-medium">{a.description}</p>
                    <p className="text-sm text-muted-foreground">
                        {usd(a.total_cents)} on {a.effective_date}, entered by{' '}
                        {a.requested_by}
                    </p>
                </div>
                <Badge
                    variant={a.status === 'waiting' ? 'default' : 'secondary'}
                >
                    {STATUS[a.status]}
                </Badge>
            </div>
            <table className="w-full text-sm">
                <thead className="text-left text-muted-foreground">
                    <tr>
                        <th className="py-1 font-medium">Account</th>
                        <th className="py-1 text-right font-medium">Debit</th>
                        <th className="py-1 text-right font-medium">Credit</th>
                    </tr>
                </thead>
                <tbody>
                    {a.lines.map((l, i) => (
                        <tr key={i} className="border-t">
                            <td className="py-1">
                                {l.account}
                                {l.memo ? ` — ${l.memo}` : ''}
                            </td>
                            <td className="py-1 text-right">
                                {l.side === 'debit' ? l.amount : ''}
                            </td>
                            <td className="py-1 text-right">
                                {l.side === 'credit' ? l.amount : ''}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            {a.decided_by && (
                <p className="text-sm">
                    {STATUS[a.status]} by {a.decided_by}
                    {a.self_approved &&
                        ' (the only approver, approving their own entry)'}
                    {a.decision_note && `: “${a.decision_note}”`}
                    {a.journal && (
                        <>
                            {' '}
                            <Link
                                className="underline"
                                href={`/finance/journals/${a.journal}`}
                            >
                                See the entry
                            </Link>
                        </>
                    )}
                </p>
            )}
            {a.status === 'waiting' && a.mine && !can.sole_approver && (
                <div className="flex flex-wrap items-center gap-3">
                    <p className="text-sm text-muted-foreground">
                        Waiting for someone else to approve it.
                    </p>
                    <Button
                        variant="outline"
                        onClick={() => decide('withdraw')}
                    >
                        Withdraw
                    </Button>
                </div>
            )}
            {canDecide && (
                <div className="grid gap-2">
                    <label className="grid gap-1 text-sm">
                        {a.mine
                            ? 'You are the only approver. Write why this adjustment is right.'
                            : 'Note (needed if you decline)'}
                        <Textarea
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                            rows={2}
                            maxLength={255}
                        />
                    </label>
                    <div className="flex flex-wrap gap-3">
                        <Button size="lg" onClick={() => decide('approve')}>
                            Approve
                        </Button>
                        <Button
                            size="lg"
                            variant="outline"
                            onClick={() =>
                                decide(a.mine ? 'withdraw' : 'decline')
                            }
                        >
                            {a.mine ? 'Withdraw' : 'Decline'}
                        </Button>
                    </div>
                </div>
            )}
        </li>
    );
}

AdjustmentsIndex.layout = {
    breadcrumbs: [{ title: 'Adjustments', href: '/finance/adjustments' }],
};
