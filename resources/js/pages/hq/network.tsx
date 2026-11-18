import { Head, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Props = {
    totals: {
        brokerages: number;
        agents: number;
        sent_30d: number;
        closed: number;
        fees_confirmed_cents: number;
    };
    disputes: {
        id: number;
        area: string;
        from: string;
        to: string;
        fee_due_cents: number;
        closed_on: string | null;
        note: string | null;
        reply: string | null;
        open: boolean;
        reported: string;
    }[];
};

/** AR2: HQ › Referral network: usage and fee disputes. No client details here. */
export default function HqNetwork({ totals, disputes }: Props) {
    return (
        <>
            <Head title="Referral network" />
            <div className="flex max-w-4xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Referral network"
                    description="How brokerages use the agent referral network, and fee disputes to follow up. RightAlly takes no share of referral fees."
                />
                <ul className="grid gap-1 text-base">
                    <li>
                        Brokerages using it:{' '}
                        <strong>{totals.brokerages}</strong> · active agents:{' '}
                        <strong>{totals.agents}</strong>
                    </li>
                    <li>
                        Referrals sent in the last 30 days:{' '}
                        <strong>{totals.sent_30d}</strong> · closed:{' '}
                        <strong>{totals.closed}</strong> · fees confirmed:{' '}
                        <strong>{usd(totals.fees_confirmed_cents)}</strong>
                    </li>
                </ul>
                <section className="grid gap-3">
                    <h2 className="text-lg font-semibold">Fee disputes</h2>
                    {disputes.length === 0 && (
                        <p className="text-muted-foreground">None.</p>
                    )}
                    <ul className="grid gap-2">
                        {disputes.map((d) => (
                            <li
                                key={d.id}
                                className="grid gap-1 rounded-lg border p-3 text-base"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <strong>
                                        {d.area} · {usd(d.fee_due_cents)}
                                    </strong>
                                    <Badge
                                        variant={
                                            d.open ? 'destructive' : 'secondary'
                                        }
                                    >
                                        {d.open ? 'Open' : 'Settled'}
                                    </Badge>
                                </div>
                                <p className="text-sm">
                                    Referred by {d.from} to {d.to}; closed{' '}
                                    {d.closed_on}; reported {d.reported}
                                </p>
                                {d.note && <p>Report: {d.note}</p>}
                                {d.reply && <p>Reply: {d.reply}</p>}
                                {d.open && (
                                    <div>
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    `/hq/network/disputes/${d.id}/close`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Both sides agree it's settled
                                        </Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}
