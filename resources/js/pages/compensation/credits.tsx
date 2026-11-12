import { Head, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Props = {
    credits: {
        ulid: string;
        agent: string | null;
        kind: string;
        name: string;
        amount_cents: number;
        remaining_cents: number;
        expires_on: string | null;
    }[];
    agents: { ulid: string; name: string }[];
};

/** Credits pay an agent's fees on their next closed deals, oldest-expiring first. */
export default function Credits({ credits, agents }: Props) {
    const form = useForm({
        agent: '',
        kind: 'marketing',
        name: '',
        amount: '',
        expires_on: '',
    });

    return (
        <>
            <Head title="Credits" />
            <div className="flex flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Credits and bonuses"
                    description="Credits pay an agent's fees on their next closed deals. A reversed deal gets its credit back."
                />
                <form
                    className="flex flex-wrap gap-2 rounded-lg border p-4 text-sm"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/compensation/credits', {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <select
                        aria-label="Agent"
                        className="rounded border bg-background px-2 py-1.5"
                        value={form.data.agent}
                        onChange={(e) => form.setData('agent', e.target.value)}
                    >
                        <option value="">Agent…</option>
                        {agents.map((a) => (
                            <option key={a.ulid} value={a.ulid}>
                                {a.name}
                            </option>
                        ))}
                    </select>
                    <select
                        aria-label="Kind"
                        className="rounded border bg-background px-2 py-1.5"
                        value={form.data.kind}
                        onChange={(e) => form.setData('kind', e.target.value)}
                    >
                        <option value="signing">Signing</option>
                        <option value="production">Production</option>
                        <option value="marketing">Marketing</option>
                        <option value="other">Other</option>
                    </select>
                    <Input
                        aria-label="Name"
                        placeholder="Welcome marketing credit"
                        className="max-w-xs"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                    <Input
                        aria-label="Amount ($)"
                        type="number"
                        min={0.01}
                        step="0.01"
                        className="w-32"
                        value={form.data.amount}
                        onChange={(e) => form.setData('amount', e.target.value)}
                    />
                    <Input
                        aria-label="Expires on (optional)"
                        type="date"
                        className="w-40"
                        value={form.data.expires_on}
                        onChange={(e) =>
                            form.setData('expires_on', e.target.value)
                        }
                    />
                    <Button type="submit" disabled={form.processing}>
                        Give credit
                    </Button>
                    {Object.values(form.errors).length > 0 && (
                        <p role="alert" className="w-full text-destructive">
                            {Object.values(form.errors)[0]}
                        </p>
                    )}
                </form>
                <ul className="divide-y rounded-lg border text-sm">
                    {credits.map((c) => (
                        <li
                            key={c.ulid}
                            className="flex flex-wrap justify-between gap-2 p-3"
                        >
                            <span>
                                <span className="font-medium">{c.agent}</span> ·{' '}
                                {c.name}{' '}
                                <span className="text-muted-foreground capitalize">
                                    ({c.kind})
                                </span>
                            </span>
                            <span className="text-muted-foreground">
                                {usd(c.remaining_cents)} left of{' '}
                                {usd(c.amount_cents)}
                                {c.expires_on
                                    ? ` · expires ${new Date(c.expires_on).toLocaleDateString()}`
                                    : ''}
                            </span>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

Credits.layout = {
    breadcrumbs: [{ title: 'Credits', href: '/compensation/credits' }],
};
