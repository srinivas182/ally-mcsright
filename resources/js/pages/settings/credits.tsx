import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { Progress } from '@/components/ui/progress';
import { Switch } from '@/components/ui/switch';

type Pool = {
    available: number;
    included: number;
    used: number;
    total: number;
    period: string;
};
type Mine = {
    available: number;
    used: number;
    from_pool: number;
    pool_open: boolean;
    cap: number | null;
};
type Entry = {
    at: string;
    type: string;
    amount: number;
    what: string;
    automation: boolean;
};
type Price = {
    action: string;
    label: string;
    credits: number;
    automation: boolean;
};
type Props = {
    manager: boolean;
    me: Mine;
    my_history: Entry[];
    prices: Price[];
    pool: Pool;
    policy: { agents_use_pool: boolean; agent_monthly_cap: number | null };
    usage?: {
        people: { name: string; credits: number }[];
        automations: number;
        features: {
            action: string;
            label: string;
            credits: number;
            uses: number;
            automation: boolean;
        }[];
    };
    agents?: ({ id: number; name: string } & Mine)[];
    pool_history?: Entry[];
};

const entryLabel: Record<string, string> = {
    grant_included: 'Monthly credits',
    purchase: 'Bought',
    allocate_out: 'Given to an agent',
    allocate_in: 'From the brokerage',
    consume: 'Used',
    refund: 'Refunded',
    expire: 'Unused monthly credits ended',
    adjust: 'Adjustment',
};

const n = (v: number) => v.toLocaleString();

function Meter({ available, total }: { available: number; total: number }) {
    const percent = total > 0 ? Math.round((available * 100) / total) : 0;
    return (
        <div className="grid gap-2">
            <p className="text-3xl font-semibold">
                {n(available)}
                <span className="text-base font-normal text-muted-foreground">
                    {' '}
                    / {n(total)} credits left
                </span>
            </p>
            <Progress value={percent} aria-label={`${percent}% left`} />
        </div>
    );
}

function History({ entries }: { entries: Entry[] }) {
    if (entries.length === 0) {
        return <p className="text-sm text-muted-foreground">Nothing yet.</p>;
    }
    return (
        <ul className="divide-y rounded-lg border text-sm">
            {entries.map((e, i) => (
                <li key={i} className="flex justify-between gap-3 p-3">
                    <span>
                        {entryLabel[e.type] ?? e.type}
                        {e.what && (
                            <span className="text-muted-foreground">
                                {' '}
                                · {e.what}
                            </span>
                        )}
                        {e.automation && (
                            <Badge variant="outline" className="ml-2">
                                Automatic
                            </Badge>
                        )}
                    </span>
                    <span className="shrink-0 text-muted-foreground">
                        <span
                            className={
                                e.amount < 0
                                    ? undefined
                                    : 'text-emerald-700 dark:text-emerald-400'
                            }
                        >
                            {e.amount > 0 ? '+' : ''}
                            {n(e.amount)}
                        </span>{' '}
                        · {new Date(e.at).toLocaleDateString()}
                    </span>
                </li>
            ))}
        </ul>
    );
}

function Prices({ prices }: { prices: Price[] }) {
    return (
        <details className="rounded-lg border p-4 text-sm">
            <summary className="cursor-pointer font-medium">
                What each AI action costs
            </summary>
            <ul className="mt-3 grid gap-1 md:grid-cols-2">
                {prices.map((p) => (
                    <li key={p.action} className="flex justify-between gap-3">
                        <span>
                            {p.label}
                            {p.automation && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    (automatic)
                                </span>
                            )}
                        </span>
                        <span className="text-muted-foreground">
                            {p.credits} {p.credits === 1 ? 'credit' : 'credits'}
                        </span>
                    </li>
                ))}
            </ul>
        </details>
    );
}

function AgentRow({ agent }: { agent: { id: number; name: string } & Mine }) {
    const [amount, setAmount] = useState('');
    const give = (sign: 1 | -1) => {
        const credits = parseInt(amount, 10);
        if (!Number.isFinite(credits) || credits <= 0) return;
        router.post(
            '/settings/credits/allot',
            { user_id: agent.id, credits: credits * sign },
            { preserveScroll: true, onSuccess: () => setAmount('') },
        );
    };
    return (
        <li className="flex flex-wrap items-center justify-between gap-3 p-3">
            <span>
                {agent.name}
                <span className="text-muted-foreground">
                    {' '}
                    · has {n(agent.available)} · used {n(agent.used)} this month
                    {agent.from_pool > 0 &&
                        ` (${n(agent.from_pool)} from the brokerage)`}
                </span>
            </span>
            <span className="flex items-center gap-2">
                <Input
                    aria-label={`Credits for ${agent.name}`}
                    type="number"
                    min={1}
                    className="w-24"
                    value={amount}
                    onChange={(e) => setAmount(e.target.value)}
                />
                <Button size="sm" onClick={() => give(1)}>
                    Give
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => give(-1)}
                    disabled={agent.available <= 0}
                >
                    Take back
                </Button>
            </span>
        </li>
    );
}

