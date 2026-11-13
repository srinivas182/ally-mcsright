import { Head, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Props = {
    settings: {
        deduct_from_commission: boolean;
        late_fee_cents: number;
        grace_days: number;
        due_days: number;
    };
    items: {
        ulid: string;
        name: string;
        amount_cents: number;
        frequency: string;
    }[];
    agents: {
        ulid: string;
        name: string;
        owed_cents: number;
        owes_brokerage_cents: number;
    }[];
    invoices: {
        ulid: string;
        number: string;
        agent: string | null;
        due_on: string;
        status: string;
        total_cents: number;
        paid_cents: number;
        plan: boolean;
    }[];
};

const select = 'rounded border bg-background px-2 py-1.5';

/** Fees, charges, invoices and payment plans for agents. Money goes to the brokerage. */
export default function AgentBilling({
    settings,
    items,
    agents,
    invoices,
}: Props) {
    const opts = { preserveScroll: true };
    const config = useForm({
        deduct: settings.deduct_from_commission,
        late_fee: String(settings.late_fee_cents / 100),
        grace_days: String(settings.grace_days),
        due_days: String(settings.due_days),
    });
    const item = useForm({ name: '', amount: '', frequency: 'monthly' });
    const assign = useForm({
        agent: '',
        item: '',
        from: new Date().toISOString().slice(0, 10),
    });
    const charge = useForm({ agent: '', description: '', amount: '' });
    const plan = useForm({
        agent: '',
        amount: '',
        instalments: '6',
        reason: '',
    });
    const errors = {
        ...item.errors,
        ...assign.errors,
        ...charge.errors,
        ...plan.errors,
        ...config.errors,
    };
    const owing = agents.filter((a) => a.owes_brokerage_cents > 0);

    return (
        <>
            <Head title="Agent billing" />
            <div className="flex max-w-5xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Agent billing"
                    description="Monthly fees, one-off charges and payment plans. Taken from commission, or paid online to the brokerage."
                />
                {Object.values(errors)[0] && (
                    <p role="alert" className="text-sm text-destructive">
                        {Object.values(errors)[0]}
                    </p>
                )}
                <form
                    className="flex flex-wrap items-center gap-2 rounded-lg border p-4 text-sm"
                    onSubmit={(e) => {
                        e.preventDefault();
                        config.post('/finance/agent-billing/settings', opts);
                    }}
                >
                    <label className="flex items-center gap-1">
                        <input
                            type="checkbox"
                            className="size-4"
                            checked={config.data.deduct}
                            onChange={(e) =>
                                config.setData('deduct', e.target.checked)
                            }
                        />{' '}
                        Take unpaid invoices from commission
                    </label>
                    · due in{' '}
                    <Input
                        aria-label="Days until due"
                        type="number"
                        className="w-16"
                        value={config.data.due_days}
                        onChange={(e) =>
                            config.setData('due_days', e.target.value)
                        }
                    />{' '}
                    days · late fee $
                    <Input
                        aria-label="Late fee ($)"
                        type="number"
                        min={0}
                        className="w-20"
                        value={config.data.late_fee}
                        onChange={(e) =>
                            config.setData('late_fee', e.target.value)
                        }
                    />
                    after{' '}
                    <Input
                        aria-label="Grace days"
                        type="number"
                        className="w-16"
                        value={config.data.grace_days}
                        onChange={(e) =>
                            config.setData('grace_days', e.target.value)
                        }
                    />{' '}
                    days
                    <Button type="submit" size="sm" variant="outline">
                        Save
                    </Button>
                </form>
                <section className="grid gap-2 rounded-lg border p-4 text-sm">
                    <h2 className="font-semibold">Fees</h2>
                    <ul>
                        {items.map((i) => (
                            <li key={i.ulid}>
                                {i.name} — {usd(i.amount_cents)}{' '}
                                {i.frequency === 'once'
                                    ? 'once'
                                    : `per ${i.frequency.replace('ly', '').replace('month', 'month')}`}
                            </li>
                        ))}
                    </ul>
                    <form
                        className="flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            item.post('/finance/agent-billing/items', {
                                ...opts,
                                onSuccess: () => item.reset(),
                            });
                        }}
                    >
                        <Input
                            aria-label="Fee name"
                            placeholder="Tech fee"
                            className="w-44"
                            value={item.data.name}
                            onChange={(e) =>
                                item.setData('name', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Amount ($)"
                            type="number"
                            min={0.01}
                            step="0.01"
                            className="w-28"
                            value={item.data.amount}
                            onChange={(e) =>
                                item.setData('amount', e.target.value)
                            }
                        />
                        <select
                            aria-label="How often"
                            className={select}
                            value={item.data.frequency}
                            onChange={(e) =>
                                item.setData('frequency', e.target.value)
                            }
                        >
                            <option value="monthly">Monthly</option>
                            <option value="yearly">Yearly</option>
                            <option value="once">Once</option>
                        </select>
                        <Button type="submit" size="sm" variant="outline">
                            Add fee
                        </Button>
                    </form>
                    <form
                        className="flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            assign.post('/finance/agent-billing/assign', opts);
                        }}
                    >
                        Charge
                        <select
                            aria-label="Agent"
                            className={select}
                            value={assign.data.agent}
                            onChange={(e) =>
                                assign.setData('agent', e.target.value)
                            }
                        >
                            <option value="">Agent…</option>
                            {agents.map((a) => (
                                <option key={a.ulid} value={a.ulid}>
                                    {a.name}
                                </option>
                            ))}
                        </select>
                        <select
                            aria-label="Fee"
                            className={select}
                            value={assign.data.item}
                            onChange={(e) =>
                                assign.setData('item', e.target.value)
                            }
                        >
                            <option value="">Fee…</option>
                            {items.map((i) => (
                                <option key={i.ulid} value={i.ulid}>
                                    {i.name}
                                </option>
                            ))}
                        </select>
                        from{' '}
                        <Input
                            aria-label="Starting"
                            type="date"
                            className="w-40"
                            value={assign.data.from}
                            onChange={(e) =>
                                assign.setData('from', e.target.value)
                            }
                        />
                        <Button
                            type="submit"
                            size="sm"
                            variant="outline"
                            disabled={!assign.data.agent || !assign.data.item}
                        >
                            Start
                        </Button>
                    </form>
                </section>
                <form
                    className="flex flex-wrap gap-2 rounded-lg border p-4 text-sm"
                    onSubmit={(e) => {
                        e.preventDefault();
                        charge.post('/finance/agent-billing/charge', {
                            ...opts,
                            onSuccess: () => charge.reset(),
                        });
                    }}
                >
                    One-off charge:
                    <select
                        aria-label="Agent to charge"
                        className={select}
                        value={charge.data.agent}
                        onChange={(e) =>
                            charge.setData('agent', e.target.value)
                        }
                    >
                        <option value="">Agent…</option>
                        {agents.map((a) => (
                            <option key={a.ulid} value={a.ulid}>
                                {a.name}
                            </option>
                        ))}
                    </select>
                    <Input
                        aria-label="Description"
                        placeholder="Sign rider"
                        className="w-48"
                        value={charge.data.description}
                        onChange={(e) =>
                            charge.setData('description', e.target.value)
                        }
                    />
                    <Input
                        aria-label="Charge amount ($)"
                        type="number"
                        min={0.01}
                        step="0.01"
                        className="w-28"
                        value={charge.data.amount}
                        onChange={(e) =>
                            charge.setData('amount', e.target.value)
                        }
                    />
                    <Button
                        type="submit"
                        size="sm"
                        variant="outline"
                        disabled={!charge.data.agent}
                    >
                        Invoice
                    </Button>
                </form>
                {owing.length > 0 && (
                    <section className="grid gap-2 rounded-lg border border-amber-300 p-4 text-sm">
                        <h2 className="font-semibold">
                            Agents who owe the brokerage
                        </h2>
                        <ul>
                            {owing.map((a) => (
                                <li key={a.ulid}>
                                    {a.name}: {usd(a.owes_brokerage_cents)}{' '}
                                    (recovered from future commission, or set up
                                    a plan)
                                </li>
                            ))}
                        </ul>
                        <form
                            className="flex flex-wrap gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                plan.post('/finance/agent-billing/plan', opts);
                            }}
                        >
                            <select
                                aria-label="Agent for plan"
                                className={select}
                                value={plan.data.agent}
                                onChange={(e) =>
                                    plan.setData('agent', e.target.value)
                                }
                            >
                                <option value="">Agent…</option>
                                {owing.map((a) => (
                                    <option key={a.ulid} value={a.ulid}>
                                        {a.name}
                                    </option>
                                ))}
                            </select>
                            <Input
                                aria-label="Plan amount ($)"
                                type="number"
                                min={0.01}
                                className="w-28"
                                value={plan.data.amount}
                                onChange={(e) =>
                                    plan.setData('amount', e.target.value)
                                }
                            />
                            in{' '}
                            <Input
                                aria-label="Instalments"
                                type="number"
                                min={1}
                                max={24}
                                className="w-16"
                                value={plan.data.instalments}
                                onChange={(e) =>
                                    plan.setData('instalments', e.target.value)
                                }
                            />{' '}
                            monthly instalments
                            <Input
                                aria-label="Reason"
                                placeholder="Reversed deal 12 Oak St"
                                className="w-56"
                                value={plan.data.reason}
                                onChange={(e) =>
                                    plan.setData('reason', e.target.value)
                                }
                            />
                            <Button type="submit" size="sm" variant="outline">
                                Set up plan
                            </Button>
                        </form>
                    </section>
                )}
                <section className="grid gap-2">
                    <h2 className="font-semibold">Invoices</h2>
                    <ul className="divide-y rounded-lg border text-sm">
                        {invoices.length === 0 && (
                            <li className="p-3 text-muted-foreground">
                                None yet.
                            </li>
                        )}
                        {invoices.map((i) => (
                            <li
                                key={i.ulid}
                                className="flex flex-wrap items-center justify-between gap-2 p-3"
                            >
                                <span>
                                    {i.number} · {i.agent}
                                    {i.plan ? ' · payment plan' : ''}
                                </span>
                                <span className="flex items-center gap-2">
                                    {usd(i.total_cents)} · due{' '}
                                    {new Date(i.due_on).toLocaleDateString()}{' '}
                                    <Badge
                                        variant={
                                            i.status === 'paid'
                                                ? 'secondary'
                                                : 'outline'
                                        }
                                        className="capitalize"
                                    >
                                        {i.status}
                                    </Badge>
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}

AgentBilling.layout = {
    breadcrumbs: [{ title: 'Agent billing', href: '/finance/agent-billing' }],
};
