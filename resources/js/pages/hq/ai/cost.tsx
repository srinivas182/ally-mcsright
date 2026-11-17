import { Head, Link, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { StatCard } from '@/components/ui/stat-card';
import { usd } from '@/lib/cents';

type Summary = {
    requests: number;
    cost_cents: number;
    reused: number;
    saved_cents: number;
    light: number;
    prior_cost_cents: number;
};
type Brokerage = {
    tenant: string;
    requests: number;
    cost_cents: number;
    per_request_cents: number;
};
type Feature = {
    feature: string;
    requests: number;
    cost_cents: number;
    per_request_cents: number;
    reused_pct: number;
    failures: number;
};
type Day = { day: string; cost_cents: number; requests: number };
type Route = {
    id: number;
    feature: string;
    locked: boolean;
    light_model_id: number | null;
    light_max_chars: number;
    cache_minutes: number;
};
type Model = {
    id: number;
    name: string;
    input_cents: number;
    output_cents: number;
};

type Props = {
    days: number;
    summary: Summary;
    brokerages: Brokerage[];
    features: Feature[];
    daily: Day[];
    routes: Route[];
    models: Model[];
};

/** AQ2: what AI costs RightAlly, by brokerage and feature, and the settings that keep it down. */
export default function AiCost({
    days,
    summary,
    brokerages,
    features,
    daily,
    routes,
    models,
}: Props) {
    const max = Math.max(1, ...daily.map((d) => d.cost_cents));
    const change =
        summary.prior_cost_cents > 0
            ? Math.round(
                  (100 * (summary.cost_cents - summary.prior_cost_cents)) /
                      summary.prior_cost_cents,
              )
            : null;

    return (
        <>
            <Head title="AI cost" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="AI cost"
                    description="What AI costs RightAlly, from the request log. No one's questions or answers are stored, so nothing here shows what anyone asked."
                    actions={
                        <div className="flex gap-2">
                            {[7, 30, 90].map((d) => (
                                <Button
                                    key={d}
                                    asChild
                                    variant={d === days ? 'default' : 'outline'}
                                >
                                    <Link href={`/hq/ai/cost?days=${d}`}>
                                        {d} days
                                    </Link>
                                </Button>
                            ))}
                        </div>
                    }
                />
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label={`Cost, last ${days} days`}
                        value={usd(summary.cost_cents)}
                        change={change ?? undefined}
                        comparison={`vs the ${days} days before`}
                        higherIsBetter={false}
                    />
                    <StatCard
                        label="AI answers"
                        value={summary.requests.toLocaleString()}
                    />
                    <StatCard
                        label={`Answers reused (saved about ${usd(summary.saved_cents)})`}
                        value={summary.reused.toLocaleString()}
                    />
                    <StatCard
                        label="Answered by a lighter model"
                        value={summary.light.toLocaleString()}
                    />
                </div>

                <section aria-labelledby="daily" className="grid gap-2">
                    <h2 id="daily" className="text-lg font-semibold">
                        Last 30 days
                    </h2>
                    <div
                        className="flex h-32 items-end gap-1"
                        role="img"
                        aria-label={`Daily AI cost, highest ${usd(max)}`}
                    >
                        {daily.map((d) => (
                            <div
                                key={d.day}
                                title={`${d.day}: ${usd(d.cost_cents)}, ${d.requests} answers`}
                                className="flex-1 rounded-t bg-primary/70"
                                style={{
                                    height: `${Math.max(2, (100 * d.cost_cents) / max)}%`,
                                }}
                            />
                        ))}
                    </div>
                </section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <CostTable
                        title="By brokerage"
                        head={['Brokerage', 'Answers', 'Cost', 'Per answer']}
                        rows={brokerages.map((b) => [
                            b.tenant,
                            b.requests.toLocaleString(),
                            usd(b.cost_cents),
                            usd(Math.round(b.per_request_cents)),
                        ])}
                    />
                    <CostTable
                        title="By feature"
                        head={[
                            'Feature',
                            'Answers',
                            'Cost',
                            'Per answer',
                            'Reused',
                        ]}
                        rows={features.map((f) => [
                            f.feature,
                            f.requests.toLocaleString(),
                            usd(f.cost_cents),
                            usd(Math.round(f.per_request_cents)),
                            `${f.reused_pct}%`,
                        ])}
                    />
                </div>

                <section aria-labelledby="settings" className="grid gap-3">
                    <h2 id="settings" className="text-lg font-semibold">
                        Cost settings by feature
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        A lighter model answers short requests first (it falls
                        back to the usual model). Reusing answers gives the same
                        answer to an identical request within the time you set,
                        free to the brokerage. Use it only for features where
                        the same question should always get the same answer.
                    </p>
                    <ul className="grid gap-3">
                        {routes.map((r) => (
                            <RouteSettings
                                key={r.id}
                                route={r}
                                models={models}
                            />
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}

function CostTable({
    title,
    head,
    rows,
}: {
    title: string;
    head: string[];
    rows: string[][];
}) {
    return (
        <section className="grid gap-2">
            <h2 className="text-lg font-semibold">{title}</h2>
            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            {head.map((h) => (
                                <th key={h} className="p-2 font-medium">
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 && (
                            <tr>
                                <td
                                    colSpan={head.length}
                                    className="p-4 text-center text-muted-foreground"
                                >
                                    No AI use yet.
                                </td>
                            </tr>
                        )}
                        {rows.map((r, i) => (
                            <tr key={i} className="border-t">
                                {r.map((c, j) => (
                                    <td key={j} className="p-2">
                                        {c}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

function RouteSettings({ route, models }: { route: Route; models: Model[] }) {
    const form = useForm({
        light_model_id: route.light_model_id,
        light_max_chars: route.light_max_chars,
        cache_minutes: route.cache_minutes,
    });

    return (
        <li className="rounded-lg border p-3">
            <form
                className="flex flex-wrap items-end gap-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.put(`/hq/ai/cost/routes/${route.id}`, {
                        preserveScroll: true,
                    });
                }}
            >
                <p className="min-w-48 font-medium">{route.feature}</p>
                <label className="grid gap-1 text-sm">
                    Lighter model
                    <select
                        className="h-9 rounded-md border bg-background px-2"
                        value={form.data.light_model_id ?? ''}
                        disabled={route.locked}
                        onChange={(e) =>
                            form.setData(
                                'light_model_id',
                                e.target.value === ''
                                    ? null
                                    : Number(e.target.value),
                            )
                        }
                    >
                        <option value="">None</option>
                        {models.map((m) => (
                            <option key={m.id} value={m.id}>
                                {m.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="grid gap-1 text-sm">
                    For requests up to (characters)
                    <Input
                        type="number"
                        min={0}
                        className="w-36"
                        value={form.data.light_max_chars}
                        disabled={route.locked}
                        onChange={(e) =>
                            form.setData(
                                'light_max_chars',
                                Number(e.target.value),
                            )
                        }
                    />
                </label>
                <label className="grid gap-1 text-sm">
                    Reuse answers for (minutes)
                    <Input
                        type="number"
                        min={0}
                        max={1440}
                        className="w-28"
                        value={form.data.cache_minutes}
                        disabled={route.locked}
                        onChange={(e) =>
                            form.setData(
                                'cache_minutes',
                                Number(e.target.value),
                            )
                        }
                    />
                </label>
                <Button
                    type="submit"
                    disabled={route.locked || form.processing}
                >
                    Save
                </Button>
                {route.locked && (
                    <span className="text-sm text-muted-foreground">
                        Locked in the AI gateway
                    </span>
                )}
                {Object.values(form.errors)[0] && (
                    <span role="alert" className="text-sm text-destructive">
                        {Object.values(form.errors)[0]}
                    </span>
                )}
            </form>
        </li>
    );
}

AiCost.layout = {
    breadcrumbs: [{ title: 'AI cost', href: '/hq/ai/cost' }],
};