/** Settings → Credits: the brokerage's (or the agent's own) RightAlly credits this month. */
export default function Credits(props: Props) {
    const { manager, me, pool, policy } = props;
    const [usePool, setUsePool] = useState(policy.agents_use_pool);
    const [cap, setCap] = useState(
        policy.agent_monthly_cap === null
            ? ''
            : String(policy.agent_monthly_cap),
    );
    const savePolicy = () =>
        router.post(
            '/settings/credits/policy',
            {
                agents_use_pool: usePool,
                agent_monthly_cap: cap === '' ? null : parseInt(cap, 10),
            },
            { preserveScroll: true },
        );

    if (!manager) {
        const poolLeft = me.pool_open
            ? me.cap !== null
                ? Math.max(0, Math.min(pool.available, me.cap - me.from_pool))
                : pool.available
            : 0;
        return (
            <>
                <Head title="Credits" />
                <div className="flex max-w-4xl flex-col gap-4 p-4 md:p-6">
                    <PageHeader
                        title="Your RightAlly credits"
                        description="AI features use credits. You're always asked before an action uses them."
                    />
                    <section className="grid gap-3 rounded-lg border p-4 text-sm">
                        <Meter
                            available={me.available + poolLeft}
                            total={me.available + poolLeft + me.used}
                        />
                        <p className="text-muted-foreground">
                            Your own credits: {n(me.available)}.{' '}
                            {me.pool_open
                                ? `You can also use ${n(poolLeft)} from your brokerage this month.`
                                : 'Your brokerage gives credits to agents directly; ask your broker for more.'}
                        </p>
                        <p className="text-muted-foreground">
                            Buying your own credits is coming soon. Until then,
                            ask your broker.
                        </p>
                    </section>
                    <Prices prices={props.prices} />
                    <h2 className="font-semibold">Your history</h2>
                    <History entries={props.my_history} />
                </div>
            </>
        );
    }

    const usage = props.usage ?? { people: [], automations: 0, features: [] };
    return (
        <>
            <Head title="Credits" />
            <div className="flex max-w-5xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="RightAlly credits"
                    description="AI features across RightAlly use credits from one balance. People are asked before an action uses them; automatic work (like lead follow-up) is shown separately."
                />
                <section className="grid gap-3 rounded-lg border p-4 text-sm">
                    <Meter available={pool.available} total={pool.total} />
                    <p className="text-muted-foreground">
                        {n(pool.included)} of these are this month's included
                        credits, which end at the end of the month.{' '}
                        {n(pool.available - pool.included)} are bought or added
                        credits. Used this month: {n(pool.used)}.
                    </p>
                    <p className="text-muted-foreground">
                        Buying more credits is coming soon; contact RightAlly if
                        you run low before then.
                    </p>
                </section>
                <section className="grid gap-3 md:grid-cols-3">
                    <div className="rounded-lg border p-4 text-sm">
                        <h2 className="mb-2 font-semibold">Who used credits</h2>
                        {usage.people.length === 0 && (
                            <p className="text-muted-foreground">Nobody yet.</p>
                        )}
                        <ul className="grid gap-1">
                            {usage.people.map((p, i) => (
                                <li key={i} className="flex justify-between">
                                    <span>{p.name}</span>
                                    <span className="text-muted-foreground">
                                        {n(p.credits)}
                                    </span>
                                </li>
                            ))}
                            <li className="flex justify-between border-t pt-1">
                                <span>Automatic (bots and follow-up)</span>
                                <span className="text-muted-foreground">
                                    {n(usage.automations)}
                                </span>
                            </li>
                        </ul>
                    </div>
                    <div className="rounded-lg border p-4 text-sm md:col-span-2">
                        <h2 className="mb-2 font-semibold">By feature</h2>
                        {usage.features.length === 0 && (
                            <p className="text-muted-foreground">
                                No AI use yet this month.
                            </p>
                        )}
                        <ul className="grid gap-1">
                            {usage.features.map((f) => (
                                <li
                                    key={f.action}
                                    className="flex justify-between gap-3"
                                >
                                    <span>
                                        {f.label}
                                        {f.automation && (
                                            <Badge
                                                variant="outline"
                                                className="ml-2"
                                            >
                                                Automatic
                                            </Badge>
                                        )}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {n(f.uses)} uses · {n(f.credits)}{' '}
                                        credits
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>
                <section className="grid gap-3 rounded-lg border p-4 text-sm">
                    <h2 className="font-semibold">Agents</h2>
                    <label className="flex items-center gap-3">
                        <Switch
                            checked={usePool}
                            onCheckedChange={setUsePool}
                        />
                        Agents can use the brokerage's credits when their own
                        run out
                    </label>
                    {usePool && (
                        <label className="flex flex-wrap items-center gap-2">
                            Limit per agent each month
                            <Input
                                type="number"
                                min={0}
                                className="w-28"
                                placeholder="No limit"
                                value={cap}
                                onChange={(e) => setCap(e.target.value)}
                            />
                            credits
                        </label>
                    )}
                    <Button
                        size="sm"
                        className="justify-self-start"
                        onClick={savePolicy}
                    >
                        Save
                    </Button>
                    <ul className="divide-y rounded-lg border">
                        {(props.agents ?? []).length === 0 && (
                            <li className="p-3 text-muted-foreground">
                                No agents yet.
                            </li>
                        )}
                        {(props.agents ?? []).map((a) => (
                            <AgentRow key={a.id} agent={a} />
                        ))}
                    </ul>
                </section>
                <Prices prices={props.prices} />
                <h2 className="font-semibold">Brokerage history</h2>
                <History entries={props.pool_history ?? []} />
            </div>
        </>
    );
}

Credits.layout = {
    breadcrumbs: [{ title: 'Credits', href: '/settings/credits' }],
};
