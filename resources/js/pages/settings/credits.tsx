import { Head, router, usePage } from '@inertiajs/react';
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
    policy: {
        agents_use_pool: boolean;
        agent_monthly_cap: number | null;
        agents_can_buy: boolean;
    };
    packs: Pack[];
    purchases: Purchase[];
    checkout: 'success' | 'cancelled' | null;
    value_microcents: number;
    autopay: Autopay;
    map_price: { credits: number; per: number };
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

type Pack = {
    ulid: string;
    name: string;
    credits: number;
    price_cents: number;
};
type Autopay = {
    card: { brand: string; last4: string } | null;
    enabled: boolean;
    threshold: number;
    pack: string | null;
    failures: number;
    last_error: string | null;
};
type Purchase = {
    ulid: string;
    pack: string;
    credits: number;
    price_cents: number;
    total_cents: number;
    status: string;
    paid_at: string | null;
    expires_at: string | null;
    remaining: number;
    for: string;
    buyer: string;
};

const usd = (cents: number) =>
    new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(cents / 100);

/** Credit packs to buy (payment on a secure checkout page), with the price per credit. */
/** Automatic top-up on the saved card: below this balance, buy this pack. */
function AutoTopUpCard({
    autopay,
    packs,
}: {
    autopay: Autopay;
    packs: Pack[];
}) {
    const [enabled, setEnabled] = useState(autopay.enabled);
    const [threshold, setThreshold] = useState(String(autopay.threshold));
    const [pack, setPack] = useState(autopay.pack ?? packs[0]?.ulid ?? '');
    return (
        <div className="grid gap-2 rounded-lg border p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="font-medium">
                    Saved card and automatic top-up
                </span>
                {autopay.card ? (
                    <span className="flex items-center gap-2 text-muted-foreground">
                        <span className="capitalize">{autopay.card.brand}</span>{' '}
                        •••• {autopay.card.last4}
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                router.delete('/settings/credits/card', {
                                    preserveScroll: true,
                                })
                            }
                        >
                            Remove
                        </Button>
                    </span>
                ) : (
                    <span className="text-muted-foreground">
                        Your card is saved the first time you buy a pack.
                    </span>
                )}
            </div>
            {autopay.last_error && (
                <p className="text-destructive">
                    The last automatic top-up failed: {autopay.last_error} Buy a
                    pack with another card to fix it.
                </p>
            )}
            <div className="flex flex-wrap items-center gap-2">
                <label className="flex items-center gap-2">
                    <Switch
                        checked={enabled}
                        onCheckedChange={setEnabled}
                        disabled={!autopay.card}
                    />
                    When credits drop below
                </label>
                <Input
                    aria-label="Top up below this many credits"
                    type="number"
                    min={0}
                    className="h-8 w-24"
                    value={threshold}
                    onChange={(e) => setThreshold(e.target.value)}
                />
                <span>buy</span>
                <select
                    aria-label="Pack to buy automatically"
                    className="h-8 rounded-md border bg-background px-2"
                    value={pack}
                    onChange={(e) => setPack(e.target.value)}
                >
                    {packs.map((p) => (
                        <option key={p.ulid} value={p.ulid}>
                            {p.name} ({n(p.credits)} for {usd(p.price_cents)})
                        </option>
                    ))}
                </select>
                <Button
                    size="sm"
                    variant="outline"
                    disabled={!autopay.card}
                    onClick={() =>
                        router.post(
                            '/settings/credits/autopay',
                            {
                                enabled,
                                threshold: parseInt(threshold || '0', 10),
                                pack,
                            },
                            { preserveScroll: true },
                        )
                    }
                >
                    Save
                </Button>
            </div>
        </div>
    );
}

