import { Head, Link, router, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { StatCard } from '@/components/ui/stat-card';
import { channelLabels } from '@/pages/crm/leads/index';

type Row = {
    key: string;
    leads: number;
    contacted: number;
    engaged: number;
    hot: number;
    deals: number;
    closed: number;
    gci_cents: number;
    company_dollar_cents: number;
    spend_cents: number;
    cost_per_lead_cents: number | null;
    cost_per_closing_cents: number | null;
    return: number | null;
};
type Agent = {
    id: number;
    name: string;
    leads: number;
    contacted: number;
    median_minutes: number | null;
    within_target: number | null;
    passed_over: number;
    waiting_now: number;
    waiting_over_hour: number;
    handoffs_open: number;
};
type Props = {
    days: number;
    returns: {
        sources: Row[];
        channels: Row[];
        totals: {
            leads: number;
            closed: number;
            gci_cents: number;
            company_dollar_cents: number;
            spend_cents: number;
            return: number | null;
        };
    };
    scorecard: {
        agents: Agent[];
        sources: {
            key: string;
            leads: number;
            median_minutes: number | null;
            within_target: number;
        }[];
        targets: { target_minutes: number; target_percent: number };
    };
    sourceOptions: { value: string; label: string }[];
    channels: string[];
    month: string;
};

const money = (cents: number | null) =>
    cents === null
        ? '—'
        : (cents / 100).toLocaleString(undefined, {
              style: 'currency',
              currency: 'USD',
              maximumFractionDigits: 0,
          });

/** Return on spend by source and channel (to commission), and the speed-to-lead scorecard (owners and staff). */
export default function LeadReport({
    days,
    returns,
    scorecard,
    sourceOptions,
    channels,
    month,
}: Props) {
    const opts = { preserveScroll: true };
    const spend = useForm({
        month,
        key: sourceOptions[0]?.value ?? '',
        amount: '',
    });
    const targets = useForm(scorecard.targets);
    const sourceLabel = (k: string) =>
        sourceOptions.find((o) => o.value === k)?.label ?? k;
    const t = returns.totals;

    const table = (rows: Row[], label: (k: string) => string) => (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full min-w-[760px] text-sm">
                <thead className="text-left text-xs text-muted-foreground">
                    <tr>
                        {[
                            '',
                            'Leads',
                            'Contacted',
                            'Replied',
                            'Hot',
                            'Deals',
                            'Closed',
                            'Commission',
                            'Company dollar',
                            'Spend',
                            'Per lead',
                            'Per closing',
                            'Return',
                        ].map((h) => (
                            <th key={h} className="p-2">
                                {h}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {rows.map((r) => (
                        <tr key={r.key}>
                            <td className="p-2 font-medium">{label(r.key)}</td>
                            <td className="p-2">{r.leads}</td>
                            <td className="p-2">{r.contacted}</td>
                            <td className="p-2">{r.engaged}</td>
                            <td className="p-2">{r.hot}</td>
                            <td className="p-2">{r.deals}</td>
                            <td className="p-2">{r.closed}</td>
                            <td className="p-2">{money(r.gci_cents)}</td>
                            <td className="p-2">
                                {money(r.company_dollar_cents)}
                            </td>
                            <td className="p-2">
                                {r.spend_cents ? money(r.spend_cents) : '—'}
                            </td>
                            <td className="p-2">
                                {money(r.cost_per_lead_cents)}
                            </td>
                            <td className="p-2">
                                {money(r.cost_per_closing_cents)}
                            </td>
                            <td className="p-2">
                                {r.return === null ? '—' : `${r.return}×`}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );

    return (
        <>
            <Head title="Lead return and speed" />
            <div className="mx-auto grid w-full max-w-6xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Lead return and speed"
                    description="What each source and channel costs and earns, down to commission, and how fast each agent reaches leads."
                    actions={
                        <span className="flex flex-wrap items-center gap-2">
                            <select
                                aria-label="Period"
                                className="rounded border bg-background px-2 py-1.5 text-sm"
                                value={days}
                                onChange={(e) =>
                                    router.get(
                                        '/leads/report',
                                        { days: e.target.value },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <option value={30}>Last 30 days</option>
                                <option value={90}>Last 90 days</option>
                                <option value={180}>Last 6 months</option>
                                <option value={365}>Last 12 months</option>
                            </select>
                            <Button variant="outline" asChild>
                                <Link href="/leads">Back to leads</Link>
                            </Button>
                        </span>
                    }
                />

                <div className="grid gap-3 sm:grid-cols-4">
                    <StatCard label="Leads" value={t.leads} />
                    <StatCard label="Closed deals" value={t.closed} />
                    <StatCard
                        label="Company dollar"
                        value={money(t.company_dollar_cents)}
                    />
                    <StatCard
                        label="Return on spend"
                        value={t.return === null ? '—' : `${t.return}×`}
                    />
                </div>

                <section className="grid gap-2 text-sm">
                    <h2 className="font-semibold">By source</h2>
                    {table(returns.sources, sourceLabel)}
                </section>
                <section className="grid gap-2 text-sm">
                    <h2 className="font-semibold">By channel</h2>
                    <p className="text-muted-foreground">
                        AI search shows people who found you through ChatGPT,
                        Perplexity, Gemini, Copilot or Claude; Search (SEO)
                        through Google or Bing. Enter what you spend on SEO and
                        content to see their return.
                    </p>
                    {table(returns.channels, (k) => channelLabels[k] ?? k)}
                </section>

                <form
                    className="flex flex-wrap items-end gap-2 rounded-lg border p-3 text-sm"
                    onSubmit={(e) => {
                        e.preventDefault();
                        spend.post('/leads/report/spend', {
                            ...opts,
                            onSuccess: () => spend.reset('amount'),
                        });
                    }}
                >
                    <h3 className="w-full font-medium">Enter monthly spend</h3>
                    <label className="grid gap-1">
                        Month
                        <Input
                            type="month"
                            value={spend.data.month}
                            onChange={(e) =>
                                spend.setData('month', e.target.value)
                            }
                        />
                    </label>
                    <label className="grid gap-1">
                        Source or channel
                        <select
                            className="rounded border bg-background px-2 py-1.5"
                            value={spend.data.key}
                            onChange={(e) =>
                                spend.setData('key', e.target.value)
                            }
                        >
                            <optgroup label="Sources">
                                {sourceOptions.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </optgroup>
                            <optgroup label="Channels">
                                {channels.map((c) => (
                                    <option key={c} value={`channel:${c}`}>
                                        {channelLabels[c] ?? c}
                                    </option>
                                ))}
                            </optgroup>
                        </select>
                    </label>
                    <label className="grid gap-1">
                        Amount ($)
                        <Input
                            inputMode="decimal"
                            className="w-32"
                            value={spend.data.amount}
                            onChange={(e) =>
                                spend.setData('amount', e.target.value)
                            }
                        />
                    </label>
                    <Button disabled={spend.processing}>Save</Button>
                    {Object.values(spend.errors)[0] && (
                        <p role="alert" className="w-full text-destructive">
                            {Object.values(spend.errors)[0]}
                        </p>
                    )}
                </form>

                <section className="grid gap-2 text-sm">
                    <div className="flex flex-wrap items-end gap-2">
                        <h2 className="font-semibold">Speed scorecard</h2>
                        <form
                            className="ml-auto flex flex-wrap items-end gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                targets.post('/leads/report/targets', opts);
                            }}
                        >
                            <label className="grid gap-1 text-xs">
                                Target: reach leads within (minutes)
                                <Input
                                    type="number"
                                    min={1}
                                    max={240}
                                    className="w-24"
                                    value={targets.data.target_minutes}
                                    onChange={(e) =>
                                        targets.setData(
                                            'target_minutes',
                                            Number(e.target.value),
                                        )
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-xs">
                                for this share of leads (%)
                                <Input
                                    type="number"
                                    min={1}
                                    max={100}
                                    className="w-24"
                                    value={targets.data.target_percent}
                                    onChange={(e) =>
                                        targets.setData(
                                            'target_percent',
                                            Number(e.target.value),
                                        )
                                    }
                                />
                            </label>
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={targets.processing}
                            >
                                Save target
                            </Button>
                        </form>
                    </div>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full min-w-[720px]">
                            <thead className="text-left text-xs text-muted-foreground">
                                <tr>
                                    {[
                                        'Agent',
                                        'Leads',
                                        'Contacted',
                                        'Median time',
                                        `Within ${scorecard.targets.target_minutes} min`,
                                        'Passed on',
                                        'Waiting now',
                                        'Waiting > 1 hour',
                                        'AI hand-offs open',
                                    ].map((h) => (
                                        <th key={h} className="p-2">
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {scorecard.agents.map((a) => (
                                    <tr key={a.id}>
                                        <td className="p-2 font-medium">
                                            {a.name}
                                        </td>
                                        <td className="p-2">{a.leads}</td>
                                        <td className="p-2">{a.contacted}</td>
                                        <td className="p-2">
                                            {a.median_minutes === null
                                                ? '—'
                                                : `${a.median_minutes} min`}
                                        </td>
                                        <td
                                            className={`p-2 ${a.within_target !== null && a.within_target < scorecard.targets.target_percent ? 'font-semibold text-destructive' : ''}`}
                                        >
                                            {a.within_target === null
                                                ? '—'
                                                : `${a.within_target}%`}
                                        </td>
                                        <td className="p-2">{a.passed_over}</td>
                                        <td className="p-2">{a.waiting_now}</td>
                                        <td className="p-2">
                                            {a.waiting_over_hour}
                                        </td>
                                        <td className="p-2">
                                            {a.handoffs_open}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <h3 className="mt-2 font-medium">By source</h3>
                    <ul className="flex flex-wrap gap-2">
                        {scorecard.sources.map((s) => (
                            <li
                                key={s.key}
                                className="rounded border px-2 py-1"
                            >
                                {sourceLabel(s.key)}: median{' '}
                                {s.median_minutes === null
                                    ? '—'
                                    : `${s.median_minutes} min`}
                                , {s.within_target}% within target
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}
