import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Action = {
    action: string;
    label: string;
    credits: number;
    automation: boolean;
    feature: string | null;
    model: {
        name: string;
        provider: string;
        input_per_mtok_cents: number;
        output_per_mtok_cents: number;
        audio_per_minute_microcents: number;
    } | null;
    costliest_model: string | null;
    estimate: { tokens_in: number; tokens_out: number; audio_seconds: number };
    uses_30d: number;
    avg_tokens_in: number | null;
    avg_tokens_out: number | null;
    avg_cost_microcents: number | null;
    p90_cost_microcents: number | null;
    estimate_microcents: number | null;
    planning_microcents: number | null;
    revenue_microcents: number;
    margin_pct: number | null;
    suggested_credits: number | null;
    cost_per_credit_microcents: number | null;
    tokens_per_credit: number | null;
    below_floor: boolean;
};
type CatalogItem = {
    key: string;
    label: string;
    unit: string;
    kind: 'usage' | 'monthly';
    cost_microcents: number;
    free_units_monthly: number;
    note: string | null;
    source_url: string | null;
    checked_at: string | null;
};
type PackagePlan = {
    id: string;
    name: string;
    monthly_cents: number;
    active: boolean;
    margin_override_reason: string | null;
    included_credits: number;
    typical_margin_pct: number | null;
    worst_margin_pct: number | null;
    suggested_credits: number;
    fixed_microcents: number;
    allowance_microcents: number;
    included_text_segments: number;
    included_call_minutes: number;
    included_map_views: number;
};
type UnitCosts = { texts: number; calls: number; maps: number };
type Margins = {
    target: number;
    floor: number;
    usage_budget: number;
    maps_alert_views: number;
};
type Props = {
    credit_value_microcents: number;
    enforced: boolean;
    margins: Margins;
    map_views_total: number;
    actions: Action[];
    typical_cost_per_credit: number;
    worst_cost_per_credit: number;
    catalog: CatalogItem[];
    packages: PackagePlan[];
    top_usage: { tenant: string; credits: number }[];
    tenants: {
        ulid: string;
        name: string;
        maps_covered: boolean;
        map_views: number;
    }[];
    period: string;
    unit_costs: UnitCosts;
    packs: CreditPack[];
    sales: {
        count: number;
        revenue_cents: number;
        credits: number;
        recent: {
            tenant: string;
            pack: string;
            credits: number;
            total_cents: number;
            status: string;
            paid_at: string;
        }[];
    };
};

/** Micro-cents (millionths of a cent) as dollars, with more places for fractions of a cent. */
const dollars = (micro: number | null): string => {
    if (micro === null) return '—';
    const d = micro / 100_000_000;
    return `$${d.toFixed(d !== 0 && Math.abs(d) < 0.1 ? 4 : 2)}`;
};
const n = (v: number | null) => (v === null ? '—' : v.toLocaleString());

function marginClass(margin: number | null, floor: number) {
    if (margin === null) return 'text-muted-foreground';
    return margin < floor
        ? 'font-medium text-destructive'
        : 'text-emerald-700 dark:text-emerald-400';
}

