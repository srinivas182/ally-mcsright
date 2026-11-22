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
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatCard } from '@/components/ui/stat-card';
import { OutLink, SetupGuide } from '@/components/setup-guide';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';

type Provider = {
    id: number;
    key: string;
    name: string;
    adapter: string;
    status: string;
    retention: 'zero' | 'standard' | 'unconfirmed';
    no_training_confirmed: boolean;
    dpa_signed_at: string | null;
    usable: boolean;
    circuit_open: boolean;
    key_source: 'hq' | 'server' | 'none';
    key_hint: string | null;
    key_saved_at: string | null;
    key_checked_at: string | null;
};

/** Where to get each provider's key, in plain words. */
const KEY_HELP: Record<string, string> = {
    anthropic: 'console.anthropic.com → API keys → Create key',
    openai: 'platform.openai.com → API keys → Create new secret key',
    gemini: 'aistudio.google.com → Get API key',
};

/** AI1: saves a provider's API key. The key is checked with the provider, locked away and never shown again. */
function KeyDialog({
    provider,
    onClose,
}: {
    provider: Provider;
    onClose: () => void;
}) {
    const form = useForm({ api_key: '' });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{provider.name} API key</DialogTitle>
                    <DialogDescription>
                        Paste the key from{' '}
                        {KEY_HELP[provider.key] ?? 'the provider'}. RightAlly
                        checks it with {provider.name}, then saves it locked
                        away. Nobody can see it again, including you.
                    </DialogDescription>
                </DialogHeader>
                <form
                    id="key-form"
                    className="grid gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/hq/ai/providers/${provider.id}/key`, {
                            preserveScroll: true,
                            onSuccess: onClose,
                            onFinish: () => form.reset('api_key'),
                        });
                    }}
                >
                    <FormField
                        label="API key"
                        help={
                            provider.key_source === 'hq'
                                ? `A key ending in ${provider.key_hint} is saved. Saving a new one replaces it.`
                                : undefined
                        }
                        error={form.errors.api_key}
                        required
                    >
                        {(control) => (
                            <Input
                                {...control}
                                type="password"
                                autoComplete="off"
                                spellCheck={false}
                                value={form.data.api_key}
                                onChange={(e) =>
                                    form.setData('api_key', e.target.value)
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
                        form="key-form"
                        disabled={form.processing || form.data.api_key === ''}
                    >
                        {form.processing ? 'Checking…' : 'Check and save'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
type Model = {
    id: number;
    name: string;
    model_id: string;
    provider: string;
    provider_id: number;
    status: string;
    capabilities: string[];
    input_cents: number;
    output_cents: number;
    cache_read_cents: number;
    audio_per_minute_microcents: number;
    prices_checked_at: string | null;
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
type ModelPreset = {
    model_id: string;
    display_name: string;
    note: string;
    capabilities: string[];
    input: number;
    output: number;
    cache_read: number | null;
    audio: number | null;
};
type Props = {
    days: number;
    presets: { key: string; name: string }[];
    capabilities: string[];
    modelPresets: Record<string, ModelPreset[]>;
    unrouted: number;
    modelPresetsChecked: string;
    providers: Provider[];
    models: Model[];
    routes: Route[];
    usage: Usage[];
    redTeam: RedTeamRun[];
};

/** HQ3: a link in the setup guide that opens a tab of this page. */
function TabLink({ to, go }: { to: string; go: (tab: string) => void }) {
    return (
        <button
            type="button"
            className="font-medium text-primary underline underline-offset-2"
            onClick={() => go(to)}
        >
            {to.charAt(0).toUpperCase() + to.slice(1)}
        </button>
    );
}

/** HQ3: creates the missing routes with the main model HQ picks (the most capable chat model is suggested). */
function CreateRoutes({ models }: { models: Model[] }) {
    const chat = models
        .filter((m) => m.status === 'active' && m.capabilities.includes('chat'))
        .sort((a, b) => b.output_cents - a.output_cents);
    const form = useForm({ main_model_id: chat[0]?.id ?? 0 });

    if (chat.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                Add an active chat model first (step 4).
            </p>
        );
    }

    return (
        <form
            className="flex flex-wrap items-center gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/hq/ai/routes/sync', { preserveScroll: true });
            }}
        >
            <label className="flex items-center gap-2 text-sm text-foreground">
                Main model
                <select
                    className="h-9 rounded-md border bg-background px-2"
                    value={form.data.main_model_id}
                    onChange={(e) =>
                        form.setData(
                            'main_model_id',
                            parseInt(e.target.value, 10),
                        )
                    }
                >
                    {chat.map((m) => (
                        <option key={m.id} value={m.id}>
                            {m.name}
                        </option>
                    ))}
                </select>
            </label>
            <Button type="submit" size="sm" disabled={form.processing}>
                {form.processing ? 'Creating…' : 'Create routes'}
            </Button>
            {form.errors.main_model_id && (
                <p className="text-sm text-destructive">
                    {form.errors.main_model_id}
                </p>
            )}
        </form>
    );
}

/** Add a model or change its prices (US dollars per million tokens, or per audio minute for speech to text). */
function ModelDialog({
    model,
    providers,
    capabilities,
    presets,
    presetsChecked,
    existing,
    onClose,
}: {
    model: Model | null;
    providers: Provider[];
    capabilities: string[];
    presets: Record<string, ModelPreset[]>;
    presetsChecked: string;
    existing: Model[];
    onClose: () => void;
}) {
    const form = useForm({
        provider_id: model?.provider_id ?? providers[0]?.id ?? 0,
        model_id: model?.model_id ?? '',
        display_name: model?.name ?? '',
        capabilities: model?.capabilities ?? ['chat', 'json'],
        input_per_mtok: model ? String(model.input_cents / 100) : '',
        output_per_mtok: model ? String(model.output_cents / 100) : '',
        cache_read_per_mtok: model ? String(model.cache_read_cents / 100) : '',
        audio_per_minute: model
            ? String(model.audio_per_minute_microcents / 100_000_000)
            : '',
        status: model?.status ?? 'active',
    });
    // AI2: the chosen provider's main models, not yet added, to fill the form in one click.
    const providerKey =
        providers.find((p) => p.id === form.data.provider_id)?.key ?? '';
    const choices = (presets[providerKey] ?? []).filter(
        (c) =>
            !existing.some(
                (m) =>
                    m.provider_id === form.data.provider_id &&
                    m.model_id === c.model_id,
            ),
    );
    const usePreset = (id: string) => {
        const c = choices.find((x) => x.model_id === id);
        if (!c) {
            return;
        }
        form.setData((d) => ({
            ...d,
            model_id: c.model_id,
            display_name: c.display_name,
            capabilities: c.capabilities,
            input_per_mtok: String(c.input),
            output_per_mtok: String(c.output),
            cache_read_per_mtok:
                c.cache_read === null ? '' : String(c.cache_read),
            audio_per_minute: c.audio === null ? '' : String(c.audio),
        }));
    };
    const submit = () => {
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (model) {
            form.transform((d) => {
                const { provider_id: _p, model_id: _m, ...rest } = d;
                return rest;
            });
            form.put(`/hq/ai/models/${model.id}`, opts);
        } else {
            form.post('/hq/ai/models', opts);
        }
    };
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {model ? `Prices for ${model.name}` : 'Add a model'}
                    </DialogTitle>
                    <DialogDescription>
                        Copy prices from the provider's pricing page. Credits
                        are planned from these prices, so keep them current.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-3 text-sm">
                    {!model && (
                        <>
                            <FormField
                                label="Provider"
                                error={form.errors.provider_id}
                            >
                                {(control) => (
                                    <select
                                        {...control}
                                        className="h-9 rounded-md border bg-background px-2"
                                        value={form.data.provider_id}
                                        onChange={(e) =>
                                            form.setData(
                                                'provider_id',
                                                parseInt(e.target.value, 10),
                                            )
                                        }
                                    >
                                        {providers.map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {p.name}
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </FormField>
                            {choices.length > 0 && (
                                <FormField
                                    label="Choose a model"
                                    help={`Fills in the details below. Prices are from the provider's pricing page on ${presetsChecked}: check them before saving.`}
                                >
                                    {(control) => (
                                        <select
                                            {...control}
                                            className="h-9 rounded-md border bg-background px-2"
                                            defaultValue=""
                                            onChange={(e) =>
                                                usePreset(e.target.value)
                                            }
                                        >
                                            <option value="">
                                                Pick one, or type your own below
                                            </option>
                                            {choices.map((c) => (
                                                <option
                                                    key={c.model_id}
                                                    value={c.model_id}
                                                >
                                                    {c.display_name}: {c.note}
                                                </option>
                                            ))}
                                        </select>
                                    )}
                                </FormField>
                            )}
                            <FormField
                                label="Model ID (exactly as the provider names it)"
                                error={form.errors.model_id}
                            >
                                {(control) => (
                                    <Input
                                        {...control}
                                        value={form.data.model_id}
                                        onChange={(e) =>
                                            form.setData(
                                                'model_id',
                                                e.target.value,
                                            )
                                        }
                                    />
                                )}
                            </FormField>
                        </>
                    )}
                    <FormField
                        label="Display name"
                        error={form.errors.display_name}
                    >
                        {(control) => (
                            <Input
                                {...control}
                                value={form.data.display_name}
                                onChange={(e) =>
                                    form.setData('display_name', e.target.value)
                                }
                            />
                        )}
                    </FormField>
                    <fieldset className="flex flex-wrap gap-3">
                        <legend className="mb-1 font-medium">Can do</legend>
                        {capabilities.map((c) => (
                            <label key={c} className="flex items-center gap-1">
                                <input
                                    type="checkbox"
                                    checked={form.data.capabilities.includes(c)}
                                    onChange={(e) =>
                                        form.setData(
                                            'capabilities',
                                            e.target.checked
                                                ? [...form.data.capabilities, c]
                                                : form.data.capabilities.filter(
                                                      (x) => x !== c,
                                                  ),
                                        )
                                    }
                                />
                                {c}
                            </label>
                        ))}
                    </fieldset>
                    <div className="grid grid-cols-3 gap-2">
                        <FormField
                            label="Input $/1M tokens"
                            error={form.errors.input_per_mtok}
                        >
                            {(control) => (
                                <Input
                                    {...control}
                                    type="number"
                                    step="0.01"
                                    value={form.data.input_per_mtok}
                                    onChange={(e) =>
                                        form.setData(
                                            'input_per_mtok',
                                            e.target.value,
                                        )
                                    }
                                />
                            )}
                        </FormField>
                        <FormField
                            label="Output $/1M tokens"
                            error={form.errors.output_per_mtok}
                        >
                            {(control) => (
                                <Input
                                    {...control}
                                    type="number"
                                    step="0.01"
                                    value={form.data.output_per_mtok}
                                    onChange={(e) =>
                                        form.setData(
                                            'output_per_mtok',
                                            e.target.value,
                                        )
                                    }
                                />
                            )}
                        </FormField>
                        <FormField
                            label="Cached input $/1M"
                            error={form.errors.cache_read_per_mtok}
                        >
                            {(control) => (
                                <Input
                                    {...control}
                                    type="number"
                                    step="0.01"
                                    value={form.data.cache_read_per_mtok}
                                    onChange={(e) =>
                                        form.setData(
                                            'cache_read_per_mtok',
                                            e.target.value,
                                        )
                                    }
                                />
                            )}
                        </FormField>
                    </div>
                    <FormField
                        label="Speech to text: $ per audio minute (leave empty for token-priced models)"
                        error={form.errors.audio_per_minute}
                    >
                        {(control) => (
                            <Input
                                {...control}
                                type="number"
                                step="0.0001"
                                value={form.data.audio_per_minute}
                                onChange={(e) =>
                                    form.setData(
                                        'audio_per_minute',
                                        e.target.value,
                                    )
                                }
                            />
                        )}
                    </FormField>
                    <label className="flex items-center gap-2">
                        <Switch
                            checked={form.data.status === 'active'}
                            onCheckedChange={(on) =>
                                form.setData(
                                    'status',
                                    on ? 'active' : 'disabled',
                                )
                            }
                        />
                        Active
                    </label>
                    {form.errors.status && (
                        <p className="text-destructive">{form.errors.status}</p>
                    )}
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={form.processing}>
                        Save
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

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
        retention: provider.retention,
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
                        A provider can be active only once it never trains on
                        customer data and you have chosen what it keeps.
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
                    <FormField
                        label="What the provider keeps"
                        help="Standard: the provider keeps requests for a short time (about 30 days for Anthropic) to check for abuse, then deletes them. Zero: you have a signed zero-retention agreement."
                        error={form.errors.retention}
                    >
                        {(control) => (
                            <Select
                                value={form.data.retention}
                                onValueChange={(v) =>
                                    form.setData(
                                        'retention',
                                        v as Provider['retention'],
                                    )
                                }
                            >
                                <SelectTrigger {...control}>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="unconfirmed">
                                        Not checked yet
                                    </SelectItem>
                                    <SelectItem value="standard">
                                        Standard retention (a short time, for
                                        abuse checks)
                                    </SelectItem>
                                    <SelectItem value="zero">
                                        Zero retention (signed agreement)
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        )}
                    </FormField>
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
    presets,
    capabilities,
    modelPresets,
    modelPresetsChecked,
    unrouted,
    providers,
    models,
    routes,
    usage,
    redTeam,
}: Props) {
    const [editing, setEditing] = useState<Provider | null>(null);
    const [keyEditing, setKeyEditing] = useState<Provider | null>(null);
    const [modelEditing, setModelEditing] = useState<Model | 'new' | null>(
        null,
    );
    const [preset, setPreset] = useState('');
    const [tab, setTab] = useState(
        providers.some((p) => p.usable) && routes.length > 0
            ? 'usage'
            : 'providers',
    );
    const missingPresets = presets.filter(
        (p) => !providers.some((x) => x.key === p.key),
    );
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

                <SetupGuide
                    title="How to set up AI"
                    intro="Do these in order, once. A provider is the AI company (Anthropic makes Claude); a model is one of its AIs; a route says which model each RightAlly feature uses."
                    steps={[
                        {
                            title: 'Add a provider',
                            done: providers.length > 0,
                            body: (
                                <p>
                                    Open the{' '}
                                    <TabLink to="providers" go={setTab} /> tab,
                                    choose <strong>Anthropic (Claude)</strong>{' '}
                                    and click Add. Add OpenAI too only if you
                                    want voice notes and smarter search.
                                </p>
                            ),
                        },
                        {
                            title: "Get the provider's API key and save it",
                            done: providers.some(
                                (p) => p.key_source !== 'none',
                            ),
                            body: (
                                <>
                                    <p>
                                        Sign in at{' '}
                                        <OutLink href="https://console.anthropic.com/settings/keys">
                                            console.anthropic.com › API keys
                                        </OutLink>
                                        , click <strong>Create key</strong>, and
                                        copy it. Add a payment card and some
                                        credit under Billing there, or the key
                                        won't work. For OpenAI:{' '}
                                        <OutLink href="https://platform.openai.com/api-keys">
                                            platform.openai.com › API keys
                                        </OutLink>
                                        .
                                    </p>
                                    <p>
                                        Back here, on the provider click{' '}
                                        <strong>API key</strong>, paste it and
                                        click Check and save.
                                    </p>
                                </>
                            ),
                        },
                        {
                            title: 'Confirm its data terms and switch it on',
                            done: providers.some((p) => p.usable),
                            body: (
                                <p>
                                    On the provider click <strong>Edit</strong>.
                                    Choose what it keeps (Standard for
                                    Anthropic's normal terms, or Zero if you
                                    have a signed agreement), confirm no
                                    training, write where this comes from (for
                                    example "Anthropic commercial terms, checked
                                    today"), set Status to Active and save.
                                </p>
                            ),
                        },
                        {
                            title: 'Add models',
                            done: models.some(
                                (m) =>
                                    m.status === 'active' &&
                                    m.capabilities.includes('chat'),
                            ),
                            body: (
                                <p>
                                    Open <TabLink to="models" go={setTab} /> and
                                    click <strong>Add a model</strong>. Pick the
                                    provider, then{' '}
                                    <strong>Choose a model</strong>: Claude
                                    Sonnet 5.5 is the main one. Check the prices
                                    and save. Add Claude Haiku 5.5 too if you
                                    like, as a low-cost backup.
                                </p>
                            ),
                        },
                        {
                            title: 'Create the routes',
                            done: routes.length > 0,
                            body: (
                                <>
                                    <p>
                                        Pick the model most features should use
                                        and click Create routes. You can change
                                        any feature's model later on{' '}
                                        <TabLink to="routes" go={setTab} />.
                                    </p>
                                    <CreateRoutes models={models} />
                                </>
                            ),
                        },
                    ]}
                />
                <Tabs value={tab} onValueChange={setTab}>
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

                    <TabsContent value="routes" className="grid gap-3">
                        {unrouted > 0 && (
                            <div className="grid gap-2 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-700 dark:bg-amber-950/40">
                                <p>
                                    {routes.length === 0
                                        ? 'No routes yet, so AI features are off. '
                                        : `${unrouted} AI feature(s) have no model yet. `}
                                    Pick the main model and create the missing
                                    routes. Routes you already have are not
                                    changed.
                                </p>
                                <CreateRoutes models={models} />
                            </div>
                        )}
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

                    <TabsContent value="providers" className="grid gap-3">
                        {missingPresets.length > 0 && (
                            <div className="flex flex-wrap items-center gap-2 text-sm">
                                <select
                                    aria-label="Provider to add"
                                    className="h-9 rounded-md border bg-background px-2"
                                    value={preset}
                                    onChange={(e) => setPreset(e.target.value)}
                                >
                                    <option value="">Add a provider…</option>
                                    {missingPresets.map((p) => (
                                        <option key={p.key} value={p.key}>
                                            {p.name}
                                        </option>
                                    ))}
                                </select>
                                <Button
                                    size="sm"
                                    disabled={!preset}
                                    onClick={() =>
                                        router.post(
                                            '/hq/ai/providers',
                                            { preset },
                                            {
                                                preserveScroll: true,
                                                onSuccess: () => setPreset(''),
                                            },
                                        )
                                    }
                                >
                                    Add
                                </Button>
                                <span className="text-muted-foreground">
                                    Then paste its API key with the API key
                                    button.
                                </span>
                            </div>
                        )}
                        <ul className="divide-y rounded-lg border">
                            {providers.map((p) => (
                                <li
                                    key={p.id}
                                    className="flex flex-wrap items-center justify-between gap-3 p-4"
                                >
                                    <div>
                                        <p className="font-medium">{p.name}</p>
                                        <p className="text-sm text-muted-foreground">
                                            {p.adapter} · retention{' '}
                                            {p.retention === 'zero'
                                                ? 'zero'
                                                : p.retention === 'standard'
                                                  ? 'standard (accepted)'
                                                  : 'not checked'}{' '}
                                            · no training{' '}
                                            {p.no_training_confirmed
                                                ? 'confirmed'
                                                : 'not confirmed'}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {p.key_source === 'hq'
                                                ? `Key saved (ends ${p.key_hint})${p.key_checked_at ? `, last checked ${p.key_checked_at}` : ''}`
                                                : p.key_source === 'server'
                                                  ? 'Key from the server settings file. Paste it here to manage it from HQ.'
                                                  : 'No key yet'}
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
                                            variant={
                                                p.key_source === 'none'
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            onClick={() => setKeyEditing(p)}
                                        >
                                            API key
                                        </Button>
                                        {p.key_source !== 'none' && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        `/hq/ai/providers/${p.id}/key/check`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Check connection
                                            </Button>
                                        )}
                                        {p.key_source === 'hq' && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    router.delete(
                                                        `/hq/ai/providers/${p.id}/key`,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Remove key
                                            </Button>
                                        )}
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

                    <TabsContent value="models" className="grid gap-3">
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <Button
                                size="sm"
                                disabled={providers.length === 0}
                                onClick={() => setModelEditing('new')}
                            >
                                Add a model
                            </Button>
                            <span className="text-muted-foreground">
                                Credits are priced from these prices (HQ →
                                RightAlly credits).
                            </span>
                        </div>
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
                                    <span className="flex items-center gap-2 text-muted-foreground tabular-nums">
                                        {m.audio_per_minute_microcents > 0
                                            ? `$${(m.audio_per_minute_microcents / 100_000_000).toFixed(4)} per audio minute`
                                            : `${dollars(m.input_cents)} in / ${dollars(m.output_cents)} out per million tokens`}
                                        {m.prices_checked_at
                                            ? ` · checked ${m.prices_checked_at}`
                                            : ' · prices never checked'}
                                        {m.status !== 'active' && (
                                            <Badge variant="secondary">
                                                Off
                                            </Badge>
                                        )}
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setModelEditing(m)}
                                        >
                                            Edit
                                        </Button>
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
            {modelEditing && (
                <ModelDialog
                    model={modelEditing === 'new' ? null : modelEditing}
                    providers={providers}
                    capabilities={capabilities}
                    presets={modelPresets}
                    presetsChecked={modelPresetsChecked}
                    existing={models}
                    onClose={() => setModelEditing(null)}
                />
            )}
            {keyEditing && (
                <KeyDialog
                    provider={keyEditing}
                    onClose={() => setKeyEditing(null)}
                />
            )}
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
