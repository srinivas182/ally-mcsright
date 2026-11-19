import { Head, router } from '@inertiajs/react';
import { InfoIcon } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { postJson } from '@/lib/http';

type Usage = {
    key: string;
    label: string;
    default: number;
    per: 'seat' | 'package';
};
type Module = {
    key: string;
    label: string;
    about: string;
    audience: string[];
    always: boolean;
    usage: Usage[];
};
type Item = {
    usage: string;
    label: string;
    amount: number;
    per: string;
    units: number;
    cost_cents: number;
    priced: boolean;
    tip: string;
};
type Quote = {
    seats: number;
    modules: string[];
    lines: { key: string; label: string; cost_cents: number; items: Item[] }[];
    fixed: { per_seat_cents: number; per_brokerage_cents: number; tip: string };
    card_fees_cents: number;
    cost_cents: number;
    typical_cost_cents: number;
    worst_cost_cents: number;
    markup_pct: number;
    price_cents: number;
    yearly_cents: number;
    margin_pct: number;
    worst_margin_pct: number;
    unpriced: string[];
    ai_priced: boolean;
};
type Pkg = {
    ulid: string;
    name: string;
    audience: string;
    active: boolean;
    monthly_cents: number;
    modules: string[];
    seats: number;
    usage: Record<string, Record<string, number>>;
    markup_pct: number | null;
    reason: string | null;
    quote: Quote;
};
type Rules = {
    markup: number;
    min_markup: number;
    typical_use: number;
    yearly_months: number;
};

const money = (cents: number) =>
    '$' +
    (cents / 100).toLocaleString('en-US', {
        minimumFractionDigits: cents % 100 === 0 ? 0 : 2,
        maximumFractionDigits: 2,
    });

/**
 * HQ → Package builder (PB1): tick modules (or "Enable all"), set people and what is included, and see what it costs
 * RightAlly — with the sum behind every number — and the price at HQ's markup (70%: cost $100 → price $170).
 */
type AddOn = {
    ulid: string;
    kind: string;
    key: string;
    amount: number;
    name: string;
    price_cents: number;
    audience: string;
    active: boolean;
    cost_cents: number;
    buyers: number;
};
type TenantRow = {
    tenant: string;
    package: string | null;
    paid_cents: number;
    cost_cents: number;
    margin_pct: number | null;
    losing: boolean;
};

