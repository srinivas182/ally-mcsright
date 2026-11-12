import { Head } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Props = {
    model: string;
    blocked: boolean;
    current: string | null;
    wanted: string;
    plans: {
        ulid: string;
        name: string;
        description: string | null;
        features: string[];
        price_cents: number;
        list_cents: number;
    }[];
    featureNames: Record<string, string>;
    paymentsOpen: boolean;
};

/** Plans at the brokerage's price, next to the public price. */
export default function Plans({
    blocked,
    current,
    wanted,
    plans,
    featureNames,
    paymentsOpen,
}: Props) {
    return (
        <>
            <Head title="Plans" />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Choose your plan"
                    description={
                        current
                            ? `You're on ${current}.`
                            : 'Join through your brokerage and pay less than the public price.'
                    }
                />
                {blocked && (
                    <p
                        role="alert"
                        className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100"
                    >
                        Your brokerage asks you to choose a plan to use
                        RightAlly.
                    </p>
                )}
                {wanted && featureNames[wanted] && (
                    <p className="text-sm">
                        To use <strong>{featureNames[wanted]}</strong>, choose a
                        plan that includes it.
                    </p>
                )}
                <ul className="grid gap-3 md:grid-cols-3">
                    {plans.map((p) => (
                        <li
                            key={p.ulid}
                            className={`grid content-start gap-2 rounded-lg border p-4 text-sm ${wanted && p.features.includes(wanted) ? 'border-primary' : ''}`}
                        >
                            <span className="flex items-center gap-2 text-lg font-semibold">
                                {p.name}
                                {current === p.name && <Badge>Current</Badge>}
                            </span>
                            <span>
                                <strong className="text-2xl">
                                    {usd(p.price_cents)}
                                </strong>{' '}
                                / month
                            </span>
                            {p.list_cents > p.price_cents && (
                                <span className="text-muted-foreground">
                                    Public price {usd(p.list_cents)} — you save{' '}
                                    {usd(p.list_cents - p.price_cents)}
                                </span>
                            )}
                            {p.description && <span>{p.description}</span>}
                            <ul className="list-disc pl-4 text-muted-foreground">
                                {p.features.map((f) => (
                                    <li key={f}>{featureNames[f] ?? f}</li>
                                ))}
                            </ul>
                            <Button disabled={!paymentsOpen}>
                                {paymentsOpen
                                    ? 'Choose'
                                    : 'Card payments open soon'}
                            </Button>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

Plans.layout = { breadcrumbs: [{ title: 'Plans', href: '/plans' }] };