function ActionRow({ a, floor }: { a: Action; floor: number }) {
    const [credits, setCredits] = useState(String(a.credits));
    const [open, setOpen] = useState(false);
    const [est, setEst] = useState({
        tokens_in: String(a.estimate.tokens_in),
        tokens_out: String(a.estimate.tokens_out),
        audio_seconds: String(a.estimate.audio_seconds),
    });
    const save = (withEstimate: boolean) =>
        router.post(
            '/hq/credits/rates',
            {
                action: a.action,
                credits: parseInt(credits, 10),
                automation: a.automation,
                label: a.label,
                ...(withEstimate
                    ? {
                          tokens_in: parseInt(est.tokens_in || '0', 10),
                          tokens_out: parseInt(est.tokens_out || '0', 10),
                          audio_seconds: parseInt(est.audio_seconds || '0', 10),
                      }
                    : {}),
            },
            { preserveScroll: true },
        );
    return (
        <>
            <tr className="border-t align-top">
                <td className="p-2">
                    <button
                        type="button"
                        className="text-left font-medium hover:underline"
                        onClick={() => setOpen(!open)}
                        aria-expanded={open}
                    >
                        {a.label}
                    </button>
                    <div className="text-xs text-muted-foreground">
                        {a.action}
                        {a.automation && (
                            <Badge variant="outline" className="ml-2">
                                Automatic
                            </Badge>
                        )}
                    </div>
                </td>
                <td className="p-2">
                    {a.model ? (
                        <>
                            <div>{a.model.name}</div>
                            <div className="text-xs text-muted-foreground">
                                {a.model.provider} ·{' '}
                                {a.model.audio_per_minute_microcents > 0
                                    ? `${dollars(a.model.audio_per_minute_microcents)} per audio minute`
                                    : `${usd(a.model.input_per_mtok_cents)} in / ${usd(a.model.output_per_mtok_cents)} out per 1M tokens`}
                            </div>
                            {a.costliest_model &&
                                a.costliest_model !== a.model.name && (
                                    <div className="text-xs text-amber-700 dark:text-amber-400">
                                        Planned on fallback {a.costliest_model}
                                    </div>
                                )}
                        </>
                    ) : (
                        <span className="text-muted-foreground">
                            Not routed yet
                        </span>
                    )}
                </td>
                <td className="p-2 text-right">{n(a.uses_30d)}</td>
                <td className="p-2 text-right">
                    {dollars(a.avg_cost_microcents)}
                </td>
                <td className="p-2 text-right font-medium">
                    {dollars(a.planning_microcents)}
                </td>
                <td className="p-2">
                    <span className="flex items-center gap-1">
                        <Input
                            aria-label={`Credits for ${a.label}`}
                            type="number"
                            min={0}
                            className="h-8 w-20"
                            value={credits}
                            onChange={(e) => setCredits(e.target.value)}
                        />
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={
                                credits === String(a.credits) || credits === ''
                            }
                            onClick={() => save(false)}
                        >
                            Save
                        </Button>
                    </span>
                </td>
                <td className="p-2 text-right">
                    {a.suggested_credits === null ? '—' : a.suggested_credits}
                    {a.action === 'usage.map_views_10' && (
                        <div className="text-xs text-muted-foreground">
                            now {dollars(a.revenue_microcents * 100)} per 1,000
                            views
                        </div>
                    )}
                </td>
                <td
                    className={`p-2 text-right ${marginClass(a.margin_pct, floor)}`}
                >
                    {a.margin_pct === null ? '—' : `${a.margin_pct}%`}
                </td>
                <td className="p-2 text-right">{n(a.tokens_per_credit)}</td>
            </tr>
            {open && (
                <tr className="bg-muted/30">
                    <td colSpan={9} className="p-3 text-xs">
                        <div className="grid gap-2 md:grid-cols-2">
                            <div className="grid gap-1">
                                <p>
                                    <strong>Real, last 30 days:</strong>{' '}
                                    {a.uses_30d === 0
                                        ? 'no uses yet'
                                        : `${n(a.uses_30d)} uses · avg ${n(a.avg_tokens_in)} tokens in / ${n(a.avg_tokens_out)} out · avg ${dollars(a.avg_cost_microcents)} · 90% of uses cost up to ${dollars(a.p90_cost_microcents)} (incl. retries)`}
                                </p>
                                <p>
                                    <strong>
                                        Estimate on the costliest model:
                                    </strong>{' '}
                                    {dollars(a.estimate_microcents)}
                                    {a.costliest_model &&
                                        ` (${a.costliest_model})`}
                                </p>
                                <p>
                                    <strong>Planning cost</strong> (the higher
                                    of the two):{' '}
                                    {dollars(a.planning_microcents)} · per
                                    credit{' '}
                                    {dollars(a.cost_per_credit_microcents)} ·
                                    sells for {dollars(a.revenue_microcents)}
                                </p>
                            </div>
                            <div className="flex flex-wrap items-end gap-2">
                                <label className="grid gap-1">
                                    Tokens in per use
                                    <Input
                                        type="number"
                                        min={0}
                                        className="h-8 w-28"
                                        value={est.tokens_in}
                                        onChange={(e) =>
                                            setEst({
                                                ...est,
                                                tokens_in: e.target.value,
                                            })
                                        }
                                    />
                                </label>
                                <label className="grid gap-1">
                                    Tokens out
                                    <Input
                                        type="number"
                                        min={0}
                                        className="h-8 w-24"
                                        value={est.tokens_out}
                                        onChange={(e) =>
                                            setEst({
                                                ...est,
                                                tokens_out: e.target.value,
                                            })
                                        }
                                    />
                                </label>
                                <label className="grid gap-1">
                                    Audio seconds
                                    <Input
                                        type="number"
                                        min={0}
                                        className="h-8 w-24"
                                        value={est.audio_seconds}
                                        onChange={(e) =>
                                            setEst({
                                                ...est,
                                                audio_seconds: e.target.value,
                                            })
                                        }
                                    />
                                </label>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => save(true)}
                                >
                                    Save estimate
                                </Button>
                            </div>
                        </div>
                    </td>
                </tr>
            )}
        </>
    );
}

