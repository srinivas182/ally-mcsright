import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

import {
    AiAssistantsPanel,
    type AiGuide,
    type McpPanel,
} from '@/components/connect/ai-assistants';
import { Monogram, type AppEntry } from '@/components/connect/monogram';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Field = {
    key: string;
    label: string;
    type: string;
    secret: boolean;
    help: string | null;
};
type App = Omit<AppEntry, 'connected' | 'problem'> & {
    link: string | null;
    multiple: boolean;
    fields: Field[];
    guide?: AiGuide | null;
};
type Run = {
    status: string;
    created: number;
    updated: number;
    skipped: number;
    note: string | null;
    at: string;
};
type Delivery = {
    ulid: string;
    event: string;
    status: string;
    attempts: number;
    code: number | null;
    error: string | null;
    at: string;
};
type ConnectionRow = {
    ulid: string;
    label: string | null;
    status: 'connected' | 'error' | 'needs_reconnect' | 'paused';
    mine: boolean;
    last_synced_at: string | null;
    last_error: string | null;
    created_at: string;
    url: string | null;
    events: string[];
    runs: Run[];
    deliveries: Delivery[];
};
type Help = {
    emails?: { name: string; address: string }[];
    api_sources?: { name: string; last4: string }[];
    api_url?: string;
    settings_url?: string;
};

const statusLabel: Record<ConnectionRow['status'], string> = {
    connected: 'Connected',
    error: 'Not working',
    needs_reconnect: 'Connect again',
    paused: 'Paused',
};
const scopeLabel = {
    brokerage: 'Connected by owners and staff for the whole brokerage',
    agent: 'Each person connects their own',
    both: 'For the whole brokerage, or each agent their own',
};
const when = (iso: string | null) =>
    iso ? new Date(iso).toLocaleString() : 'not yet';