function BuyCredits({
    packs,
    forPool,
    autopay,
}: {
    packs: Pack[];
    forPool: boolean;
    autopay: Autopay;
}) {
    const [busy, setBusy] = useState<string | null>(null);
    if (packs.length === 0) return null;
    const card = autopay.card;
    return (
        <section id="buy" className="grid gap-3 rounded-lg border p-4 text-sm">
            <h2 className="font-semibold">
                {forPool
                    ? 'Buy credits for the brokerage'
                    : 'Buy your own credits'}
            </h2>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {packs.map((p) => (
                    <div
                        key={p.ulid}
                        className="grid gap-2 rounded-lg border p-4"
                    >
                        <span className="font-medium">{p.name}</span>
                        <span className="text-2xl font-semibold">
                            {n(p.credits)}
                        </span>
                        <span className="text-muted-foreground">
                            credits · {usd(p.price_cents)} (
                            {((p.price_cents / p.credits) * 1).toFixed(2)}¢
                            each)
                        </span>
                        {card && (
                            <Button
                                size="sm"
                                disabled={busy !== null}
                                onClick={() => {
                                    setBusy(p.ulid);
                                    router.post(
                                        '/settings/credits/buy-saved',
                                        { pack: p.ulid },
                                        {
                                            preserveScroll: true,
                                            onFinish: () => setBusy(null),
                                        },
                                    );
                                }}
                            >
                                {busy === p.ulid
                                    ? 'Paying…'
                                    : `Buy with •••• ${card.last4}`}
                            </Button>
                        )}
                        <Button
                            size="sm"
                            variant={card ? 'outline' : 'default'}
                            disabled={busy !== null}
                            onClick={() => {
                                setBusy(p.ulid);
                                router.post(
                                    '/settings/credits/buy',
                                    { pack: p.ulid },
                                    { onFinish: () => setBusy(null) },
                                );
                            }}
                        >
                            {card
                                ? 'Pay with another card'
                                : busy === p.ulid
                                  ? 'Opening checkout…'
                                  : `Buy for ${usd(p.price_cents)}`}
                        </Button>
                    </div>
                ))}
            </div>
            <AutoTopUpCard autopay={autopay} packs={packs} />
            <p className="text-muted-foreground">
                You pay on a secure checkout page and get an emailed receipt.
                Credits arrive as soon as the payment is confirmed and last 12
                months; included monthly credits are always used first, then
                bought credits oldest first.
            </p>
        </section>
    );
}

