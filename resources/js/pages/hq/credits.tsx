import { Head, router } from '@inertiajs/react';
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
    } | null;
    uses_30d: number;
    avg_tokens_in: number | null;
    avg_tokens_out: number | null;
    avg_cost_microcents: number | null;
    revenue_microcents: number;
    margin_pct: number | null;
    tokens_per_credit: number | null;
};
type Props = {
    credit_value_microcents: number;
    enforced: boolean;
    actions: Action[];
    packages: {
        id: string;
        name: string;
        monthly_cents: number;
        included_credits: number;
    }[];
    top_usage: { tenant: string; credits: number }[];
    tenants: { ulid: string; name: string }[];
    period: string;
};

/** Microcents (millionths of a cent) as dollars, with enough places for fractions of a cent. */
const dollars = (micro: number | null): string =>
    micro === null
        ? '—'
        : `$${(micro / 100_000_000).toFixed(micro < 10_000_000 ? 4 : 2)}`;
const n = (v: number | null) => (v === null ? '—' : v.toLocaleString());

function RateRow({ a }: { a: Action }) {
    const [credits, setCredits] = useState(String(a.credits));
    return (
        <tr className="border-t align-top">
            <td className="p-2">
                <div className="font-medium">{a.label}</div>
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
                            {a.model.provider} · in{' '}
                            {usd(a.model.input_per_mtok_cents)} / out{' '}
                            {usd(a.model.output_per_mtok_cents)} per 1M tokens
                        </div>
                    </>
                ) : (
                    <span className="text-muted-foreground">
                        Not routed yet
                    </span>
                )}
            </td>
            <td className="p-2 text-right">{n(a.uses_30d)}</td>
            <td className="p-2 text-right">
                {n(a.avg_tokens_in)} / {n(a.avg_tokens_out)}
            </td>
            <td className="p-2 text-right">{dollars(a.avg_cost_microcents)}</td>
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
                        onClick={() =>
                            router.post(
                                '/hq/credits/rates',
                                {
                                    action: a.action,
                                    credits: parseInt(credits, 10),
                                    automation: a.automation,
                                    label: a.label,
                                },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Save
                    </Button>
                </span>
            </td>
            <td className="p-2 text-right">{dollars(a.revenue_microcents)}</td>
            <td
                className={
                    a.margin_pct !== null && a.margin_pct < 30
                        ? 'p-2 text-right font-medium text-destructive'
                        : 'p-2 text-right'
                }
            >
                {a.margin_pct === null ? '—' : `${a.margin_pct}%`}
            </td>
            <td className="p-2 text-right">{n(a.tokens_per_credit)}</td>
        </tr>
    );
}

