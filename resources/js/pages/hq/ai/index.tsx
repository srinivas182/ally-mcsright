import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { PageHeader } from '@/components/ui/page-header';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatCard } from '@/components/ui/stat-card';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';

type Provider = {
    id: number;
    key: string;
    name: string;
    adapter: string;
    status: string;
    zero_retention_confirmed: boolean;
    no_training_confirmed: boolean;
    dpa_signed_at: string | null;
    usable: boolean;
    circuit_open: boolean;
};
type Model = {
    id: number;
    name: string;
    model_id: string;
    provider: string;
    status: string;
    input_cents: number;
    output_cents: number;
};
type Route = {
    id: number;
    feature: string;
    primary_model_id: number;
    fallback_model_ids: number[];
    locked: boolean;
    timeout_seconds: number;
};
type Usage = {
    feature: string;
    model: string | null;
    requests: number;
    failures: number;
    cost_cents: number;
    latency_ms: number;
    blocked: number;
};
type RedTeamRun = {
    ulid: string;
    status: string;
    scenarios: number;
    followed: number;
    critical: number;
    caught: number;
    errors: number;
    cost_cents: number;
    started_at: string;
};
type Props = {
    days: number;
    providers: Provider[];
    models: Model[];
    routes: Route[];
    usage: Usage[];
    redTeam: RedTeamRun[];
};

const dollars = (cents: number) =>
    new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(cents / 100);