/** One app: what it does, connect it, and how it is doing (specs/connect/01). */
export default function AppScreen({
    app,
    connections,
    events,
    secret,
    help,
    mcp,
}: {
    app: App;
    connections: ConnectionRow[];
    events: Record<string, string>;
    secret: { connection: string; secret: string } | null;
    help: Help;
    mcp: McpPanel | null;
}) {
    const initial: Record<string, string | string[]> = {};
    app.fields.forEach((f) => {
        initial[f.key] = f.type === 'events' ? [] : '';
    });
    const form = useForm<Record<string, string | string[]>>(initial);
    const upload = useForm<{ file: File | null }>({ file: null });
    const [copied, setCopied] = useState(false);
    const errors = usePage().props.errors as Record<string, string>;

    return (
        <>
            <Head title={app.name} />
            <div className="grid max-w-3xl gap-5 p-4 text-sm">
                <div className="flex items-start gap-3">
                    <Monogram name={app.name} />
                    <PageHeader
                        title={app.name}
                        description={app.summary}
                        actions={
                            <Link
                                href="/apps"
                                className="underline-offset-4 hover:underline"
                            >
                                All apps
                            </Link>
                        }
                    />
                </div>

                <dl className="grid gap-2 rounded-lg border p-4 sm:grid-cols-2">
                    <div>
                        <dt className="text-muted-foreground">What it syncs</dt>
                        <dd>
                            {app.syncs.length > 0
                                ? app.syncs.join(', ')
                                : 'Details when it launches'}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">Cost</dt>
                        <dd>
                            Free in RightAlly
                            {app.vendor_cost ? ` · ${app.vendor_cost}` : ''}
                        </dd>
                    </div>
                    <div className="sm:col-span-2">
                        <dt className="text-muted-foreground">
                            Who connects it
                        </dt>
                        <dd>{scopeLabel[app.scope]}</dd>
                    </div>
                </dl>

                {app.status === 'soon' && (
                    <section className="grid gap-2 rounded-lg border p-4">
                        <h2 className="font-medium">Coming soon</h2>
                        <p className="text-muted-foreground">
                            Tell us you want it: the apps brokerages ask for
                            most are built first. Until then, Zapier, webhooks
                            and the lead forwarding email cover most needs.
                        </p>
                        <div>
                            <Button
                                disabled={app.requested}
                                onClick={() =>
                                    router.post(
                                        `/apps/${app.key}/request`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {app.requested
                                    ? 'You asked for this'
                                    : 'I want this app'}
                            </Button>
                        </div>
                    </section>
                )}

                {app.one_click && app.status === 'available' && (
                    <section className="grid gap-3 rounded-lg border p-5">
                        {!app.ready ? (
                            <p className="text-muted-foreground">
                                {app.name} will be ready to connect soon. We're
                                finishing the set-up with{' '}
                                {app.name.split(' ')[0]}.
                            </p>
                        ) : !app.can_connect ? (
                            <p className="text-muted-foreground">
                                Your brokerage connects this app for everyone.
                            </p>
                        ) : (
                            <>
                                <p className="text-base">
                                    {connections.length === 0
                                        ? `Press the button, sign in to ${app.name.split(' ')[0]} and approve. That's all.`
                                        : connections[0]?.status ===
                                            'needs_reconnect'
                                          ? 'The connection stopped working. Press the button to connect again.'
                                          : `Connected as ${connections[0]?.label ?? ''}.`}
                                </p>
                                {(connections.length === 0 ||
                                    connections[0]?.status ===
                                        'needs_reconnect') && (
                                    <div>
                                        <Button
                                            size="lg"
                                            className="h-12 px-6 text-base"
                                            onClick={() =>
                                                router.post(
                                                    `/apps/${app.key}/oauth`,
                                                )
                                            }
                                        >
                                            {connections.length === 0
                                                ? `Connect ${app.name}`
                                                : 'Connect again'}
                                        </Button>
                                    </div>
                                )}
                                {errors.app && (
                                    <p className="text-destructive">
                                        {errors.app}
                                    </p>
                                )}
                            </>
                        )}
                    </section>
                )}

                {mcp &&
                    (app.status === 'available' ||
                        app.key === 'ai_assistants') && (
                        <AiAssistantsPanel
                            mcp={mcp}
                            appKey={app.key}
                            name={app.name}
                            guide={app.guide ?? null}
                        />
                    )}

                {app.key === 'csv_import' && (
                    <form
                        className="grid gap-3 rounded-lg border p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            upload.post('/contacts/imports', {
                                forceFormData: true,
                            });
                        }}
                    >
                        <h2 className="font-medium">Import a CSV file</h2>
                        <p className="text-muted-foreground">
                            Export contacts from your CRM (Follow Up Boss,
                            BoldTrail, Lofty and others export CSV), then upload
                            the file. You'll match the columns and see
                            duplicates before anything is added. Imported
                            contacts are never texted automatically unless their
                            consent is on record.
                        </p>
                        <input
                            type="file"
                            accept=".csv,text/csv"
                            aria-label="CSV file"
                            onChange={(e) =>
                                upload.setData(
                                    'file',
                                    e.target.files?.[0] ?? null,
                                )
                            }
                        />
                        {upload.errors.file && (
                            <p className="text-destructive">
                                {upload.errors.file}
                            </p>
                        )}
                        <div>
                            <Button
                                disabled={
                                    upload.processing || !upload.data.file
                                }
                            >
                                Upload and match columns
                            </Button>
                        </div>
                    </form>
                )}

                {[
                    'lead_email',
                    'lead_api',
                    'zapier',
                    'realtor_com',
                    'zillow',
                    'homes_com',
                ].includes(app.key) && (
                    <section className="grid gap-3 rounded-lg border p-4">
                        {app.auth === 'email' &&
                            (app.guide?.steps ?? []).length > 0 && (
                                <ol className="grid list-decimal gap-1 pl-5">
                                    {(app.guide?.steps ?? []).map((step) => (
                                        <li key={step}>{step}</li>
                                    ))}
                                </ol>
                            )}
                        {app.key !== 'lead_api' && (
                            <>
                                <h2 className="font-medium">
                                    {app.key === 'zapier'
                                        ? 'Send RightAlly events to Zapier or Make'
                                        : 'Your forwarding addresses'}
                                </h2>
                                {app.key === 'zapier' ? (
                                    <p className="text-muted-foreground">
                                        In Zapier, start a Zap with “Webhooks by
                                        Zapier → Catch Hook” (in Make, a “Custom
                                        webhook”), copy its URL, and add it
                                        under{' '}
                                        <Link
                                            href="/apps/webhooks"
                                            className="underline"
                                        >
                                            Outgoing webhooks
                                        </Link>{' '}
                                        with the events you want.
                                    </p>
                                ) : (help.emails ?? []).length > 0 ? (
                                    <ul className="grid gap-1">
                                        {(help.emails ?? []).map((e) => (
                                            <li key={e.address}>
                                                {e.name}:{' '}
                                                <code className="break-all">
                                                    {e.address}
                                                </code>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="text-muted-foreground">
                                        Create a “Forwarded emails” lead source
                                        to get an address. Forward your Zillow,
                                        Realtor.com and Homes.com lead emails to
                                        it.
                                    </p>
                                )}
                            </>
                        )}
                        {app.key !== 'lead_email' && (
                            <>
                                <h2 className="font-medium">
                                    Send leads into RightAlly
                                </h2>
                                <p className="text-muted-foreground">
                                    POST JSON to{' '}
                                    <code className="break-all">
                                        {help.api_url ?? '/api/leads'}
                                    </code>{' '}
                                    with the header{' '}
                                    <code>
                                        Authorization: Bearer &lt;key&gt;
                                    </code>
                                    . Each API lead source has its own key
                                    {(help.api_sources ?? []).length > 0
                                        ? `: ${(help.api_sources ?? []).map((s) => `${s.name} (…${s.last4})`).join(', ')}`
                                        : ''}
                                    .
                                </p>
                            </>
                        )}
                        {help.settings_url && (
                            <div>
                                <Link
                                    href={help.settings_url}
                                    className="underline-offset-4 hover:underline"
                                >
                                    Manage lead sources
                                </Link>
                            </div>
                        )}
                    </section>
                )}

                {secret && (
                    <section
                        role="status"
                        className="grid gap-2 rounded-lg border border-primary p-4"
                    >
                        <h2 className="font-medium">Your signing secret</h2>
                        <p>
                            Copy it now: it isn't shown again. Use it to check
                            the X-RightAlly-Signature header on each request.
                        </p>
                        <code className="rounded bg-muted p-2 break-all">
                            {secret.secret}
                        </code>
                        <div>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    void navigator.clipboard
                                        .writeText(secret.secret)
                                        .then(() => setCopied(true));
                                }}
                            >
                                {copied ? 'Copied' : 'Copy'}
                            </Button>
                        </div>
                    </section>
                )}

                {app.status === 'available' &&
                    app.fields.length > 0 &&
                    app.can_connect &&
                    (app.multiple || connections.length === 0) && (
                        <form
                            className="grid gap-3 rounded-lg border p-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(`/apps/${app.key}/connect`, {
                                    preserveScroll: true,
                                    onSuccess: () => form.reset(),
                                });
                            }}
                        >
                            <h2 className="font-medium">
                                {app.multiple && connections.length > 0
                                    ? 'Add another'
                                    : 'Connect'}
                            </h2>
                            {(app.guide?.steps ?? []).length > 0 && (
                                <ol className="grid list-decimal gap-1 pl-5">
                                    {(app.guide?.steps ?? []).map((step) => (
                                        <li key={step}>{step}</li>
                                    ))}
                                </ol>
                            )}
                            {app.fields.map((f) =>
                                f.type === 'events' ? (
                                    <fieldset
                                        key={f.key}
                                        className="grid gap-1"
                                    >
                                        <legend className="mb-1">
                                            {f.label}
                                        </legend>
                                        {Object.entries(events).map(
                                            ([key, label]) => {
                                                const chosen = (form.data[
                                                    f.key
                                                ] ?? []) as string[];

                                                return (
                                                    <label
                                                        key={key}
                                                        className="flex items-center gap-2"
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            checked={chosen.includes(
                                                                key,
                                                            )}
                                                            onChange={(e) =>
                                                                form.setData(
                                                                    f.key,
                                                                    e.target
                                                                        .checked
                                                                        ? [
                                                                              ...chosen,
                                                                              key,
                                                                          ]
                                                                        : chosen.filter(
                                                                              (
                                                                                  k,
                                                                              ) =>
                                                                                  k !==
                                                                                  key,
                                                                          ),
                                                                )
                                                            }
                                                        />
                                                        <span>
                                                            {label}{' '}
                                                            <code className="text-xs text-muted-foreground">
                                                                {key}
                                                            </code>
                                                        </span>
                                                    </label>
                                                );
                                            },
                                        )}
                                        {form.errors[f.key] && (
                                            <p className="text-destructive">
                                                {form.errors[f.key]}
                                            </p>
                                        )}
                                    </fieldset>
                                ) : (
                                    <label key={f.key} className="grid gap-1">
                                        {f.label}
                                        <Input
                                            type={
                                                f.secret
                                                    ? 'password'
                                                    : f.type === 'url'
                                                      ? 'url'
                                                      : 'text'
                                            }
                                            autoComplete="off"
                                            value={String(
                                                form.data[f.key] ?? '',
                                            )}
                                            onChange={(e) =>
                                                form.setData(
                                                    f.key,
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        {f.help && (
                                            <span className="text-muted-foreground">
                                                {f.help}
                                            </span>
                                        )}
                                        {form.errors[f.key] && (
                                            <span className="text-destructive">
                                                {form.errors[f.key]}
                                            </span>
                                        )}
                                    </label>
                                ),
                            )}
                            {form.errors.app && (
                                <p className="text-destructive">
                                    {form.errors.app}
                                </p>
                            )}
                            <div>
                                <Button disabled={form.processing}>
                                    Connect
                                </Button>
                            </div>
                        </form>
                    )}

                {connections.map((c) => (
                    <section
                        key={c.ulid}
                        className="grid gap-3 rounded-lg border p-4"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="flex items-center gap-2 font-medium">
                                {c.label ?? app.name}
                                <Badge
                                    variant={
                                        c.status === 'connected'
                                            ? 'default'
                                            : c.status === 'paused'
                                              ? 'outline'
                                              : 'destructive'
                                    }
                                >
                                    {statusLabel[c.status]}
                                </Badge>
                                {c.mine && (
                                    <Badge variant="outline">Yours</Badge>
                                )}
                            </h2>
                            <div className="flex flex-wrap gap-2">
                                {app.key === 'webhooks' ? (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                `/connections/${c.ulid}/ping`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Send a test event
                                    </Button>
                                ) : (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                `/connections/${c.ulid}/sync`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Sync now
                                    </Button>
                                )}
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            `/connections/${c.ulid}/pause`,
                                            { paused: c.status !== 'paused' },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {c.status === 'paused' ? 'Resume' : 'Pause'}
                                </Button>
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    onClick={() => {
                                        if (
                                            window.confirm(
                                                `Disconnect ${c.label ?? app.name}? Its saved sign-in details are deleted.`,
                                            )
                                        )
                                            router.delete(
                                                `/connections/${c.ulid}`,
                                                {
                                                    preserveScroll: true,
                                                },
                                            );
                                    }}
                                >
                                    Disconnect
                                </Button>
                            </div>
                        </div>
                        {c.url && (
                            <p className="break-all text-muted-foreground">
                                {c.url} · {c.events.join(', ')}
                            </p>
                        )}
                        {c.last_error && (
                            <p className="text-destructive">{c.last_error}</p>
                        )}
                        {app.key !== 'webhooks' && (
                            <p className="text-muted-foreground">
                                Last synced {when(c.last_synced_at)}
                            </p>
                        )}
                        {c.runs.length > 0 && (
                            <ul className="grid gap-1 text-muted-foreground">
                                {c.runs.map((r, i) => (
                                    <li key={i}>
                                        {when(r.at)} ·{' '}
                                        {r.status === 'ok'
                                            ? `${r.created} added, ${r.updated} updated, ${r.skipped} skipped`
                                            : r.status === 'running'
                                              ? 'Running…'
                                              : `Failed: ${r.note ?? ''}`}
                                    </li>
                                ))}
                            </ul>
                        )}
                        {c.deliveries.length > 0 && (
                            <table className="w-full text-left">
                                <thead className="text-muted-foreground">
                                    <tr>
                                        <th className="py-1 font-normal">
                                            When
                                        </th>
                                        <th className="py-1 font-normal">
                                            Event
                                        </th>
                                        <th className="py-1 font-normal">
                                            Result
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {c.deliveries.map((d) => (
                                        <tr key={d.ulid} className="border-t">
                                            <td className="py-1">
                                                {when(d.at)}
                                            </td>
                                            <td className="py-1">
                                                <code>{d.event}</code>
                                            </td>
                                            <td className="py-1">
                                                {d.status === 'delivered'
                                                    ? `Delivered (${d.code})`
                                                    : d.status === 'pending'
                                                      ? `Retrying (try ${d.attempts})${d.error ? ` · ${d.error}` : ''}`
                                                      : `Failed · ${d.error ?? ''}`}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </section>
                ))}

                {!app.can_connect &&
                    app.status === 'available' &&
                    app.fields.length > 0 && (
                        <p className="text-muted-foreground">
                            Your brokerage connects this app for everyone. Ask
                            an owner or staff member.
                        </p>
                    )}
            </div>
        </>
    );
}

AppScreen.layout = {
    breadcrumbs: [
        { title: 'Apps', href: '/apps' },
        { title: 'App', href: '#' },
    ],
};
