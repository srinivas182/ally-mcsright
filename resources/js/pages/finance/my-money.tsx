import { Head, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Props = {
    balances: {
        commission_cents: number;
        revenue_share_cents: number;
        available_cents: number;
        pending_cents: number;
        pending_deals: number;
    };
    bank: {
        last4: string;
        type: string;
        holder: string;
        usable_from: string;
        usable: boolean;
    } | null;
    holdHours: number;
};

/** An agent's money: what's ready, what's on its way, and where it's paid. */
export default function MyMoney({ balances, bank, holdHours }: Props) {
    const form = useForm({
        holder: '',
        routing: '',
        account: '',
        type: 'checking',
    });

    return (
        <>
            <Head title="My money" />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4 md:p-6">
                <PageHeader title="My money" />
                <section className="grid gap-3 sm:grid-cols-3">
                    <div className="rounded-lg border p-4 text-sm">
                        <p className="text-muted-foreground">Ready to pay</p>
                        <p className="text-2xl font-semibold">
                            {usd(balances.available_cents)}
                        </p>
                    </div>
                    <div className="rounded-lg border p-4 text-sm">
                        <p className="text-muted-foreground">Commission</p>
                        <p className="text-xl font-semibold">
                            {usd(balances.commission_cents)}
                        </p>
                        <p className="text-muted-foreground">
                            Revenue share {usd(balances.revenue_share_cents)}
                        </p>
                    </div>
                    <div className="rounded-lg border p-4 text-sm">
                        <p className="text-muted-foreground">On its way</p>
                        <p className="text-xl font-semibold">
                            {usd(balances.pending_cents)}
                        </p>
                        <p className="text-muted-foreground">
                            {balances.pending_deals} deal(s) waiting for title's
                            payment
                        </p>
                    </div>
                </section>
                <section className="grid gap-2 rounded-lg border p-4 text-sm">
                    <h2 className="font-semibold">Where we pay you</h2>
                    {bank ? (
                        <p>
                            {bank.holder} · {bank.type} account ending{' '}
                            <strong>{bank.last4}</strong>
                            {bank.usable
                                ? ''
                                : ` · usable from ${new Date(bank.usable_from).toLocaleString()} (security hold)`}
                        </p>
                    ) : (
                        <p className="text-muted-foreground">
                            No bank account yet.
                        </p>
                    )}
                    <form
                        className="grid gap-2 sm:grid-cols-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/my-money/bank', {
                                preserveScroll: true,
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        <Input
                            aria-label="Account holder"
                            placeholder="Account holder name"
                            value={form.data.holder}
                            onChange={(e) =>
                                form.setData('holder', e.target.value)
                            }
                        />
                        <select
                            aria-label="Account type"
                            className="rounded border bg-background px-2 py-1.5"
                            value={form.data.type}
                            onChange={(e) =>
                                form.setData('type', e.target.value)
                            }
                        >
                            <option value="checking">Checking</option>
                            <option value="savings">Savings</option>
                        </select>
                        <Input
                            aria-label="Routing number"
                            inputMode="numeric"
                            autoComplete="off"
                            placeholder="Routing number (9 digits)"
                            value={form.data.routing}
                            onChange={(e) =>
                                form.setData('routing', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Account number"
                            inputMode="numeric"
                            autoComplete="off"
                            placeholder="Account number"
                            value={form.data.account}
                            onChange={(e) =>
                                form.setData('account', e.target.value)
                            }
                        />
                        {Object.values(form.errors)[0] && (
                            <p
                                role="alert"
                                className="text-destructive sm:col-span-2"
                            >
                                {Object.values(form.errors)[0]}
                            </p>
                        )}
                        <Button
                            type="submit"
                            className="justify-self-start"
                            disabled={form.processing}
                        >
                            {bank ? 'Change bank account' : 'Add bank account'}
                        </Button>
                    </form>
                    <p className="text-xs text-muted-foreground">
                        We'll email you about any change, and for your
                        protection payouts go to a new account only after{' '}
                        {holdHours} hours.
                    </p>
                </section>
            </div>
        </>
    );
}

MyMoney.layout = { breadcrumbs: [{ title: 'My money', href: '/my-money' }] };