/** HQ → Credits: credit value, the price of each AI action and the real cost behind it, and package allowances. */
export default function HqCredits(props: Props) {
    const [value, setValue] = useState(
        (props.credit_value_microcents / 100_000_000).toString(),
    );
    const [grant, setGrant] = useState({ tenant: '', credits: '', note: '' });

    return (
        <>
            <Head title="Credits" />
            <div className="flex flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="RightAlly credits"
                    description="What a credit is worth, what each AI action costs in credits, and what it really costs us over the last 30 days."
                />
                <section className="flex flex-wrap items-center gap-3 rounded-lg border p-4 text-sm">
                    <span className="font-medium">Enforcement</span>
                    {props.enforced ? (
                        <Badge>On: AI stops when credits run out</Badge>
                    ) : (
                        <Badge variant="outline">
                            Off: usage is recorded and shown, nothing is blocked
                        </Badge>
                    )}
                    <Button
                        size="sm"
                        variant={props.enforced ? 'outline' : 'default'}
                        onClick={() =>
                            router.post(
                                '/hq/credits/enforce',
                                { enforce: !props.enforced },
                                { preserveScroll: true },
                            )
                        }
                    >
                        {props.enforced ? 'Turn off' : 'Turn on'}
                    </Button>
                    <span className="text-muted-foreground">
                        Turn on once every package includes credits.
                    </span>
                </section>
                <section className="flex flex-wrap items-end gap-3 rounded-lg border p-4 text-sm">
                    <label className="grid gap-1">
                        <span className="font-medium">
                            One credit is worth (US$)
                        </span>
                        <Input
                            type="number"
                            step="0.001"
                            min="0.00001"
                            className="w-32"
                            value={value}
                            onChange={(e) => setValue(e.target.value)}
                        />
                    </label>
                    <Button
                        size="sm"
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
                    <span className="text-muted-foreground">
                        Revenue and margin below use this value. Tokens per
                        credit = what one credit's value buys on that action's
                        model, at its real input/output mix.
                    </span>
                </section>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-[960px] text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-2">Action</th>
                                <th className="p-2">Model</th>
                                <th className="p-2 text-right">
                                    Uses (30 days)
                                </th>
                                <th className="p-2 text-right">
                                    Avg tokens in / out
                                </th>
                                <th className="p-2 text-right">Avg cost</th>
                                <th className="p-2">Credits</th>
                                <th className="p-2 text-right">
                                    Revenue per use
                                </th>
                                <th className="p-2 text-right">Margin</th>
                                <th className="p-2 text-right">
                                    Tokens per credit
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {props.actions.map((a) => (
                                <RateRow key={a.action} a={a} />
                            ))}
                        </tbody>
                    </table>
                </div>
                <section className="grid gap-3 md:grid-cols-2">
                    <div className="rounded-lg border p-4 text-sm">
                        <h2 className="mb-2 font-semibold">
                            Credits included in each package (monthly)
                        </h2>
                        <ul className="grid gap-2">
                            {props.packages.map((p) => (
                                <PackageRow
                                    key={p.id}
                                    p={p}
                                    value={props.credit_value_microcents}
                                />
                            ))}
                        </ul>
                    </div>
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
                </section>
                <section className="grid gap-2 rounded-lg border p-4 text-sm">
                    <h2 className="font-semibold">
                        Add or remove credits for a brokerage
                    </h2>
                    <div className="flex flex-wrap gap-2">
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
                        <Input
                            aria-label="Credits"
                            type="number"
                            className="w-28"
                            placeholder="e.g. 500"
                            value={grant.credits}
                            onChange={(e) =>
                                setGrant({ ...grant, credits: e.target.value })
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
                                !grant.tenant || !grant.credits || !grant.note
                            }
                            onClick={() =>
                                router.post(
                                    '/hq/credits/grant',
                                    {
                                        ...grant,
                                        credits: parseInt(grant.credits, 10),
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
                </section>
            </div>
        </>
    );
}

function PackageRow({
    p,
    value,
}: {
    p: Props['packages'][number];
    value: number;
}) {
    const [credits, setCredits] = useState(String(p.included_credits));
    const worth = p.included_credits * value;
    return (
        <li className="flex flex-wrap items-center justify-between gap-2">
            <span>
                {p.name}{' '}
                <span className="text-muted-foreground">
                    ({usd(p.monthly_cents)}/mo · credits worth {dollars(worth)})
                </span>
            </span>
            <span className="flex items-center gap-1">
                <Input
                    aria-label={`Included credits for ${p.name}`}
                    type="number"
                    min={0}
                    className="h-8 w-28"
                    value={credits}
                    onChange={(e) => setCredits(e.target.value)}
                />
                <Button
                    size="sm"
                    variant="outline"
                    disabled={
                        credits === String(p.included_credits) || credits === ''
                    }
                    onClick={() =>
                        router.post(
                            `/hq/credits/packages/${p.id}`,
                            { included_credits: parseInt(credits, 10) },
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

HqCredits.layout = {
    breadcrumbs: [{ title: 'Credits', href: '/hq/credits' }],
};
