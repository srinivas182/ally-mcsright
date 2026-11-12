import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Pkg = {
    ulid: string;
    name: string;
    monthly_cents: number;
    yearly_cents: number;
    ailly_conversations: number;
    features: string[];
};
type Props = {
    packages: Pkg[];
    current: {
        status: string;
        interval: string;
        price_cents: number;
        package: string | null;
        grace_until: string | null;
        customer: string | null;
    } | null;
    readOnly: boolean;
    partnerCode: string;
    featureNames: Record<string, string>;
};

/** The brokerage's own RightAlly subscription, card and invoices, and its partner code. */
export default function Billing({
    packages,
    current,
    readOnly,
    partnerCode,
    featureNames,
}: Props) {
    const [interval, setInterval] = useState<'month' | 'year'>('year');
    const errors = usePage().props.errors as Record<string, string>;

    return (
        <>
            <Head title="Billing" />
            <div className="flex max-w-4xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Billing"
                    description="Your brokerage's RightAlly subscription. Your agents pay for their own plans."
                />
                {(readOnly || errors?.billing) && (
                    <p
                        role="alert"
                        className="rounded-md border border-destructive/40 p-3 text-sm text-destructive"
                    >
                        {errors?.billing ??
                            'Your subscription is unpaid, so changes are paused for owners and staff. Agents who pay for themselves keep working.'}
                    </p>
                )}
                {current && (
                    <section className="flex flex-wrap items-center gap-3 rounded-lg border p-4 text-sm">
                        <span className="font-medium">{current.package}</span>
                        <Badge
                            variant={
                                current.status === 'active'
                                    ? 'secondary'
                                    : 'destructive'
                            }
                            className="capitalize"
                        >
                            {current.status}
                        </Badge>
                        <span>
                            {usd(current.price_cents)} / {current.interval}
                        </span>
                        {current.status === 'grace' && current.grace_until && (
                            <span className="text-destructive">
                                Payment failed — update your card by{' '}
                                {new Date(
                                    current.grace_until,
                                ).toLocaleDateString()}
                                .
                            </span>
                        )}
                        {current.customer && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.get('/settings/billing/portal')
                                }
                            >
                                Manage card and invoices
                            </Button>
                        )}
                    </section>
                )}
                <section className="grid gap-3">
                    <div
                        role="group"
                        aria-label="Billing period"
                        className="flex w-fit rounded border text-sm"
                    >
                        {(['month', 'year'] as const).map((i) => (
                            <button
                                key={i}
                                type="button"
                                aria-pressed={interval === i}
                                className={`px-3 py-1.5 ${interval === i ? 'bg-muted font-medium' : ''}`}
                                onClick={() => setInterval(i)}
                            >
                                {i === 'month' ? 'Monthly' : 'Yearly'}
                            </button>
                        ))}
                    </div>
                    <ul className="grid gap-3 md:grid-cols-3">
                        {packages.map((p) => (
                            <li
                                key={p.ulid}
                                className="grid content-start gap-2 rounded-lg border p-4 text-sm"
                            >
                                <span className="text-lg font-semibold">
                                    {p.name}
                                </span>
                                <span>
                                    <strong className="text-2xl">
                                        {usd(
                                            interval === 'month'
                                                ? p.monthly_cents
                                                : p.yearly_cents,
                                        )}
                                    </strong>{' '}
                                    / {interval}
                                </span>
                                {interval === 'year' &&
                                    p.yearly_cents < p.monthly_cents * 12 && (
                                        <span className="text-muted-foreground">
                                            Save{' '}
                                            {usd(
                                                p.monthly_cents * 12 -
                                                    p.yearly_cents,
                                            )}{' '}
                                            a year
                                        </span>
                                    )}
                                <span className="text-muted-foreground">
                                    AIlly: {p.ailly_conversations} conversations
                                    a month
                                </span>
                                <ul className="list-disc pl-4 text-muted-foreground">
                                    {p.features.map((f) => (
                                        <li key={f}>{featureNames[f] ?? f}</li>
                                    ))}
                                </ul>
                                <Button
                                    onClick={() =>
                                        router.post(
                                            '/settings/billing/checkout',
                                            { package: p.ulid, interval },
                                        )
                                    }
                                >
                                    {current?.package === p.name &&
                                    current.status !== 'ended'
                                        ? 'Change card or period'
                                        : 'Choose and add card'}
                                </Button>
                            </li>
                        ))}
                    </ul>
                </section>
                <section className="grid gap-1 rounded-lg border p-4 text-sm">
                    <span className="font-medium">Your partner code</span>
                    <span className="font-mono text-2xl tracking-wider">
                        {partnerCode}
                    </span>
                    <span className="text-muted-foreground">
                        Agents enter this on rightally.io to see your prices and
                        join through you.
                    </span>
                </section>
            </div>
        </>
    );
}

Billing.layout = {
    breadcrumbs: [{ title: 'Billing', href: '/settings/billing' }],
};
