import { Head } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Props = {
    link: string;
    stats: {
        clicks_30d: number;
        joined: number;
        pending_cents: number;
        approved_cents: number;
        paid_cents: number;
        referrals: {
            brokerage: string;
            status: string;
            reward_cents: number;
            joined: string;
        }[];
    };
    months: number;
    hold_days: number;
};

const STATUS: Record<string, string> = {
    pending: 'Waiting',
    approved: 'Confirmed',
    paid: 'Credited',
    void: 'Not eligible',
};

/** RF1: a brokerage refers another brokerage to RightAlly and earns a month's credit. */
export default function Refer({ link, stats, months, hold_days }: Props) {
    const [copied, setCopied] = useState(false);

    return (
        <>
            <Head title="Refer a brokerage" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Refer a brokerage"
                    description={`Know a broker who'd like RightAlly? Share your link. When their brokerage joins and stays past ${hold_days} days, you get ${months === 1 ? 'one month' : `${months} months`} of your RightAlly free.`}
                />
                <div className="flex flex-wrap items-center gap-3 rounded-lg border p-4">
                    <code className="text-base break-all">{link}</code>
                    <Button
                        size="lg"
                        onClick={() => {
                            void navigator.clipboard?.writeText(link);
                            setCopied(true);
                        }}
                    >
                        {copied ? 'Copied' : 'Copy link'}
                    </Button>
                </div>
                <ul className="grid gap-1 text-base">
                    <li>
                        Visits in the last 30 days:{' '}
                        <strong>{stats.clicks_30d}</strong>
                    </li>
                    <li>
                        Brokerages that joined: <strong>{stats.joined}</strong>
                    </li>
                    <li>
                        Credit waiting:{' '}
                        <strong>{usd(stats.pending_cents)}</strong> · confirmed:{' '}
                        <strong>{usd(stats.approved_cents)}</strong> · credited:{' '}
                        <strong>{usd(stats.paid_cents)}</strong>
                    </li>
                </ul>
                {stats.referrals.length > 0 && (
                    <ul className="grid gap-2">
                        {stats.referrals.map((r, i) => (
                            <li
                                key={i}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3"
                            >
                                <span>Joined {r.joined}</span>
                                <span className="flex items-center gap-2">
                                    {usd(r.reward_cents)}
                                    <Badge variant="secondary">
                                        {STATUS[r.status] ?? r.status}
                                    </Badge>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
                <p className="text-sm text-muted-foreground">
                    This is about brokerages joining RightAlly. Referral fees
                    between agents are yours: RightAlly never takes a share of
                    them.
                </p>
            </div>
        </>
    );
}

Refer.layout = {
    breadcrumbs: [{ title: 'Refer a brokerage', href: '/settings/refer' }],
};