export default function Packages({
    catalog,
    rules,
    packages,
    everything,
    addons = [],
    creditPacks = [],
    tenants = [],
}: {
    catalog: Module[];
    rules: Rules;
    packages: Pkg[];
    everything: Record<'brokerage' | 'agent', Quote>;
    addons?: AddOn[];
    creditPacks?: {
        name: string;
        credits: number;
        price_cents: number;
        suggested_cents: number;
    }[];
    tenants?: TenantRow[];
}) {
    const [extra, setExtra] = useState({
        kind: 'allowance',
        key: 'texts',
        amount: 1000,
        name: '1,000 more texts',
        audience: 'both',
        price: '',
        reason: '',
    });
    const [editing, setEditing] = useState<Pkg | null>(null);
    const [name, setName] = useState('');
    const [audience, setAudience] = useState<'brokerage' | 'agent'>(
        'brokerage',
    );
    const [modules, setModules] = useState<string[]>([]);
    const [seats, setSeats] = useState(5);
    const [usage, setUsage] = useState<Record<string, Record<string, number>>>(
        {},
    );
    const [markup, setMarkup] = useState(rules.markup);
    const [price, setPrice] = useState('');
    const [reason, setReason] = useState('');
    const [quote, setQuote] = useState<Quote | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const offered = useMemo(
        () => catalog.filter((m) => m.audience.includes(audience)),
        [catalog, audience],
    );

    useEffect(() => {
        const t = window.setTimeout(() => {
            postJson<Quote>('/hq/packages/quote', {
                audience,
                modules,
                seats,
                usage,
                markup,
            })
                .then(setQuote)
                .catch(() => undefined);
        }, 250);
        return () => window.clearTimeout(t);
    }, [audience, modules, seats, usage, markup]);

    const start = (p: Pkg | null) => {
        setEditing(p);
        setName(p?.name ?? '');
        setAudience((p?.audience as 'brokerage' | 'agent') ?? 'brokerage');
        setModules(p?.modules ?? []);
        setSeats(p?.seats ?? (p?.audience === 'agent' ? 1 : 5));
        setUsage(p?.usage ?? {});
        setMarkup(p?.markup_pct ?? rules.markup);
        setPrice(p ? String(p.monthly_cents / 100) : '');
        setReason(p?.reason ?? '');
        setErrors({});
    };

    const save = () => {
        const data = {
            name,
            audience,
            modules,
            seats,
            usage,
            markup,
            price: price === '' ? null : price,
            reason,
        };
        const opts = { onError: (e: Record<string, string>) => setErrors(e) };
        if (editing) {
            router.put(`/hq/packages/${editing.ulid}`, data, opts);
        } else {
            router.post('/hq/packages', data, opts);
        }
    };

    const typedCents = price === '' ? null : Math.round(Number(price) * 100);
    const belowMin =
        quote !== null &&
        typedCents !== null &&
        typedCents * 100 < quote.cost_cents * (100 + rules.min_markup);

    return (
        <>
            <Head title="Package builder" />
            <div className="mx-auto grid w-full max-w-6xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Package builder"
                    description={`Tick modules, see what they cost us, and price at ${rules.markup}% markup (cost $100 → price $${100 + rules.markup}). Hover the ⓘ on any number to see the sum behind it.`}
                />

                <section className="grid gap-3 md:grid-cols-2">
                    {(['brokerage', 'agent'] as const).map((a) => (
                        <div key={a} className="rounded-lg border p-4">
                            <h2 className="font-medium">
                                Everything on —{' '}
                                {a === 'brokerage'
                                    ? 'brokerage, 10 people'
                                    : 'solo agent'}
                            </h2>
                            <p className="text-2xl font-semibold">
                                {money(everything[a].price_cents)}
                                <span className="text-base font-normal text-muted-foreground">
                                    {' '}
                                    a month
                                </span>
                            </p>
                            <p className="text-sm text-muted-foreground">
                                Our cost {money(everything[a].cost_cents)} ·
                                markup {everything[a].markup_pct}% · we keep{' '}
                                {everything[a].margin_pct}%
                            </p>
                        </div>
                    ))}
                </section>

                <section className="grid gap-2">
                    <div className="flex items-center justify-between">
                        <h2 className="text-lg font-medium">Packages</h2>
                        <Button onClick={() => start(null)}>New package</Button>
                    </div>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left text-muted-foreground">
                                <th className="py-2">Package</th>
                                <th>For</th>
                                <th>Price</th>
                                <th>Our cost today</th>
                                <th>We keep</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {packages.map((p) => (
                                <tr key={p.ulid} className="border-t">
                                    <td className="py-2 font-medium">
                                        {p.name}{' '}
                                        {!p.active && (
                                            <Badge variant="secondary">
                                                Off
                                            </Badge>
                                        )}
                                    </td>
                                    <td>
                                        {p.audience === 'agent'
                                            ? 'Solo agent'
                                            : 'Brokerage'}
                                    </td>
                                    <td>{money(p.monthly_cents)}</td>
                                    <td>{money(p.quote.cost_cents)}</td>
                                    <td
                                        className={
                                            p.monthly_cents > 0 &&
                                            (p.monthly_cents -
                                                p.quote.cost_cents) *
                                                100 <
                                                p.quote.cost_cents *
                                                    rules.min_markup
                                                ? 'font-medium text-destructive'
                                                : ''
                                        }
                                    >
                                        {p.monthly_cents > 0
                                            ? `${Math.floor(((p.monthly_cents - p.quote.cost_cents) * 100) / p.monthly_cents)}%`
                                            : '—'}
                                    </td>
                                    <td>
                                        <Button
                                            variant="outline"
                                            onClick={() => start(p)}
                                        >
                                            Edit
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>

                <section aria-labelledby="extras-h" className="grid gap-3">
                    <h2 id="extras-h" className="text-lg font-medium">
                        Extras customers can add
                    </h2>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left text-muted-foreground">
                                <th className="py-2">Extra</th>
                                <th>For</th>
                                <th>Price a month</th>
                                <th>Our cost</th>
                                <th>Customers</th>
                            </tr>
                        </thead>
                        <tbody>
                            {addons.map((a) => (
                                <tr key={a.ulid} className="border-t">
                                    <td className="py-2">
                                        {a.name}
                                        {!a.active && ' (off)'}
                                    </td>
                                    <td>{a.audience}</td>
                                    <td>{money(a.price_cents)}</td>
                                    <td>{money(a.cost_cents)}</td>
                                    <td>{a.buyers}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <form
                        className="grid gap-2 rounded-md border p-3 text-sm sm:grid-cols-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.post(
                                '/hq/packages/addons',
                                {
                                    ...extra,
                                    price:
                                        extra.price === '' ? null : extra.price,
                                },
                                {
                                    preserveScroll: true,
                                    onError: (er) => setErrors(er),
                                },
                            );
                        }}
                    >
                        <select
                            className="h-10 rounded-md border bg-background px-2"
                            value={`${extra.kind}:${extra.key}`}
                            onChange={(e) => {
                                const [kind, key] = e.target.value.split(':');
                                setExtra({
                                    ...extra,
                                    kind: kind ?? 'allowance',
                                    key: key ?? 'texts',
                                });
                            }}
                        >
                            <option value="allowance:texts">More texts</option>
                            <option value="allowance:calls">
                                More call minutes
                            </option>
                            <option value="allowance:maps">
                                More website map views
                            </option>
                            {catalog
                                .filter((m) => !m.always)
                                .map((m) => (
                                    <option
                                        key={m.key}
                                        value={`module:${m.key}`}
                                    >
                                        Module: {m.label}
                                    </option>
                                ))}
                        </select>
                        <input
                            className="h-10 rounded-md border bg-background px-3"
                            aria-label="Name"
                            value={extra.name}
                            onChange={(e) =>
                                setExtra({ ...extra, name: e.target.value })
                            }
                        />
                        {extra.kind === 'allowance' && (
                            <input
                                type="number"
                                aria-label="How many more a month"
                                className="h-10 rounded-md border bg-background px-3"
                                value={extra.amount}
                                onChange={(e) =>
                                    setExtra({
                                        ...extra,
                                        amount: Number(e.target.value),
                                    })
                                }
                            />
                        )}
                        <select
                            aria-label="For"
                            className="h-10 rounded-md border bg-background px-2"
                            value={extra.audience}
                            onChange={(e) =>
                                setExtra({ ...extra, audience: e.target.value })
                            }
                        >
                            <option value="both">Everyone</option>
                            <option value="brokerage">Brokerages</option>
                            <option value="agent">Solo agents</option>
                        </select>
                        <input
                            inputMode="decimal"
                            aria-label="Price (empty: suggested at cost + markup)"
                            placeholder="Price (empty: suggested)"
                            className="h-10 rounded-md border bg-background px-3"
                            value={extra.price}
                            onChange={(e) =>
                                setExtra({ ...extra, price: e.target.value })
                            }
                        />
                        {errors.price && (
                            <input
                                aria-label="Why below cost + minimum?"
                                placeholder="Why below cost + minimum?"
                                className="h-10 rounded-md border bg-background px-3"
                                value={extra.reason}
                                onChange={(e) =>
                                    setExtra({
                                        ...extra,
                                        reason: e.target.value,
                                    })
                                }
                            />
                        )}
                        <Button type="submit">Add extra</Button>
                    </form>
                    {creditPacks.length > 0 && (
                        <p className="text-sm text-muted-foreground">
                            Credit packs at cost + {rules.markup}%:{' '}
                            {creditPacks
                                .map(
                                    (c) =>
                                        `${c.name} ${money(c.price_cents)} (suggested ${money(c.suggested_cents)})`,
                                )
                                .join(' · ')}
                        </p>
                    )}
                </section>

                <section aria-labelledby="month-h" className="grid gap-2">
                    <h2 id="month-h" className="text-lg font-medium">
                        This month by customer
                    </h2>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left text-muted-foreground">
                                <th className="py-2">Customer</th>
                                <th>Package</th>
                                <th>They pay</th>
                                <th>They cost</th>
                                <th>We keep</th>
                            </tr>
                        </thead>
                        <tbody>
                            {tenants.map((t) => (
                                <tr
                                    key={t.tenant}
                                    className={`border-t ${t.losing ? 'text-destructive' : ''}`}
                                >
                                    <td className="py-2">{t.tenant}</td>
                                    <td>{t.package ?? 'None'}</td>
                                    <td>{money(t.paid_cents)}</td>
                                    <td>{money(t.cost_cents)}</td>
                                    <td>
                                        {t.margin_pct === null
                                            ? '—'
                                            : `${t.margin_pct}%`}
                                        {t.losing && ' (losing money)'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>

                <section
                    aria-labelledby="builder"
                    className="grid gap-4 rounded-lg border p-4 lg:grid-cols-[1fr_20rem]"
                >
                    <div className="grid gap-4">
                        <h2 id="builder" className="text-lg font-medium">
                            {editing ? `Edit ${editing.name}` : 'New package'}
                        </h2>
                        <div className="grid gap-3 sm:grid-cols-3">
                            <label className="grid gap-1 text-sm">
                                Name
                                <input
                                    className="h-10 rounded-md border bg-background px-3"
                                    value={name}
                                    onChange={(e) => setName(e.target.value)}
                                />
                                {errors.name && (
                                    <span className="text-destructive">
                                        {errors.name}
                                    </span>
                                )}
                            </label>
                            <label className="grid gap-1 text-sm">
                                For
                                <select
                                    className="h-10 rounded-md border bg-background px-2"
                                    value={audience}
                                    onChange={(e) =>
                                        setAudience(
                                            e.target.value as
                                                | 'brokerage'
                                                | 'agent',
                                        )
                                    }
                                >
                                    <option value="brokerage">
                                        Brokerages
                                    </option>
                                    <option value="agent">Solo agents</option>
                                </select>
                            </label>
                            <label className="grid gap-1 text-sm">
                                People included
                                <input
                                    type="number"
                                    min={1}
                                    className="h-10 rounded-md border bg-background px-3"
                                    value={seats}
                                    onChange={(e) =>
                                        setSeats(
                                            Math.max(1, Number(e.target.value)),
                                        )
                                    }
                                />
                            </label>
                        </div>

                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                onClick={() =>
                                    setModules(offered.map((m) => m.key))
                                }
                            >
                                Enable all
                            </Button>
                            <Button
                                variant="ghost"
                                onClick={() => setModules([])}
                            >
                                Clear
                            </Button>
                        </div>

                        <ul className="grid gap-2">
                            {offered.map((m) => {
                                const on = m.always || modules.includes(m.key);
                                const line = quote?.lines.find(
                                    (l) => l.key === m.key,
                                );
                                return (
                                    <li
                                        key={m.key}
                                        className={`grid gap-2 rounded-md border p-3 ${on ? 'border-primary/50' : ''}`}
                                    >
                                        <label className="flex items-start gap-3">
                                            <input
                                                type="checkbox"
                                                className="mt-1 size-4"
                                                checked={on}
                                                disabled={m.always}
                                                onChange={(e) =>
                                                    setModules(
                                                        e.target.checked
                                                            ? [
                                                                  ...modules,
                                                                  m.key,
                                                              ]
                                                            : modules.filter(
                                                                  (k) =>
                                                                      k !==
                                                                      m.key,
                                                              ),
                                                    )
                                                }
                                            />
                                            <span className="grid flex-1">
                                                <span className="font-medium">
                                                    {m.label}
                                                    {m.always &&
                                                        ' (always included)'}
                                                </span>
                                                <span className="text-sm text-muted-foreground">
                                                    {m.about}
                                                </span>
                                            </span>
                                            {line && (
                                                <span className="text-sm whitespace-nowrap">
                                                    Our cost{' '}
                                                    {money(line.cost_cents)}
                                                </span>
                                            )}
                                        </label>
                                        {on && m.usage.length > 0 && (
                                            <div className="grid gap-2 pl-7 sm:grid-cols-2">
                                                {m.usage.map((u) => {
                                                    const item =
                                                        line?.items.find(
                                                            (i) =>
                                                                i.usage ===
                                                                u.key,
                                                        );
                                                    return (
                                                        <label
                                                            key={u.key}
                                                            className="flex items-center gap-2 text-sm"
                                                        >
                                                            <input
                                                                type="number"
                                                                min={0}
                                                                className="h-9 w-24 rounded-md border bg-background px-2"
                                                                value={
                                                                    usage[
                                                                        m.key
                                                                    ]?.[
                                                                        u.key
                                                                    ] ??
                                                                    u.default
                                                                }
                                                                onChange={(e) =>
                                                                    setUsage({
                                                                        ...usage,
                                                                        [m.key]:
                                                                            {
                                                                                ...usage[
                                                                                    m
                                                                                        .key
                                                                                ],
                                                                                [u.key]:
                                                                                    Math.max(
                                                                                        0,
                                                                                        Number(
                                                                                            e
                                                                                                .target
                                                                                                .value,
                                                                                        ),
                                                                                    ),
                                                                            },
                                                                    })
                                                                }
                                                            />
                                                            {u.label}{' '}
                                                            {u.per === 'seat'
                                                                ? 'per person'
                                                                : 'per package'}
                                                            {item && (
                                                                <span
                                                                    title={
                                                                        item.tip
                                                                    }
                                                                    aria-label={
                                                                        item.tip
                                                                    }
                                                                    className="inline-flex cursor-help items-center gap-1 text-muted-foreground"
                                                                >
                                                                    <InfoIcon className="size-4" />
                                                                    {money(
                                                                        item.cost_cents,
                                                                    )}
                                                                </span>
                                                            )}
                                                        </label>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </div>

                    <aside className="grid content-start gap-3 rounded-md bg-muted/40 p-4 text-sm lg:sticky lg:top-4">
                        <h3 className="text-base font-medium">The numbers</h3>
                        {quote && (
                            <>
                                <dl className="grid grid-cols-[1fr_auto] gap-x-3 gap-y-1">
                                    <dt>Included usage (all used)</dt>
                                    <dd>
                                        {money(
                                            quote.lines.reduce(
                                                (s, l) => s + l.cost_cents,
                                                0,
                                            ),
                                        )}
                                    </dd>
                                    <dt title={quote.fixed.tip}>
                                        Platform and hosting ⓘ
                                    </dt>
                                    <dd>
                                        {money(
                                            quote.fixed.per_seat_cents +
                                                quote.fixed.per_brokerage_cents,
                                        )}
                                    </dd>
                                    <dt title="Card payment fees on the price (Stripe 2.9% + $0.30)">
                                        Card fees ⓘ
                                    </dt>
                                    <dd>{money(quote.card_fees_cents)}</dd>
                                    <dt className="font-medium">
                                        Our cost a month
                                    </dt>
                                    <dd className="font-medium">
                                        {money(quote.cost_cents)}
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        Typical month ({rules.typical_use}%
                                        used)
                                    </dt>
                                    <dd className="text-muted-foreground">
                                        {money(quote.typical_cost_cents)}
                                    </dd>
                                </dl>
                                <label className="grid gap-1">
                                    Markup %
                                    <input
                                        type="number"
                                        min={0}
                                        className="h-10 rounded-md border bg-background px-3"
                                        value={markup}
                                        onChange={(e) =>
                                            setMarkup(Number(e.target.value))
                                        }
                                    />
                                </label>
                                <p className="text-lg">
                                    Suggested price{' '}
                                    <strong>{money(quote.price_cents)}</strong>{' '}
                                    a month
                                </p>
                                <p className="text-muted-foreground">
                                    {money(quote.yearly_cents)} a year (
                                    {rules.yearly_months} months) · we keep{' '}
                                    {quote.margin_pct}%
                                </p>
                                {quote.worst_margin_pct < rules.min_markup && (
                                    <p className="text-amber-700 dark:text-amber-300">
                                        If every AI credit went on the costliest
                                        AI action, we would keep{' '}
                                        {quote.worst_margin_pct}% (
                                        {money(quote.worst_cost_cents)} cost).
                                    </p>
                                )}
                                {!quote.ai_priced && (
                                    <p className="text-amber-700 dark:text-amber-300">
                                        AI prices are not set up yet, so AI
                                        credits count as $0. Route AI models in
                                        HQ → AI gateway first.
                                    </p>
                                )}
                                {quote.unpriced.length > 0 && (
                                    <p className="text-amber-700 dark:text-amber-300">
                                        No cost entered yet for:{' '}
                                        {quote.unpriced.join(', ')} (HQ →
                                        Credits → Costs).
                                    </p>
                                )}
                                <label className="grid gap-1">
                                    Your price (leave empty for the suggested
                                    one)
                                    <input
                                        inputMode="decimal"
                                        className="h-10 rounded-md border bg-background px-3"
                                        value={price}
                                        onChange={(e) =>
                                            setPrice(e.target.value)
                                        }
                                        placeholder={String(
                                            quote.price_cents / 100,
                                        )}
                                    />
                                </label>
                                {(belowMin || errors.price) && (
                                    <label className="grid gap-1">
                                        Why below cost + {rules.min_markup}%?
                                        <input
                                            className="h-10 rounded-md border bg-background px-3"
                                            value={reason}
                                            onChange={(e) =>
                                                setReason(e.target.value)
                                            }
                                            placeholder="For example: launch offer until March"
                                        />
                                        {errors.price && (
                                            <span className="text-destructive">
                                                {errors.price}
                                            </span>
                                        )}
                                    </label>
                                )}
                                <Button onClick={save} disabled={name === ''}>
                                    Save package
                                </Button>
                            </>
                        )}
                    </aside>
                </section>
            </div>
        </>
    );
}
