import { Head, Link, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { channelLabels } from '@/pages/crm/leads/index';

type Source = {
    ulid: string;
    name: string;
    kind: 'api' | 'form' | 'email';
    source: string;
    active: boolean;
    last_lead_at: string | null;
    key_last4: string | null;
    address: string | null;
    form_url: string | null;
    embed: string | null;
    api_url: string | null;
};
type Rule = {
    ulid: string;
    name: string;
    method: string;
    agent_ids: number[];
    sources: string[];
    zips: string[];
    price_min: number | null;
    price_max: number | null;
    intent: string;
    active: boolean;
};
type Props = {
    sources: Source[];
    rules: Rule[];
    settings: {
        respond_minutes: number;
        max_passes: number;
        fallback: string;
        fallback_user_id: number | null;
    };
    agents: { id: number; name: string }[];
    sourceOptions: { value: string; label: string }[];
    channels: string[];
    newKey: { source: string; key: string } | null;
    ai: {
        enabled: boolean;
        nurture_enabled: boolean;
        assistant_name: string;
        tone: string;
        max_messages: number;
        sources: string[] | null;
        knowledge: string;
    };
};

const kindLabels = {
    api: 'Lead API (Zapier, lead ads, your own site)',
    form: 'Website form',
    email: 'Forwarded portal emails (Zillow, Realtor.com, Homes.com…)',
};
const methodLabels: Record<string, string> = {
    agent: 'Always one agent',
    round_robin: 'Round robin',
    first_claim: 'First to claim',
    pond: 'Lead pond',
};

/** Lead sources, routing rules and the response window (owners and staff). */
export default function LeadSettings({
    sources,
    rules,
    settings,
    agents,
    sourceOptions,
    channels,
    newKey,
    ai,
}: Props) {
    const assistant = useForm({ ...ai, sources: ai.sources ?? null });
    const opts = { preserveScroll: true };
    const add = useForm({
        name: '',
        kind: 'form',
        source: 'website',
        heading: '',
        consent_wording: '',
    });
    const s = useForm({
        ...settings,
        fallback_user_id: settings.fallback_user_id ?? '',
    });
    const rule = useForm({
        name: '',
        method: 'round_robin',
        agent_ids: [] as number[],
        sources: [] as string[],
        zips: '',
        price_min: '',
        price_max: '',
        intent: 'any',
    });
    const agentName = (id: number) =>
        agents.find((a) => a.id === id)?.name ?? 'Former agent';
    const move = (i: number, by: number) => {
        const order = rules.map((r) => r.ulid);
        const [r] = order.splice(i, 1);
        order.splice(i + by, 0, r);
        router.post('/leads/rules/order', { order }, opts);
    };
    const error = (errors: Record<string, string>) =>
        Object.values(errors)[0] ? (
            <p role="alert" className="text-destructive">
                {Object.values(errors)[0]}
            </p>
        ) : null;

    return (
        <>
            <Head title="Lead sources and routing" />
            <div className="mx-auto grid w-full max-w-5xl gap-8 p-4 md:p-6">
                <PageHeader
                    title="Lead sources and routing"
                    description="Where leads come in, who gets them, and how fast agents must reach out."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/leads">Back to leads</Link>
                        </Button>
                    }
                />

                <section className="grid gap-3 text-sm">
                    <h2 className="font-semibold">Sources</h2>
                    {newKey && (
                        <div
                            role="status"
                            className="grid gap-1 rounded-lg border border-amber-500 p-3"
                        >
                            <strong>
                                Copy this API key now. It is not shown again.
                            </strong>
                            <code className="break-all">{newKey.key}</code>
                        </div>
                    )}
                    <ul className="grid gap-3">
                        {sources.map((src) => (
                            <li
                                key={src.ulid}
                                className="grid gap-2 rounded-lg border p-3"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">
                                        {src.name}
                                    </span>
                                    <Badge variant="outline">
                                        {kindLabels[src.kind]}
                                    </Badge>
                                    {!src.active && (
                                        <Badge variant="destructive">Off</Badge>
                                    )}
                                    <span className="ml-auto text-xs text-muted-foreground">
                                        {src.last_lead_at
                                            ? `Last lead ${new Date(src.last_lead_at).toLocaleDateString()}`
                                            : 'No leads yet'}
                                    </span>
                                </div>
                                {src.address && (
                                    <p>
                                        Forward lead emails to{' '}
                                        <code className="break-all">
                                            {src.address}
                                        </code>
                                        . In Zillow Premier Agent, Realtor.com
                                        and Homes.com, add it as a lead email
                                        address (or set up a forwarding rule in
                                        your inbox).
                                    </p>
                                )}
                                {src.form_url && (
                                    <>
                                        <p>
                                            Hosted form:{' '}
                                            <a
                                                className="break-all underline"
                                                href={src.form_url}
                                            >
                                                {src.form_url}
                                            </a>
                                        </p>
                                        <details>
                                            <summary>
                                                Embed on your website (plain
                                                HTML: fast, accessible and
                                                readable by search and AI
                                                engines)
                                            </summary>
                                            <textarea
                                                readOnly
                                                aria-label="Embed code"
                                                className="mt-2 h-40 w-full rounded border bg-muted p-2 font-mono text-xs"
                                                value={src.embed ?? ''}
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Put the small script on every
                                                page so a visitor who arrives
                                                from ChatGPT or Google is
                                                credited to it even if they fill
                                                the form later.
                                            </p>
                                        </details>
                                    </>
                                )}
                                {src.api_url && (
                                    <p>
                                        POST JSON to <code>{src.api_url}</code>{' '}
                                        with{' '}
                                        <code>
                                            Authorization: Bearer ra_live_…
                                            {src.key_last4}
                                        </code>
                                        . Send <code>external_id</code> so
                                        retries never create duplicates.
                                    </p>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                `/leads/sources/${src.ulid}/active`,
                                                { active: !src.active },
                                                opts,
                                            )
                                        }
                                    >
                                        {src.active ? 'Turn off' : 'Turn on'}
                                    </Button>
                                    {src.kind === 'api' && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    `/leads/sources/${src.ulid}/key`,
                                                    {},
                                                    opts,
                                                )
                                            }
                                        >
                                            New key
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                    <form
                        className="grid gap-2 rounded-lg border p-3 md:grid-cols-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            add.post('/leads/sources', {
                                ...opts,
                                onSuccess: () => add.reset(),
                            });
                        }}
                    >
                        <h3 className="font-medium md:col-span-2">
                            Add a source
                        </h3>
                        <Input
                            aria-label="Name"
                            placeholder="Name, e.g. Zillow or Website contact form"
                            value={add.data.name}
                            onChange={(e) =>
                                add.setData('name', e.target.value)
                            }
                        />
                        <select
                            aria-label="Type"
                            className="rounded border bg-background px-2 py-1.5"
                            value={add.data.kind}
                            onChange={(e) =>
                                add.setData('kind', e.target.value)
                            }
                        >
                            {Object.entries(kindLabels).map(([k, label]) => (
                                <option key={k} value={k}>
                                    {label}
                                </option>
                            ))}
                        </select>
                        <select
                            aria-label="Where leads come from"
                            className="rounded border bg-background px-2 py-1.5"
                            value={add.data.source}
                            onChange={(e) =>
                                add.setData('source', e.target.value)
                            }
                        >
                            {sourceOptions.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </select>
                        {add.data.kind === 'form' && (
                            <>
                                <Input
                                    aria-label="Form heading"
                                    placeholder="Heading, e.g. Ask us about a home"
                                    value={add.data.heading}
                                    onChange={(e) =>
                                        add.setData('heading', e.target.value)
                                    }
                                />
                                <textarea
                                    aria-label="Text consent wording"
                                    placeholder="Text consent wording (leave empty for the standard TCPA wording)"
                                    className="rounded border bg-background p-2 md:col-span-2"
                                    rows={2}
                                    value={add.data.consent_wording}
                                    onChange={(e) =>
                                        add.setData(
                                            'consent_wording',
                                            e.target.value,
                                        )
                                    }
                                />
                            </>
                        )}
                        <Button
                            className="justify-self-start"
                            disabled={add.processing}
                        >
                            Add source
                        </Button>
                        {error(add.errors)}
                    </form>
                </section>

                <section className="grid gap-3 text-sm">
                    <h2 className="font-semibold">AI first responder</h2>
                    <form
                        className="grid gap-2 rounded-lg border p-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            assistant.post('/leads/ai', opts);
                        }}
                    >
                        <p className="text-muted-foreground">
                            Answers every new lead within a minute, day and
                            night, says it is an AI assistant, learns what they
                            want, and hands them to the agent the moment they
                            ask for a person or are ready. It texts only leads
                            who gave written consent (others get an email) and
                            stops as soon as the agent writes.
                        </p>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={assistant.data.enabled}
                                onChange={(e) =>
                                    assistant.setData(
                                        'enabled',
                                        e.target.checked,
                                    )
                                }
                            />
                            Answer new leads automatically
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={assistant.data.nurture_enabled}
                                onChange={(e) =>
                                    assistant.setData(
                                        'nurture_enabled',
                                        e.target.checked,
                                    )
                                }
                            />
                            Keep in touch with quiet leads for 12 months (new
                            homes for their search, price drops, their home's
                            value, the market). It stops when they reply or you
                            write to them.
                        </label>
                        <div className="flex flex-wrap gap-3">
                            <label className="grid gap-1">
                                Assistant's name
                                <Input
                                    className="w-40"
                                    value={assistant.data.assistant_name}
                                    onChange={(e) =>
                                        assistant.setData(
                                            'assistant_name',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="grid gap-1">
                                Tone
                                <select
                                    className="rounded border bg-background px-2 py-1.5"
                                    value={assistant.data.tone}
                                    onChange={(e) =>
                                        assistant.setData(
                                            'tone',
                                            e.target.value,
                                        )
                                    }
                                >
                                    <option value="friendly">Friendly</option>
                                    <option value="professional">
                                        Professional
                                    </option>
                                </select>
                            </label>
                            <label className="grid gap-1">
                                Most messages per lead
                                <Input
                                    type="number"
                                    min={1}
                                    max={20}
                                    className="w-24"
                                    value={assistant.data.max_messages}
                                    onChange={(e) =>
                                        assistant.setData(
                                            'max_messages',
                                            Number(e.target.value),
                                        )
                                    }
                                />
                            </label>
                        </div>
                        <fieldset className="grid gap-1">
                            <legend className="text-xs text-muted-foreground">
                                Which sources
                            </legend>
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    className="size-4"
                                    checked={assistant.data.sources === null}
                                    onChange={(e) =>
                                        assistant.setData(
                                            'sources',
                                            e.target.checked ? null : [],
                                        )
                                    }
                                />
                                Every source
                            </label>
                            {assistant.data.sources !== null && (
                                <div className="flex flex-wrap gap-3">
                                    {sources.map((src) => (
                                        <label
                                            key={src.ulid}
                                            className="flex items-center gap-1"
                                        >
                                            <input
                                                type="checkbox"
                                                className="size-4"
                                                checked={(
                                                    assistant.data.sources ?? []
                                                ).includes(src.ulid)}
                                                onChange={(e) =>
                                                    assistant.setData(
                                                        'sources',
                                                        e.target.checked
                                                            ? [
                                                                  ...(assistant
                                                                      .data
                                                                      .sources ??
                                                                      []),
                                                                  src.ulid,
                                                              ]
                                                            : (
                                                                  assistant.data
                                                                      .sources ??
                                                                  []
                                                              ).filter(
                                                                  (x) =>
                                                                      x !==
                                                                      src.ulid,
                                                              ),
                                                    )
                                                }
                                            />
                                            {src.name}
                                        </label>
                                    ))}
                                </div>
                            )}
                        </fieldset>
                        <label className="grid gap-1">
                            Facts it may use (office hours, areas you serve, how
                            showings work, your website)
                            <textarea
                                className="rounded border bg-background p-2"
                                rows={4}
                                value={assistant.data.knowledge}
                                onChange={(e) =>
                                    assistant.setData(
                                        'knowledge',
                                        e.target.value,
                                    )
                                }
                            />
                        </label>
                        <Button
                            className="justify-self-start"
                            disabled={assistant.processing}
                        >
                            Save
                        </Button>
                        {error(assistant.errors)}
                    </form>
                </section>

                <section className="grid gap-3 text-sm">
                    <h2 className="font-semibold">Routing rules</h2>
                    <p className="text-muted-foreground">
                        Rules are tried from the top; the first that matches
                        decides. A returning contact always goes to their own
                        agent. Agents who paused new leads are skipped.
                    </p>
                    <ol className="grid gap-2">
                        {rules.map((r, i) => (
                            <li
                                key={r.ulid}
                                className="flex flex-wrap items-center gap-2 rounded-lg border p-3"
                            >
                                <span className="font-medium">
                                    {i + 1}. {r.name}
                                </span>
                                <Badge variant="outline">
                                    {methodLabels[r.method]}
                                </Badge>
                                <span className="text-muted-foreground">
                                    {[
                                        r.sources.length
                                            ? r.sources
                                                  .map(
                                                      (x) =>
                                                          channelLabels[x] ??
                                                          sourceOptions.find(
                                                              (o) =>
                                                                  o.value === x,
                                                          )?.label ??
                                                          x,
                                                  )
                                                  .join(', ')
                                            : 'any source',
                                        r.zips.length
                                            ? `ZIP ${r.zips.join(', ')}`
                                            : null,
                                        r.intent !== 'any' ? r.intent : null,
                                        r.price_min || r.price_max
                                            ? `$${(r.price_min ?? 0).toLocaleString()}–${r.price_max ? `$${r.price_max.toLocaleString()}` : 'any'}`
                                            : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                    {r.agent_ids.length
                                        ? ` → ${r.agent_ids.map(agentName).join(', ')}`
                                        : ''}
                                </span>
                                <span className="ml-auto flex gap-1">
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        aria-label="Move up"
                                        disabled={i === 0}
                                        onClick={() => move(i, -1)}
                                    >
                                        ↑
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        aria-label="Move down"
                                        disabled={i === rules.length - 1}
                                        onClick={() => move(i, 1)}
                                    >
                                        ↓
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            router.delete(
                                                `/leads/rules/${r.ulid}`,
                                                opts,
                                            )
                                        }
                                    >
                                        Delete
                                    </Button>
                                </span>
                            </li>
                        ))}
                    </ol>
                    <form
                        className="grid gap-2 rounded-lg border p-3 md:grid-cols-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            rule.post('/leads/rules', {
                                ...opts,
                                onSuccess: () => rule.reset(),
                            });
                        }}
                    >
                        <h3 className="font-medium md:col-span-2">
                            Add a rule
                        </h3>
                        <Input
                            aria-label="Rule name"
                            placeholder="Name, e.g. Zillow buyers in Austin"
                            value={rule.data.name}
                            onChange={(e) =>
                                rule.setData('name', e.target.value)
                            }
                        />
                        <select
                            aria-label="How leads are given out"
                            className="rounded border bg-background px-2 py-1.5"
                            value={rule.data.method}
                            onChange={(e) =>
                                rule.setData('method', e.target.value)
                            }
                        >
                            {Object.entries(methodLabels).map(([k, label]) => (
                                <option key={k} value={k}>
                                    {label}
                                </option>
                            ))}
                        </select>
                        <fieldset className="grid gap-1 md:col-span-2">
                            <legend className="text-xs text-muted-foreground">
                                Only leads from (leave empty for any)
                            </legend>
                            <div className="flex flex-wrap gap-3">
                                {[
                                    ...sourceOptions.map((o) => [
                                        o.value,
                                        o.label,
                                    ]),
                                    ...channels.map((c) => [
                                        c,
                                        channelLabels[c] ?? c,
                                    ]),
                                ].map(([value, label]) => (
                                    <label
                                        key={value}
                                        className="flex items-center gap-1"
                                    >
                                        <input
                                            type="checkbox"
                                            className="size-4"
                                            checked={rule.data.sources.includes(
                                                value,
                                            )}
                                            onChange={(e) =>
                                                rule.setData(
                                                    'sources',
                                                    e.target.checked
                                                        ? [
                                                              ...rule.data
                                                                  .sources,
                                                              value,
                                                          ]
                                                        : rule.data.sources.filter(
                                                              (x) =>
                                                                  x !== value,
                                                          ),
                                                )
                                            }
                                        />
                                        {label}
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                        <Input
                            aria-label="ZIP codes"
                            placeholder="ZIP codes, comma separated (optional)"
                            value={rule.data.zips}
                            onChange={(e) =>
                                rule.setData('zips', e.target.value)
                            }
                        />
                        <select
                            aria-label="Buying or selling"
                            className="rounded border bg-background px-2 py-1.5"
                            value={rule.data.intent}
                            onChange={(e) =>
                                rule.setData('intent', e.target.value)
                            }
                        >
                            <option value="any">
                                Buying, selling or renting
                            </option>
                            <option value="buy">Buyers</option>
                            <option value="sell">Sellers</option>
                            <option value="rent">Renters</option>
                        </select>
                        <Input
                            aria-label="Lowest price"
                            inputMode="numeric"
                            placeholder="Lowest price (optional)"
                            value={rule.data.price_min}
                            onChange={(e) =>
                                rule.setData('price_min', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Highest price"
                            inputMode="numeric"
                            placeholder="Highest price (optional)"
                            value={rule.data.price_max}
                            onChange={(e) =>
                                rule.setData('price_max', e.target.value)
                            }
                        />
                        {rule.data.method !== 'pond' && (
                            <fieldset className="grid gap-1 md:col-span-2">
                                <legend className="text-xs text-muted-foreground">
                                    Agents
                                </legend>
                                <div className="flex flex-wrap gap-3">
                                    {agents.map((a) => (
                                        <label
                                            key={a.id}
                                            className="flex items-center gap-1"
                                        >
                                            <input
                                                type="checkbox"
                                                className="size-4"
                                                checked={rule.data.agent_ids.includes(
                                                    a.id,
                                                )}
                                                onChange={(e) =>
                                                    rule.setData(
                                                        'agent_ids',
                                                        e.target.checked
                                                            ? [
                                                                  ...rule.data
                                                                      .agent_ids,
                                                                  a.id,
                                                              ]
                                                            : rule.data.agent_ids.filter(
                                                                  (x) =>
                                                                      x !==
                                                                      a.id,
                                                              ),
                                                    )
                                                }
                                            />
                                            {a.name}
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                        )}
                        <Button
                            className="justify-self-start"
                            disabled={rule.processing}
                        >
                            Add rule
                        </Button>
                        {error(rule.errors)}
                    </form>
                </section>

                <section className="grid gap-3 text-sm">
                    <h2 className="font-semibold">Response window</h2>
                    <form
                        className="flex flex-wrap items-end gap-3 rounded-lg border p-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            s.post('/leads/settings', opts);
                        }}
                    >
                        <label className="grid gap-1">
                            Minutes to reach out
                            <Input
                                type="number"
                                min={1}
                                max={240}
                                className="w-24"
                                value={s.data.respond_minutes}
                                onChange={(e) =>
                                    s.setData(
                                        'respond_minutes',
                                        Number(e.target.value),
                                    )
                                }
                            />
                        </label>
                        <label className="grid gap-1">
                            Pass to the next agent up to
                            <Input
                                type="number"
                                min={0}
                                max={10}
                                className="w-24"
                                value={s.data.max_passes}
                                onChange={(e) =>
                                    s.setData(
                                        'max_passes',
                                        Number(e.target.value),
                                    )
                                }
                            />
                        </label>
                        <label className="grid gap-1">
                            Then
                            <select
                                className="rounded border bg-background px-2 py-1.5"
                                value={s.data.fallback}
                                onChange={(e) =>
                                    s.setData('fallback', e.target.value)
                                }
                            >
                                <option value="broker">Give it to</option>
                                <option value="pond">
                                    Send it to the pond
                                </option>
                            </select>
                        </label>
                        {s.data.fallback === 'broker' && (
                            <label className="grid gap-1">
                                Person
                                <select
                                    className="rounded border bg-background px-2 py-1.5"
                                    value={s.data.fallback_user_id}
                                    onChange={(e) =>
                                        s.setData(
                                            'fallback_user_id',
                                            e.target.value,
                                        )
                                    }
                                >
                                    <option value="">The broker (owner)</option>
                                    {agents.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                        <Button disabled={s.processing}>Save</Button>
                        <p className="w-full text-xs text-muted-foreground">
                            The clock only runs between 8am and 9pm, so a lead
                            that arrives overnight waits for the morning instead
                            of bouncing between sleeping agents.
                        </p>
                        {error(s.errors)}
                    </form>
                </section>
            </div>
        </>
    );
}
