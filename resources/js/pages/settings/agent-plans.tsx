import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Earnings = {
    floor_cents: number;
    commission_percent: string;
    commission_cents: number;
    markup_cents: number;
    total_cents: number;
    rightally_cents: number;
};
type Plan = {
    ulid: string;
    name: string;
    description: string | null;
    features: string[];
    list_cents: number;
    floor_cents: number;
    price_cents: number;
    offered: boolean;
    default: boolean;
    pending: string | null;
    earnings: Earnings;
};
type Props = {
    model: string;
    mayChoose: boolean;
    agents: { ulid: string; name: string }[];
    policy: string;
    payingAgents: number;
    plans: Plan[];
    featureNames: Record<string, string>;
};

function PlanRow({ plan }: { plan: Plan }) {
    const [price, setPrice] = useState(String(plan.price_cents / 100));
    const [offered, setOffered] = useState(plan.offered);
    const cents = Math.round(Number(price) * 100);
    const markup = Math.max(0, cents - plan.floor_cents);

    return (
        <li className="grid gap-2 rounded-lg border p-4 text-sm">
            <span className="flex items-center gap-2 font-semibold">
                {plan.name}
                {plan.default && <Badge>Default</Badge>}
            </span>
            <span className="text-muted-foreground">
                Floor {usd(plan.floor_cents)} · public price{' '}
                {usd(plan.list_cents)} per month
            </span>
            <span className="flex flex-wrap items-center gap-2">
                Your agents pay $
                <Input
                    aria-label={`Price for ${plan.name}`}
                    type="number"
                    min={plan.floor_cents / 100}
                    max={plan.list_cents / 100}
                    step="0.01"
                    className="w-28"
                    value={price}
                    onChange={(e) => setPrice(e.target.value)}
                />
                / month
                <label className="flex items-center gap-1">
                    <input
                        type="checkbox"
                        className="size-4"
                        checked={offered}
                        onChange={(e) => setOffered(e.target.checked)}
                    />{' '}
                    Offer this plan
                </label>
            </span>
            <span>
                You earn about{' '}
                <strong>{usd(plan.earnings.commission_cents + markup)}</strong>{' '}
                per paying agent a month ({plan.earnings.commission_percent}% of
                the floor = {usd(plan.earnings.commission_cents)}, plus{' '}
                {usd(markup)} markup).
            </span>
            {plan.pending && (
                <span className="text-xs text-muted-foreground">
                    A price increase reaches existing agents on{' '}
                    {new Date(plan.pending).toLocaleDateString()}.
                </span>
            )}
            <span className="flex gap-2">
                <Button
                    size="sm"
                    onClick={() =>
                        router.post(
                            `/settings/agent-plans/${plan.ulid}`,
                            { price, offered },
                            { preserveScroll: true },
                        )
                    }
                >
                    Save price
                </Button>
                {!plan.default && (
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() =>
                            router.post(
                                `/settings/agent-plans/${plan.ulid}`,
                                { price, offered: true, default: true },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Make default for new agents
                    </Button>
                )}
            </span>
        </li>
    );
}

/** Who pays RightAlly, what agents without a plan can use, and the brokerage's agent prices. */
export default function AgentPlans({
    model,
    agents,
    mayChoose,
    policy,
    payingAgents,
    plans,
}: Props) {
    const form = useForm({ model, policy });
    const sponsor = useForm({ agent: '', plan: '' });

    return (
        <>
            <Head title="Agent plans" />
            <div className="flex flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Agent plans"
                    description={`${payingAgents} agent(s) paying. You have no liability for agents' fees: they pay RightAlly directly.`}
                />
                <form
                    className="grid gap-2 rounded-lg border p-4 text-sm"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/settings/agent-plans', {
                            preserveScroll: true,
                        });
                    }}
                >
                    {mayChoose ? (
                        <>
                            <label className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    name="model"
                                    checked={
                                        form.data.model === 'brokerage_pays'
                                    }
                                    onChange={() =>
                                        form.setData('model', 'brokerage_pays')
                                    }
                                />{' '}
                                The brokerage pays for everyone
                            </label>
                            <label className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    name="model"
                                    checked={form.data.model === 'agent_pays'}
                                    onChange={() =>
                                        form.setData('model', 'agent_pays')
                                    }
                                />{' '}
                                Agents pay RightAlly directly (you earn from
                                every paying agent)
                            </label>
                        </>
                    ) : (
                        <p>
                            {model === 'agent_pays'
                                ? 'Your agents pay RightAlly directly. You have no contract or liability for their fees, and you earn from every paying agent.'
                                : 'Your brokerage pays RightAlly for its agents.'}
                        </p>
                    )}
                    {form.data.model === 'agent_pays' && (
                        <span className="flex flex-wrap items-center gap-2 pl-6">
                            Agents without a plan:
                            <select
                                aria-label="Agents without a plan"
                                className="rounded border bg-background px-2 py-1"
                                value={form.data.policy}
                                onChange={(e) =>
                                    form.setData('policy', e.target.value)
                                }
                            >
                                <option value="minimal">
                                    keep the basics (statements, deals,
                                    documents, training)
                                </option>
                                <option value="blocked">
                                    must choose a plan first
                                </option>
                            </select>
                        </span>
                    )}
                    <Button
                        type="submit"
                        className="justify-self-start"
                        disabled={form.processing}
                    >
                        Save
                    </Button>
                </form>
                <ul className="grid gap-3 md:grid-cols-2">
                    {plans.map((p) => (
                        <PlanRow key={p.ulid} plan={p} />
                    ))}
                </ul>
                {model === 'agent_pays' && (
                    <form
                        className="flex flex-wrap items-center gap-2 rounded-lg border p-4 text-sm"
                        onSubmit={(e) => {
                            e.preventDefault();
                            sponsor.post('/settings/agent-plans-sponsor', {
                                preserveScroll: true,
                            });
                        }}
                    >
                        Sponsor an agent (the brokerage pays their whole plan):
                        <select
                            aria-label="Agent to sponsor"
                            className="rounded border bg-background px-2 py-1.5"
                            value={sponsor.data.agent}
                            onChange={(e) =>
                                sponsor.setData('agent', e.target.value)
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
                            aria-label="Plan to sponsor"
                            className="rounded border bg-background px-2 py-1.5"
                            value={sponsor.data.plan}
                            onChange={(e) =>
                                sponsor.setData('plan', e.target.value)
                            }
                        >
                            <option value="">Plan…</option>
                            {plans
                                .filter((p) => p.offered)
                                .map((p) => (
                                    <option key={p.ulid} value={p.ulid}>
                                        {p.name}
                                    </option>
                                ))}
                        </select>
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={!sponsor.data.agent || !sponsor.data.plan}
                        >
                            Sponsor
                        </Button>
                    </form>
                )}
            </div>
        </>
    );
}

AgentPlans.layout = {
    breadcrumbs: [{ title: 'Agent plans', href: '/settings/agent-plans' }],
};