function PackageRow({
    p,
    typical,
    worst,
    floor,
    units,
}: {
    p: PackagePlan;
    typical: number;
    worst: number;
    floor: number;
    units: UnitCosts;
}) {
    const [credits, setCredits] = useState(String(p.included_credits));
    const [reason, setReason] = useState(p.margin_override_reason ?? '');
    const [allow, setAllow] = useState({
        included_text_segments: String(p.included_text_segments),
        included_call_minutes: String(p.included_call_minutes),
        included_map_views: String(p.included_map_views),
    });
    const num = (v: string) => parseInt(v || '0', 10);
    const c = num(credits);
    const allowanceCost =
        num(allow.included_text_segments) * units.texts +
        num(allow.included_call_minutes) * units.calls +
        num(allow.included_map_views) * units.maps;
    const changed =
        credits !== String(p.included_credits) ||
        allow.included_text_segments !== String(p.included_text_segments) ||
        allow.included_call_minutes !== String(p.included_call_minutes) ||
        allow.included_map_views !== String(p.included_map_views);
    const revenue = p.monthly_cents * 1_000_000;
    const margin = (cost: number) =>
        revenue > 0
            ? Math.floor(
                  ((revenue - cost - p.fixed_microcents - allowanceCost) *
                      100) /
                      revenue,
              )
            : null;
    const typicalMargin = margin(c * typical);
    const worstMargin = margin(c * worst);
    const below = typicalMargin !== null && typicalMargin < floor;
    const field = (
        key: keyof typeof allow,
        label: string,
        unitCost: number,
        note?: string,
    ) => (
        <label className="grid gap-1">
            {label}
            <Input
                type="number"
                min={0}
                className="h-8 w-28"
                value={allow[key]}
                onChange={(e) => setAllow({ ...allow, [key]: e.target.value })}
            />
            <span className="text-[11px] text-muted-foreground">
                costs you up to {dollars(num(allow[key]) * unitCost)} a month
                {note ? ` ${note}` : ''}
            </span>
        </label>
    );
    return (
        <>
            <tr className="border-t align-top">
                <td className="p-2">
                    <div className="font-medium">{p.name}</div>
                    <div className="text-xs text-muted-foreground">
                        {usd(p.monthly_cents)}/mo{!p.active && ' · inactive'}
                    </div>
                </td>
                <td className="p-2">
                    <Input
                        aria-label={`Included credits for ${p.name}`}
                        type="number"
                        min={0}
                        className="h-8 w-28"
                        value={credits}
                        onChange={(e) => setCredits(e.target.value)}
                    />
                    <button
                        type="button"
                        className="mt-1 text-xs text-muted-foreground underline"
                        onClick={() => setCredits(String(p.suggested_credits))}
                    >
                        Suggested: {n(p.suggested_credits)}
                    </button>
                </td>
                <td className="p-2 text-right">{dollars(c * typical)}</td>
                <td className="p-2 text-right">{dollars(c * worst)}</td>
                <td className="p-2 text-right">
                    {dollars(p.fixed_microcents + allowanceCost)}
                </td>
                <td
                    className={`p-2 text-right ${marginClass(typicalMargin, floor)}`}
                >
                    {typicalMargin === null ? '—' : `${typicalMargin}%`}
                </td>
                <td className={`p-2 text-right ${marginClass(worstMargin, 0)}`}>
                    {worstMargin === null ? '—' : `${worstMargin}%`}
                </td>
                <td className="p-2">
                    {below && (
                        <Input
                            aria-label={`Reason for ${p.name} below minimum margin`}
                            className="mb-1 h-8 w-56"
                            placeholder="Reason (below minimum margin)"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                        />
                    )}
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={credits === '' || (!changed && !below)}
                        onClick={() =>
                            router.post(
                                `/hq/credits/packages/${p.id}`,
                                {
                                    included_credits: c,
                                    included_text_segments: num(
                                        allow.included_text_segments,
                                    ),
                                    included_call_minutes: num(
                                        allow.included_call_minutes,
                                    ),
                                    included_map_views: num(
                                        allow.included_map_views,
                                    ),
                                    reason: below ? reason : null,
                                },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Save
                    </Button>
                </td>
            </tr>
            <tr className="text-xs">
                <td className="p-2 pt-0 text-muted-foreground">
                    Included each month
                </td>
                <td colSpan={7} className="p-2 pt-0">
                    <div className="flex flex-wrap gap-4">
                        {field(
                            'included_text_segments',
                            'Texts (segments)',
                            units.texts,
                        )}
                        {field(
                            'included_call_minutes',
                            'Call minutes',
                            units.calls,
                        )}
                        {field(
                            'included_map_views',
                            'Website map views',
                            units.maps,
                            '(free while all brokerages together stay under 50,000)',
                        )}
                    </div>
                </td>
            </tr>
        </>
    );
}

type CreditPack = {
    ulid: string;
    name: string;
    credits: number;
    price_cents: number;
    audience: 'brokerage' | 'agent' | 'both';
    active: boolean;
    sort: number;
};

/** One credit pack (or a new one): price, credits, who may buy it, and its margin at the typical cost per credit. */
function PackRow({
    pack,
    typical,
    floor,
}: {
    pack: CreditPack | null;
    typical: number;
    floor: number;
}) {
    const [f, setF] = useState({
        name: pack?.name ?? '',
        credits: pack ? String(pack.credits) : '',
        price: pack ? String(pack.price_cents / 100) : '',
        audience: pack?.audience ?? 'both',
        active: pack?.active ?? true,
        sort: pack ? String(pack.sort) : '0',
    });
    const credits = parseInt(f.credits || '0', 10);
    const priceMicro = Math.round(parseFloat(f.price || '0') * 100) * 1_000_000;
    const cost = credits * typical;
    const margin =
        priceMicro > 0
            ? Math.floor(((priceMicro - cost) * 100) / priceMicro)
            : null;
    const save = () =>
        pack
            ? router.put(
                  `/hq/credits/packs/${pack.ulid}`,
                  { ...f, sort: parseInt(f.sort || '0', 10) },
                  { preserveScroll: true },
              )
            : router.post(
                  '/hq/credits/packs',
                  { ...f, sort: parseInt(f.sort || '0', 10) },
                  {
                      preserveScroll: true,
                      onSuccess: () =>
                          setF({
                              name: '',
                              credits: '',
                              price: '',
                              audience: 'both',
                              active: true,
                              sort: '0',
                          }),
                  },
              );
    return (
        <li className="flex flex-wrap items-end gap-2 p-3">
            <label className="grid gap-1">
                Name
                <Input
                    className="h-8 w-36"
                    value={f.name}
                    onChange={(e) => setF({ ...f, name: e.target.value })}
                />
            </label>
            <label className="grid gap-1">
                Credits
                <Input
                    type="number"
                    min={1}
                    className="h-8 w-24"
                    value={f.credits}
                    onChange={(e) => setF({ ...f, credits: e.target.value })}
                />
            </label>
            <label className="grid gap-1">
                Price (US$)
                <Input
                    type="number"
                    min={1}
                    step="0.01"
                    className="h-8 w-24"
                    value={f.price}
                    onChange={(e) => setF({ ...f, price: e.target.value })}
                />
            </label>
            <label className="grid gap-1">
                Who can buy
                <select
                    className="h-8 rounded-md border bg-background px-2"
                    value={f.audience}
                    onChange={(e) =>
                        setF({
                            ...f,
                            audience: e.target.value as CreditPack['audience'],
                        })
                    }
                >
                    <option value="both">Brokerages and agents</option>
                    <option value="brokerage">Brokerages</option>
                    <option value="agent">Agents</option>
                </select>
            </label>
            <label className="flex items-center gap-1 pb-1">
                <input
                    type="checkbox"
                    checked={f.active}
                    onChange={(e) => setF({ ...f, active: e.target.checked })}
                />
                On sale
            </label>
            <span className="pb-1 text-xs text-muted-foreground">
                {credits > 0 && priceMicro > 0 && (
                    <>
                        {(priceMicro / credits / 1_000_000).toFixed(2)}¢ a
                        credit · costs you about {dollars(cost)} if all used ·{' '}
                        <span className={marginClass(margin, floor)}>
                            margin {margin}%
                        </span>
                    </>
                )}
            </span>
            <Button
                size="sm"
                variant="outline"
                onClick={save}
                disabled={!f.name || !f.credits || !f.price}
            >
                {pack ? 'Save' : 'Add pack'}
            </Button>
        </li>
    );
}

function CatalogRow({ item }: { item: CatalogItem }) {
    const [cost, setCost] = useState(
        (item.cost_microcents / 100_000_000).toString(),
    );
    const [free, setFree] = useState(String(item.free_units_monthly));
    return (
        <li className="flex flex-wrap items-center justify-between gap-2 p-3">
            <span>
                <span className="font-medium">{item.label}</span>
                <span className="text-muted-foreground">
                    {' '}
                    · per {item.unit}
                    {item.note && ` · ${item.note}`}
                    {item.checked_at && ` · checked ${item.checked_at}`}
                </span>
                {item.source_url && (
                    <a
                        href={item.source_url}
                        target="_blank"
                        rel="noreferrer"
                        className="ml-2 text-xs underline"
                    >
                        Vendor prices
                    </a>
                )}
            </span>
            <span className="flex items-center gap-1">
                $
                <Input
                    aria-label={`Cost per ${item.unit}`}
                    type="number"
                    step="0.0001"
                    min={0}
                    className="h-8 w-28"
                    value={cost}
                    onChange={(e) => setCost(e.target.value)}
                />
                {item.kind === 'usage' && (
                    <>
                        <span className="ml-2 text-xs text-muted-foreground">
                            free/month
                        </span>
                        <Input
                            aria-label={`Free ${item.unit}s per month`}
                            type="number"
                            min={0}
                            className="h-8 w-24"
                            value={free}
                            onChange={(e) => setFree(e.target.value)}
                        />
                    </>
                )}
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        router.post(
                            `/hq/credits/costs/${item.key}`,
                            {
                                cost,
                                free_units_monthly: parseInt(free || '0', 10),
                            },
                            { preserveScroll: true },
                        )
                    }
                >
                    Save
                </Button>
            </span>
        </li>
    );
}

/** HQ → RightAlly credits: what credits sell for, what they really cost, and whether each package makes money. */
export default function HqCredits(props: Props) {
    const errors = usePage().props.errors as Record<string, string> | undefined;
    const [value, setValue] = useState(
        (props.credit_value_microcents / 100_000_000).toString(),
    );
    const [margins, setMargins] = useState({
        target: String(props.margins.target),
        floor: String(props.margins.floor),
        usage_budget: String(props.margins.usage_budget),
        maps_alert_views: String(props.margins.maps_alert_views),
    });
    const [grant, setGrant] = useState({ tenant: '', credits: '', note: '' });
    const below = props.actions.filter((a) => a.below_floor).length;
    const unpriced = props.actions.filter(
        (a) => a.planning_microcents === null,
    ).length;
    const typicalMargin =
        props.credit_value_microcents > 0
            ? Math.floor(
                  ((props.credit_value_microcents -
                      props.typical_cost_per_credit) *
                      100) /
                      props.credit_value_microcents,
              )
            : null;

    return (
        <>
            <Head title="RightAlly credits" />
            <div className="flex flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="RightAlly credits"
                    description="What a credit sells for, what it really costs on each AI model, and whether each package makes money."
                />

                <section className="grid gap-3 rounded-lg border p-4 text-sm">
                    <div className="flex flex-wrap items-center gap-3">
                        <span className="font-medium">Credits</span>
                        {props.enforced ? (
                            <Badge>On: AI stops when credits run out</Badge>
                        ) : (
                            <Badge variant="outline">
                                Off: usage is recorded, nothing is blocked
                            </Badge>
                        )}
                        {!props.enforced && (
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        '/hq/credits/go-live',
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Set suggested credits and switch on
                            </Button>
                        )}
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    '/hq/credits/enforce',
                                    { enforce: !props.enforced },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {props.enforced
                                ? 'Switch off'
                                : 'Switch on without changing packages'}
                        </Button>
                    </div>
                    {errors?.enforce && (
                        <p className="text-destructive">{errors.enforce}</p>
                    )}
                    <p className="text-muted-foreground">
                        "Set suggested credits and switch on" gives each active
                        package without credits its suggested amount (below),
                        then switches credits on. Both ask for your password.
                    </p>
                </section>

                <section className="grid gap-3 rounded-lg border p-4 text-sm md:grid-cols-2">
                    <div className="grid gap-2">
                        <h2 className="font-semibold">
                            Price and margin rules
                        </h2>
                        <div className="flex flex-wrap items-end gap-3">
                            <label className="grid gap-1">
                                One credit sells for (US$)
                                <Input
                                    type="number"
                                    step="0.001"
                                    min="0.00001"
                                    className="w-28"
                                    value={value}
                                    onChange={(e) => setValue(e.target.value)}
                                />
                            </label>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        '/hq/credits/value',
                                        { dollars: value },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Save
                            </Button>
                        </div>
                        <div className="flex flex-wrap items-end gap-3">
                            <label className="grid gap-1">
                                Target margin %
                                <Input
                                    type="number"
                                    className="w-20"
                                    value={margins.target}
                                    onChange={(e) =>
                                        setMargins({
                                            ...margins,
                                            target: e.target.value,
                                        })
                                    }
                                />
                            </label>
                            <label className="grid gap-1">
                                Minimum margin %
                                <Input
                                    type="number"
                                    className="w-20"
                                    value={margins.floor}
                                    onChange={(e) =>
                                        setMargins({
                                            ...margins,
                                            floor: e.target.value,
                                        })
                                    }
                                />
                            </label>
                            <label className="grid gap-1">
                                Usage budget % of package price
                                <Input
                                    type="number"
                                    className="w-20"
                                    value={margins.usage_budget}
                                    onChange={(e) =>
                                        setMargins({
                                            ...margins,
                                            usage_budget: e.target.value,
                                        })
                                    }
                                />
                            </label>
                            <label className="grid gap-1">
                                Email me past this many map views a month
                                <Input
                                    type="number"
                                    className="w-28"
                                    value={margins.maps_alert_views}
                                    onChange={(e) =>
                                        setMargins({
                                            ...margins,
                                            maps_alert_views: e.target.value,
                                        })
                                    }
                                />
                            </label>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        '/hq/credits/margins',
                                        margins,
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Save
                            </Button>
                        </div>
                        {errors?.target && (
                            <p className="text-destructive">{errors.target}</p>
                        )}
                    </div>
                    <div className="grid content-start gap-1">
                        <h2 className="font-semibold">
                            What a credit costs you
                        </h2>
                        <p>
                            Typical cost per credit:{' '}
                            <strong>
                                {dollars(props.typical_cost_per_credit)}
                            </strong>{' '}
                            (sells for {dollars(props.credit_value_microcents)}
                            {typicalMargin !== null && (
                                <>
                                    , margin{' '}
                                    <span
                                        className={marginClass(
                                            typicalMargin,
                                            props.margins.floor,
                                        )}
                                    >
                                        {typicalMargin}%
                                    </span>
                                </>
                            )}
                            )
                        </p>
                        <p>
                            Worst case per credit:{' '}
                            <strong>
                                {dollars(props.worst_cost_per_credit)}
                            </strong>{' '}
                            (every credit spent on the costliest action)
                        </p>
                        <p className="text-muted-foreground">
                            Typical uses the real mix of actions over the last
                            30 days. Each action is planned at the higher of its
                            real cost (90% of uses, including retries) and an
                            estimate on the costliest model it can fall back to.
                        </p>
                        {unpriced > 0 && (
                            <p className="text-amber-700 dark:text-amber-400">
                                {unpriced} action(s) have no model price yet.
                                Add models and prices in HQ → AI gateway.
                            </p>
                        )}
                    </div>
                </section>

                <section className="grid gap-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="font-semibold">Price of each action</h2>
                        {below > 0 && (
                            <Badge variant="destructive">
                                {below} below minimum margin
                            </Badge>
                        )}
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    '/hq/credits/rates/apply-suggested',
                                    { only_below_floor: true },
                                    { preserveScroll: true },
                                )
                            }
                            disabled={below === 0}
                        >
                            Fix those below minimum
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    '/hq/credits/rates/apply-suggested',
                                    { only_below_floor: false },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Apply all suggested prices
                        </Button>
                        <span className="text-xs text-muted-foreground">
                            Click an action to see its tokens and set its
                            estimate.
                        </span>
                    </div>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full min-w-[1000px] text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-2">Action</th>
                                    <th className="p-2">Model</th>
                                    <th className="p-2 text-right">
                                        Uses (30 days)
                                    </th>
                                    <th className="p-2 text-right">Avg cost</th>
                                    <th className="p-2 text-right">
                                        Planning cost
                                    </th>
                                    <th className="p-2">Credits</th>
                                    <th className="p-2 text-right">
                                        Suggested
                                    </th>
                                    <th className="p-2 text-right">Margin</th>
                                    <th className="p-2 text-right">
                                        Tokens per credit
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {props.actions.map((a) => (
                                    <ActionRow
                                        key={a.action}
                                        a={a}
                                        floor={props.margins.floor}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="grid gap-2">
                    <h2 className="font-semibold">
                        Packages: will they make money?
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Monthly, per brokerage, if every included credit is
                        used. "Typical" uses the real mix of actions; "worst"
                        assumes every credit goes on the costliest action. Fixed
                        costs come from the cost list below.
                    </p>
                    {errors?.reason && (
                        <p className="text-sm text-destructive">
                            {errors.reason}
                        </p>
                    )}
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full min-w-[960px] text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-2">Package</th>
                                    <th className="p-2">Included credits</th>
                                    <th className="p-2 text-right">
                                        Typical cost
                                    </th>
                                    <th className="p-2 text-right">
                                        Worst cost
                                    </th>
                                    <th className="p-2 text-right">
                                        Fixed costs
                                    </th>
                                    <th className="p-2 text-right">
                                        Typical margin
                                    </th>
                                    <th className="p-2 text-right">
                                        Worst margin
                                    </th>
                                    <th className="p-2">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {props.packages.map((p) => (
                                    <PackageRow
                                        key={p.id}
                                        p={p}
                                        typical={props.typical_cost_per_credit}
                                        worst={props.worst_cost_per_credit}
                                        floor={props.margins.floor}
                                        units={props.unit_costs}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="grid gap-2">
                    <h2 className="font-semibold">Credit packs for sale</h2>
                    <p className="text-sm text-muted-foreground">
                        What brokerages (for their pool) and agents (for
                        themselves) can buy. Bought credits last 12 months. This
                        month: {n(props.sales.count)} sold,{' '}
                        {usd(props.sales.revenue_cents)} including tax,{' '}
                        {n(props.sales.credits)} credits.
                    </p>
                    <ul className="divide-y rounded-lg border text-sm">
                        {props.packs.map((p) => (
                            <PackRow
                                key={p.ulid}
                                pack={p}
                                typical={props.typical_cost_per_credit}
                                floor={props.margins.floor}
                            />
                        ))}
                        <PackRow
                            pack={null}
                            typical={props.typical_cost_per_credit}
                            floor={props.margins.floor}
                        />
                    </ul>
                    {props.sales.recent.length > 0 && (
                        <details className="rounded-lg border p-3 text-sm">
                            <summary className="cursor-pointer font-medium">
                                Latest purchases
                            </summary>
                            <ul className="mt-2 grid gap-1">
                                {props.sales.recent.map((r, i) => (
                                    <li
                                        key={i}
                                        className="flex justify-between gap-2"
                                    >
                                        <span>
                                            {r.tenant} · {r.pack} ·{' '}
                                            {n(r.credits)} credits
                                            {r.status === 'refunded' &&
                                                ' · refunded'}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {usd(r.total_cents)} ·{' '}
                                            {new Date(
                                                r.paid_at,
                                            ).toLocaleDateString()}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </details>
                    )}
                </section>

                <section className="grid gap-2">
                    <h2 className="font-semibold">
                        Website maps by brokerage ({props.period})
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {n(props.map_views_total)} map views across all websites
                        this month. Views beyond a package's allowance use the
                        brokerage's credits (with automatic top-up from its
                        saved card) unless RightAlly covers them. Only a
                        brokerage that doesn't pay has its own map paused;
                        owners are told how many visitors tried to use it.
                    </p>
                    <ul className="divide-y rounded-lg border text-sm">
                        {props.tenants.length === 0 && (
                            <li className="p-3 text-muted-foreground">
                                No brokerages yet.
                            </li>
                        )}
                        {props.tenants.map((t) => (
                            <li
                                key={t.ulid}
                                className="flex flex-wrap items-center justify-between gap-2 p-3"
                            >
                                <span>
                                    {t.name}{' '}
                                    <span className="text-muted-foreground">
                                        · {n(t.map_views)} map views this month
                                    </span>
                                </span>
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={t.maps_covered}
                                        onChange={(e) =>
                                            router.post(
                                                `/hq/credits/tenants/${t.ulid}/maps-covered`,
                                                { covered: e.target.checked },
                                                { preserveScroll: true },
                                            )
                                        }
                                    />
                                    RightAlly covers extra map views
                                </label>
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="grid gap-2">
                    <h2 className="font-semibold">
                        Other costs (texts, calls, maps, email, hosting)
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Prices from the vendors' US price pages; confirm them
                        now and then. Monthly items count as a fixed cost per
                        brokerage in the package margins; the text, call and map
                        allowances above are priced from these.
                    </p>
                    <ul className="divide-y rounded-lg border text-sm">
                        {props.catalog.map((item) => (
                            <CatalogRow key={item.key} item={item} />
                        ))}
                    </ul>
                </section>

                <section className="grid gap-3 md:grid-cols-2">
                    <div className="rounded-lg border p-4 text-sm">
                        <h2 className="mb-2 font-semibold">
                            Most credits used ({props.period})
                        </h2>
                        {props.top_usage.length === 0 && (
                            <p className="text-muted-foreground">
                                No use yet this month.
                            </p>
                        )}
                        <ul className="grid gap-1">
                            {props.top_usage.map((t, i) => (
                                <li key={i} className="flex justify-between">
                                    <span>{t.tenant}</span>
                                    <span className="text-muted-foreground">
                                        {n(t.credits)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                    <div className="grid content-start gap-2 rounded-lg border p-4 text-sm">
                        <h2 className="font-semibold">
                            Add or remove credits for a brokerage
                        </h2>
                        <select
                            aria-label="Brokerage"
                            className="h-9 rounded-md border bg-background px-2"
                            value={grant.tenant}
                            onChange={(e) =>
                                setGrant({ ...grant, tenant: e.target.value })
                            }
                        >
                            <option value="">Choose a brokerage</option>
                            {props.tenants.map((t) => (
                                <option key={t.ulid} value={t.ulid}>
                                    {t.name}
                                </option>
                            ))}
                        </select>
                        <div className="flex flex-wrap gap-2">
                            <Input
                                aria-label="Credits"
                                type="number"
                                className="w-28"
                                placeholder="e.g. 500"
                                value={grant.credits}
                                onChange={(e) =>
                                    setGrant({
                                        ...grant,
                                        credits: e.target.value,
                                    })
                                }
                            />
                            <Input
                                aria-label="Reason"
                                className="max-w-xs"
                                placeholder="Reason (shown to the brokerage)"
                                value={grant.note}
                                onChange={(e) =>
                                    setGrant({ ...grant, note: e.target.value })
                                }
                            />
                            <Button
                                size="sm"
                                disabled={
                                    !grant.tenant ||
                                    !grant.credits ||
                                    !grant.note
                                }
                                onClick={() =>
                                    router.post(
                                        '/hq/credits/grant',
                                        {
                                            ...grant,
                                            credits: parseInt(
                                                grant.credits,
                                                10,
                                            ),
                                        },
                                        {
                                            preserveScroll: true,
                                            onSuccess: () =>
                                                setGrant({
                                                    tenant: '',
                                                    credits: '',
                                                    note: '',
                                                }),
                                        },
                                    )
                                }
                            >
                                Apply
                            </Button>
                        </div>
                    </div>
                </section>
            </div>
        </>
    );
}

HqCredits.layout = {
    breadcrumbs: [{ title: 'RightAlly credits', href: '/hq/credits' }],
};