function ProviderDialog({
    provider,
    onClose,
}: {
    provider: Provider;
    onClose: () => void;
}) {
    const form = useForm({
        status: provider.status,
        zero_retention_confirmed: provider.zero_retention_confirmed,
        no_training_confirmed: provider.no_training_confirmed,
        dpa_signed_at: provider.dpa_signed_at ?? '',
        confirmation: '',
    });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{provider.name}</DialogTitle>
                    <DialogDescription>
                        Only providers that confirm zero retention and no
                        training on customer data can be active.
                    </DialogDescription>
                </DialogHeader>
                <form
                    id="provider-form"
                    className="grid gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(`/hq/ai/providers/${provider.id}`, {
                            onSuccess: onClose,
                        });
                    }}
                >
                    <label className="flex items-center justify-between gap-4 text-sm">
                        Zero retention confirmed
                        <Switch
                            checked={form.data.zero_retention_confirmed}
                            onCheckedChange={(v) =>
                                form.setData('zero_retention_confirmed', v)
                            }
                        />
                    </label>
                    <label className="flex items-center justify-between gap-4 text-sm">
                        No training on customer data confirmed
                        <Switch
                            checked={form.data.no_training_confirmed}
                            onCheckedChange={(v) =>
                                form.setData('no_training_confirmed', v)
                            }
                        />
                    </label>
                    <FormField label="Status" error={form.errors.status}>
                        {(control) => (
                            <Select
                                value={form.data.status}
                                onValueChange={(v) => form.setData('status', v)}
                            >
                                <SelectTrigger {...control}>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="active">
                                        Active
                                    </SelectItem>
                                    <SelectItem value="disabled">
                                        Disabled
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        )}
                    </FormField>
                    <FormField
                        label="Confirmation note"
                        help="Where the confirmation comes from (contract, DPA, date)."
                        error={form.errors.confirmation}
                        required
                    >
                        {(control) => (
                            <Textarea
                                {...control}
                                value={form.data.confirmation}
                                onChange={(e) =>
                                    form.setData('confirmation', e.target.value)
                                }
                            />
                        )}
                    </FormField>
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        form="provider-form"
                        disabled={form.processing}
                    >
                        Save
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function RouteRow({ route, models }: { route: Route; models: Model[] }) {
    const form = useForm({
        primary_model_id: String(route.primary_model_id),
        fallback_model_ids: route.fallback_model_ids.map(String),
        timeout_seconds: route.timeout_seconds,
    });
    const name = (id: number) =>
        models.find((m) => m.id === id)?.name ?? `#${id}`;
    const active = models.filter((m) => m.status === 'active');

    return (
        <li className="grid gap-3 p-4 md:grid-cols-[1fr_2fr_auto] md:items-center">
            <div>
                <p className="font-mono text-sm">{route.feature}</p>
                <p className="text-xs text-muted-foreground">
                    {route.fallback_model_ids.length > 0
                        ? `Falls back to ${route.fallback_model_ids.map(name).join(', ')}`
                        : 'No fallback'}
                </p>
            </div>
            <Select
                value={form.data.primary_model_id}
                disabled={route.locked}
                onValueChange={(v) => form.setData('primary_model_id', v)}
            >
                <SelectTrigger
                    aria-label={`Primary model for ${route.feature}`}
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {active.map((m) => (
                        <SelectItem key={m.id} value={String(m.id)}>
                            {m.name} ({m.provider})
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <div className="flex gap-2">
                <Button
                    size="sm"
                    disabled={route.locked || form.processing}
                    onClick={() => {
                        form.transform((d) => ({
                            ...d,
                            primary_model_id: Number(d.primary_model_id),
                            fallback_model_ids:
                                d.fallback_model_ids.map(Number),
                        }));
                        form.put(`/hq/ai/routes/${route.id}`, {
                            preserveScroll: true,
                        });
                    }}
                >
                    Save
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        router.post(
                            `/hq/ai/routes/${route.id}/lock`,
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    {route.locked ? 'Unlock' : 'Lock'}
                </Button>
            </div>
            {Object.values(form.errors)[0] && (
                <p
                    role="alert"
                    className="text-sm text-destructive md:col-span-3"
                >
                    {Object.values(form.errors)[0]}
                </p>
            )}
        </li>
    );
}

export default function AiConsole({
    days,
    providers,
    models,
    routes,
    usage,
    redTeam,
}: Props) {
    const [editing, setEditing] = useState<Provider | null>(null);
    const totals = usage.reduce(
        (t, u) => ({
            requests: t.requests + u.requests,
            failures: t.failures + u.failures,
            cost: t.cost + u.cost_cents,
            blocked: t.blocked + u.blocked,
        }),
        { requests: 0, failures: 0, cost: 0, blocked: 0 },
    );
    const latest = redTeam[0];

    return (
        <>
            <Head title="AI gateway" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="AI gateway"
                    description="Providers, models, routes, usage and security across every brokerage."
                    actions={
                        <Select
                            value={String(days)}
                            onValueChange={(v) =>
                                router.get('/hq/ai', { days: v })
                            }
                        >
                            <SelectTrigger aria-label="Period" className="w-36">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="7">Last 7 days</SelectItem>
                                <SelectItem value="30">Last 30 days</SelectItem>
                            </SelectContent>
                        </Select>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="AI requests"
                        value={totals.requests.toLocaleString()}
                    />
                    <StatCard
                        label="Failure rate"
                        value={
                            totals.requests
                                ? `${((totals.failures / totals.requests) * 100).toFixed(1)}%`
                                : '—'
                        }
                    />
                    <StatCard label="Model cost" value={dollars(totals.cost)} />
                    <StatCard
                        label="Answers blocked by output policy"
                        value={String(totals.blocked)}
                    />
                </div>

                <Tabs defaultValue="usage">
                    <TabsList>
                        <TabsTrigger value="usage">Usage</TabsTrigger>
                        <TabsTrigger value="routes">Routes</TabsTrigger>
                        <TabsTrigger value="providers">Providers</TabsTrigger>
                        <TabsTrigger value="models">Models</TabsTrigger>
                        <TabsTrigger value="security">Red team</TabsTrigger>
                    </TabsList>

                    <TabsContent value="usage">
                        {usage.length === 0 ? (
                            <EmptyState title="No AI requests in this period" />
                        ) : (
                            <div className="overflow-x-auto rounded-lg border">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-left">
                                        <tr>
                                            <th className="p-3 font-medium">
                                                Feature
                                            </th>
                                            <th className="p-3 font-medium">
                                                Model
                                            </th>
                                            <th className="p-3 text-right font-medium">
                                                Requests
                                            </th>
                                            <th className="p-3 text-right font-medium">
                                                Failures
                                            </th>
                                            <th className="p-3 text-right font-medium">
                                                Avg latency
                                            </th>
                                            <th className="p-3 text-right font-medium">
                                                Blocked
                                            </th>
                                            <th className="p-3 text-right font-medium">
                                                Cost
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {usage.map((u) => (
                                            <tr
                                                key={`${u.feature}-${u.model}`}
                                                className="border-t"
                                            >
                                                <td className="p-3 font-mono text-xs">
                                                    {u.feature}
                                                </td>
                                                <td className="p-3">
                                                    {u.model ?? '—'}
                                                </td>
                                                <td className="p-3 text-right tabular-nums">
                                                    {u.requests}
                                                </td>
                                                <td className="p-3 text-right tabular-nums">
                                                    {u.failures}
                                                </td>
                                                <td className="p-3 text-right tabular-nums">
                                                    {u.latency_ms} ms
                                                </td>
                                                <td className="p-3 text-right tabular-nums">
                                                    {u.blocked}
                                                </td>
                                                <td className="p-3 text-right tabular-nums">
                                                    {dollars(u.cost_cents)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </TabsContent>

                    <TabsContent value="routes">
                        <ul className="divide-y rounded-lg border">
                            {routes.map((r) => (
                                <RouteRow
                                    key={r.id}
                                    route={r}
                                    models={models}
                                />
                            ))}
                        </ul>
                    </TabsContent>

                    <TabsContent value="providers">
                        <ul className="divide-y rounded-lg border">
                            {providers.map((p) => (
                                <li
                                    key={p.id}
                                    className="flex flex-wrap items-center justify-between gap-3 p-4"
                                >
                                    <div>
                                        <p className="font-medium">{p.name}</p>
                                        <p className="text-sm text-muted-foreground">
                                            {p.adapter} · zero retention{' '}
                                            {p.zero_retention_confirmed
                                                ? 'confirmed'
                                                : 'not confirmed'}{' '}
                                            · no training{' '}
                                            {p.no_training_confirmed
                                                ? 'confirmed'
                                                : 'not confirmed'}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {p.circuit_open && (
                                            <Badge variant="destructive">
                                                Paused after failures
                                            </Badge>
                                        )}
                                        <Badge
                                            variant={
                                                p.usable
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {p.usable ? 'In use' : 'Not usable'}
                                        </Badge>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setEditing(p)}
                                        >
                                            Edit
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </TabsContent>

                    <TabsContent value="models">
                        <ul className="divide-y rounded-lg border">
                            {models.map((m) => (
                                <li
                                    key={m.id}
                                    className="flex flex-wrap items-center justify-between gap-3 p-4 text-sm"
                                >
                                    <span>
                                        <span className="font-medium">
                                            {m.name}
                                        </span>{' '}
                                        <span className="text-muted-foreground">
                                            {m.provider} · {m.model_id}
                                        </span>
                                    </span>
                                    <span className="text-muted-foreground tabular-nums">
                                        {dollars(m.input_cents)} in /{' '}
                                        {dollars(m.output_cents)} out per
                                        million tokens
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </TabsContent>

                    <TabsContent value="security" className="grid gap-4">
                        {latest ? (
                            <p className="text-sm">
                                Latest run:{' '}
                                <Badge
                                    variant={
                                        latest.status === 'failed'
                                            ? 'destructive'
                                            : 'default'
                                    }
                                    className="capitalize"
                                >
                                    {latest.status.replace('_', ' ')}
                                </Badge>{' '}
                                {latest.critical} critical and {latest.followed}{' '}
                                total injections followed, {latest.caught}{' '}
                                answers caught by the output policy,{' '}
                                {latest.scenarios} scenarios,{' '}
                                {dollars(latest.cost_cents)}.
                            </p>
                        ) : (
                            <EmptyState
                                title="No red-team runs yet"
                                description="The run starts every Monday at 05:00 once AI providers are connected."
                            />
                        )}
                        {redTeam.length > 1 && (
                            <ul className="divide-y rounded-lg border text-sm">
                                {redTeam.slice(1).map((run) => (
                                    <li
                                        key={run.ulid}
                                        className="flex justify-between p-3"
                                    >
                                        <span>
                                            {new Date(
                                                run.started_at,
                                            ).toLocaleDateString()}
                                        </span>
                                        <span className="capitalize">
                                            {run.status.replace('_', ' ')} ·{' '}
                                            {run.critical} critical
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </TabsContent>
                </Tabs>
            </div>
            {editing && (
                <ProviderDialog
                    provider={editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

AiConsole.layout = {
    breadcrumbs: [{ title: 'AI gateway', href: '/hq/ai' }],
};