function Purchases({
    purchases,
    manager,
}: {
    purchases: Purchase[];
    manager: boolean;
}) {
    const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));
    return (
        <section className="grid gap-2 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="font-semibold">Purchases and receipts</h2>
                <span className="flex items-center gap-2">
                    <Input
                        aria-label="Month to export"
                        type="month"
                        className="h-8 w-40"
                        value={month}
                        onChange={(e) => setMonth(e.target.value)}
                    />
                    <Button size="sm" variant="outline" asChild>
                        <a
                            href={`/settings/credits/export?month=${encodeURIComponent(month)}`}
                        >
                            Export {manager ? 'credit history' : 'my history'}{' '}
                            (CSV)
                        </a>
                    </Button>
                </span>
            </div>
            {purchases.length === 0 ? (
                <p className="text-muted-foreground">No purchases yet.</p>
            ) : (
                <ul className="divide-y rounded-lg border">
                    {purchases.map((p) => (
                        <li
                            key={p.ulid}
                            className="flex flex-wrap items-center justify-between gap-2 p-3"
                        >
                            <span>
                                {p.pack} · {n(p.credits)} credits ·{' '}
                                {usd(p.total_cents)}
                                <span className="text-muted-foreground">
                                    {' '}
                                    · {p.for}
                                    {manager && ` · ${p.buyer}`}
                                    {p.paid_at &&
                                        ` · ${new Date(p.paid_at).toLocaleDateString()}`}
                                    {p.status === 'paid' &&
                                        p.expires_at &&
                                        ` · ${n(p.remaining)} left until ${new Date(p.expires_at).toLocaleDateString()}`}
                                </span>
                                {p.status === 'refunded' && (
                                    <Badge variant="outline" className="ml-2">
                                        Refunded
                                    </Badge>
                                )}
                            </span>
                            <a
                                className="underline"
                                href={`/settings/credits/receipts/${p.ulid}`}
                            >
                                Receipt
                            </a>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function CheckoutNotice({ status }: { status: Props['checkout'] }) {
    if (status === null) return null;
    return (
        <p
            role="status"
            className={
                status === 'success'
                    ? 'rounded-lg border border-emerald-600/40 bg-emerald-50 p-3 text-sm text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100'
                    : 'rounded-lg border p-3 text-sm text-muted-foreground'
            }
        >
            {status === 'success'
                ? "Thank you. Your payment went through; the credits appear here within a minute (refresh if you don't see them yet). A receipt is on its way by email."
                : 'Checkout was cancelled. Nothing was charged.'}
        </p>
    );
}

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
    const [canBuy, setCanBuy] = useState(policy.agents_can_buy);
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
                agents_can_buy: canBuy,
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
                    </section>
                    <CheckoutNotice status={props.checkout} />
                    <BuyCredits
                        packs={props.packs}
                        forPool={false}
                        autopay={props.autopay}
                    />
                    {props.packs.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            Need more? Ask your broker to give you credits.
                        </p>
                    )}
                    <Prices prices={props.prices} />
                    <Purchases purchases={props.purchases} manager={false} />
                    <h2 className="font-semibold">Your history</h2>
                    <History entries={props.my_history} />
                </div>
            </>
        );
    }

    const usage = props.usage ?? { people: [], automations: 0, features: [] };
    const allowances = (
        usePage().props.credits as
            | {
                  usage?: Record<
                      string,
                      { used: number; included: number; label: string }
                  >;
              }
            | null
            | undefined
    )?.usage;
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
                </section>
                <CheckoutNotice status={props.checkout} />
                <BuyCredits
                    packs={props.packs}
                    forPool
                    autopay={props.autopay}
                />
                {allowances && (
                    <section className="grid gap-3 rounded-lg border p-4 text-sm">
                        <h2 className="font-semibold">
                            Included in your package this month
                        </h2>
                        <div className="grid gap-3 md:grid-cols-3">
                            {Object.entries(allowances).map(([key, a]) => {
                                const pct =
                                    a.included > 0
                                        ? Math.min(
                                              100,
                                              Math.round(
                                                  (a.used * 100) / a.included,
                                              ),
                                          )
                                        : 0;
                                return (
                                    <div key={key} className="grid gap-1">
                                        <span className="flex justify-between">
                                            <span className="capitalize">
                                                {a.label}
                                            </span>
                                            <span
                                                className={
                                                    a.used > a.included
                                                        ? 'font-medium text-amber-700 dark:text-amber-400'
                                                        : 'text-muted-foreground'
                                                }
                                            >
                                                {n(a.used)} / {n(a.included)}
                                            </span>
                                        </span>
                                        <Progress
                                            value={pct}
                                            aria-label={`${a.label}: ${pct}% used`}
                                        />
                                    </div>
                                );
                            })}
                        </div>
                        <p className="text-muted-foreground">
                            Everything keeps working past the included amount:
                            extra texts, call minutes and map views use credits.
                            Website map views beyond the package cost{' '}
                            {props.map_price.credits} credits per{' '}
                            {props.map_price.per} (about{' '}
                            {usd(
                                Math.round(
                                    (props.map_price.credits *
                                        props.value_microcents *
                                        1000) /
                                        props.map_price.per /
                                        1_000_000,
                                ),
                            )}{' '}
                            per 1,000). Turn on automatic top-up so your website
                            map never pauses.
                        </p>
                    </section>
                )}
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
                    <label className="flex items-center gap-3">
                        <Switch checked={canBuy} onCheckedChange={setCanBuy} />
                        Agents can buy their own credits
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
                <Purchases purchases={props.purchases} manager />
                <h2 className="font-semibold">Brokerage history</h2>
                <History entries={props.pool_history ?? []} />
            </div>
        </>
    );
}

Credits.layout = {
    breadcrumbs: [{ title: 'Credits', href: '/settings/credits' }],
};
