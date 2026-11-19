import { Head, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Props = {
    affiliates: {
        ulid: string;
        kind: string;
        name: string;
        code: string;
        joined: number;
    }[];
    referrals: {
        id: number;
        affiliate: string;
        brokerage: string;
        status: 'pending' | 'approved' | 'paid' | 'void';
        reward_cents: number;
        reward_kind: 'cash' | 'credit';
        paid_cents: number;
        note: string | null;
        first_paid_at: string | null;
    }[];
    token: { code: string; link: string; dashboard: string } | null;
    share: number;
    brokerageShare: number;
};

/** RF1: HQ adds coaches and influencers, and pays confirmed referral rewards. PB3: HQ sets both shares. */
export default function Affiliates({
    affiliates,
    referrals,
    token,
    share,
    brokerageShare,
}: Props) {
    const form = useForm({ name: '', email: '' });
    const rewards = useForm({ person: share, brokerage: brokerageShare });

    return (
        <>
            <Head title="Referral programme" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Referral programme"
                    description={`People earn ${share}% of a referred brokerage's first-year payments (cash). Brokerages earn ${brokerageShare}% as credit on their own bill. Rewards are confirmed 30 days after the first payment; you pay them and mark them paid here.`}
                />
                <form
                    className="flex flex-wrap items-end gap-3 rounded-lg border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        rewards.post('/hq/affiliates/rewards', {
                            preserveScroll: true,
                        });
                    }}
                >
                    <label className="grid gap-1 text-sm">
                        People earn (% of first year, cash)
                        <Input
                            type="number"
                            min={0}
                            max={50}
                            value={rewards.data.person}
                            onChange={(e) =>
                                rewards.setData(
                                    'person',
                                    Number(e.target.value),
                                )
                            }
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Brokerages earn (% of first year, as credit)
                        <Input
                            type="number"
                            min={0}
                            max={50}
                            value={rewards.data.brokerage}
                            onChange={(e) =>
                                rewards.setData(
                                    'brokerage',
                                    Number(e.target.value),
                                )
                            }
                        />
                    </label>
                    <Button type="submit" disabled={rewards.processing}>
                        Save shares
                    </Button>
                    {rewards.errors.person && (
                        <p className="w-full text-sm text-destructive">
                            {rewards.errors.person}
                        </p>
                    )}
                </form>
                <form
                    className="flex flex-wrap items-end gap-3 rounded-lg border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/hq/affiliates', {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <label className="grid gap-1 text-sm">
                        Name (coach or influencer)
                        <Input
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Email
                        <Input
                            type="email"
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData('email', e.target.value)
                            }
                        />
                    </label>
                    <Button type="submit" disabled={form.processing}>
                        Add and make their link
                    </Button>
                    {form.errors.email && (
                        <p role="alert" className="text-sm text-destructive">
                            {form.errors.email}
                        </p>
                    )}
                </form>
                {token && (
                    <p role="status" className="rounded-md border p-3 text-sm">
                        Their link: <code>{token.link}</code>. Their private
                        dashboard (shown once; send it to them):{' '}
                        <code className="break-all">{token.dashboard}</code>
                    </p>
                )}

                <section className="grid gap-2">
                    <h2 className="text-lg font-semibold">Referrals</h2>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-2">Referred by</th>
                                    <th className="p-2">Brokerage</th>
                                    <th className="p-2">Paid so far</th>
                                    <th className="p-2">Reward</th>
                                    <th className="p-2">Status</th>
                                    <th className="p-2">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {referrals.map((r) => (
                                    <tr key={r.id} className="border-t">
                                        <td className="p-2">{r.affiliate}</td>
                                        <td className="p-2">{r.brokerage}</td>
                                        <td className="p-2">
                                            {usd(r.paid_cents)}
                                        </td>
                                        <td className="p-2">
                                            {usd(r.reward_cents)}{' '}
                                            {r.reward_kind}
                                        </td>
                                        <td className="p-2">
                                            <Badge
                                                variant={
                                                    r.status === 'approved'
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {r.status}
                                            </Badge>
                                            {r.note && (
                                                <p className="text-xs text-muted-foreground">
                                                    {r.note}
                                                </p>
                                            )}
                                        </td>
                                        <td className="p-2">
                                            {r.status === 'approved' && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => {
                                                        const note =
                                                            window.prompt(
                                                                'How was it paid? (e.g. "ACH 12 Dec" or "Credit applied")',
                                                            );
                                                        if (note) {
                                                            router.post(
                                                                `/hq/affiliates/referrals/${r.id}/paid`,
                                                                { note },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                        }
                                                    }}
                                                >
                                                    Mark paid
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="grid gap-2">
                    <h2 className="text-lg font-semibold">Referrers</h2>
                    <ul className="grid gap-1 text-sm">
                        {affiliates.map((a) => (
                            <li key={a.ulid}>
                                {a.name} ({a.kind}) · /r/{a.code} · {a.joined}{' '}
                                joined
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}

Affiliates.layout = {
    breadcrumbs: [{ title: 'Referral programme', href: '/hq/affiliates' }],
};
