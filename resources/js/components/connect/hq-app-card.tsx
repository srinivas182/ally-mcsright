import { useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';

export type HqApp = {
    key: string;
    name: string;
    category: string;
    built: boolean;
    built_in: boolean;
    one_click: boolean;
    needs: 'none' | 'provider' | 'keys';
    provider: string | null;
    fields: { key: string; label: string; secret: boolean }[];
    steps: string[];
    partner: string | null;
    docs: string | null;
    return_address: string | null;
    webhook_address?: string | null;
    request: {
        to: string | null;
        url: string | null;
        subject: string;
        body: string;
    } | null;
    enabled: boolean;
    ready: boolean;
    setup_complete: boolean;
    keys_set: Record<string, boolean>;
    secret_expires_on: string | null;
};

/**
 * The go-live checklist for one app: send the ready-written request to the vendor (one click opens HQ's own email,
 * or the vendor's form), paste the keys they send back, switch it on. Each step ticks itself off.
 */
function GoLive({ app }: { app: HqApp }) {
    const [copied, setCopied] = useState(false);
    const request = app.request;
    const keysDone =
        app.fields.length === 0 || app.fields.every((f) => app.keys_set[f.key]);
    const href = request
        ? (request.url ??
          `mailto:${request.to ?? ''}?subject=${encodeURIComponent(request.subject)}&body=${encodeURIComponent(request.body)}`)
        : null;
    const steps: { label: string; done: boolean }[] = [
        ...(request
            ? [
                  {
                      label: `Send the request to ${app.name}`,
                      done: keysDone && app.fields.length > 0,
                  },
              ]
            : []),
        ...(app.fields.length > 0
            ? [{ label: 'Paste the keys they send back', done: keysDone }]
            : []),
        { label: `Switch ${app.name} on`, done: app.enabled },
    ];

    return (
        <section
            className="grid gap-2 rounded-md bg-muted p-3"
            aria-label={`Go-live tip for ${app.name}`}
        >
            <span className="font-medium">Tip: going live with {app.name}</span>
            <ol className="grid gap-1">
                {steps.map((s, i) => (
                    <li key={s.label} className="flex items-center gap-2">
                        <span aria-hidden>{s.done ? '✓' : `${i + 1}.`}</span>
                        <span className={s.done ? 'text-muted-foreground' : ''}>
                            {s.label}
                        </span>
                    </li>
                ))}
            </ol>
            {request && href && (
                <div className="flex flex-wrap items-center gap-2">
                    <a
                        href={href}
                        target={request.url ? '_blank' : undefined}
                        rel="noreferrer"
                        className="inline-flex h-10 items-center rounded-md bg-primary px-4 font-medium text-primary-foreground"
                    >
                        {request.url
                            ? `Open ${app.name}'s request form`
                            : `Email ${app.name}`}
                    </a>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => {
                            void navigator.clipboard
                                .writeText(
                                    `${request.subject}\n\n${request.body}`,
                                )
                                .then(() => setCopied(true));
                        }}
                    >
                        {copied ? 'Copied' : 'Copy the message'}
                    </Button>
                    {request.to && (
                        <span className="text-sm text-muted-foreground">
                            To: {request.to}
                        </span>
                    )}
                </div>
            )}
            {request && (
                <details>
                    <summary className="cursor-pointer text-sm">
                        See the message
                    </summary>
                    <pre className="mt-2 text-sm whitespace-pre-wrap">
                        {request.subject}
                        {'\n\n'}
                        {request.body}
                    </pre>
                </details>
            )}
        </section>
    );
}

/** One app on HQ → Connect setup: its state, the go-live tip, the steps, its keys, and the on/off switch. */
export function HqAppCard({ app }: { app: HqApp }) {
    const [open, setOpen] = useState(false);
    const keys: Record<string, string> = {};
    app.fields.forEach((f) => {
        keys[f.key] = '';
    });
    const form = useForm({
        enabled: app.enabled,
        keys,
        secret_expires_on: app.secret_expires_on ?? '',
    });
    const state = !app.built ? (
        <Badge variant="outline">Not built yet</Badge>
    ) : app.ready ? (
        <Badge>Live for everyone</Badge>
    ) : app.enabled ? (
        <Badge variant="destructive">On, setup not finished</Badge>
    ) : (
        <Badge variant="outline">Off</Badge>
    );

    return (
        <li className="rounded-md border">
            <button
                type="button"
                className="flex w-full flex-wrap items-center justify-between gap-2 p-3 text-left"
                aria-expanded={open}
                onClick={() => setOpen(!open)}
            >
                <span className="font-medium">{app.name}</span>
                <span className="flex items-center gap-2">
                    {app.request && !app.ready && (
                        <Badge variant="outline">Tip: request to send</Badge>
                    )}
                    {app.partner && (
                        <Badge variant="outline">Needs partner access</Badge>
                    )}
                    {state}
                </span>
            </button>
            {open && (
                <form
                    className="grid gap-3 border-t p-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/hq/connect/apps/${app.key}`, {
                            preserveScroll: true,
                            onSuccess: () => form.setData('keys', keys),
                        });
                    }}
                >
                    {app.built && !app.built_in && <GoLive app={app} />}
                    {app.steps.length > 0 && (
                        <div className="grid gap-1">
                            <span className="font-medium">Steps</span>
                            <ol className="grid list-decimal gap-1 pl-5">
                                {app.steps.map((s) => (
                                    <li key={s}>{s}</li>
                                ))}
                            </ol>
                        </div>
                    )}
                    {app.partner && (
                        <p>
                            <strong>Partner access:</strong> {app.partner}
                        </p>
                    )}
                    {app.return_address && (
                        <p className="break-all">
                            <strong>Return address:</strong>{' '}
                            <code>{app.return_address}</code>
                        </p>
                    )}
                    {app.webhook_address && (
                        <p className="break-all">
                            <strong>Webhook address:</strong>{' '}
                            <code>{app.webhook_address}</code>
                        </p>
                    )}
                    {app.docs && (
                        <a
                            className="underline"
                            href={app.docs}
                            target="_blank"
                            rel="noreferrer"
                        >
                            {app.name} developer documentation
                        </a>
                    )}
                    {app.fields.map((f) => (
                        <label key={f.key} className="grid gap-1">
                            {f.label}
                            <Input
                                type={f.secret ? 'password' : 'text'}
                                autoComplete="off"
                                placeholder={
                                    app.keys_set[f.key]
                                        ? 'Saved. Paste a new one only to replace it.'
                                        : ''
                                }
                                value={form.data.keys[f.key] ?? ''}
                                onChange={(e) =>
                                    form.setData('keys', {
                                        ...form.data.keys,
                                        [f.key]: e.target.value,
                                    })
                                }
                            />
                        </label>
                    ))}
                    {app.fields.length > 0 && (
                        <label className="grid gap-1">
                            Keys expire on (if the vendor sets an expiry)
                            <Input
                                type="date"
                                value={form.data.secret_expires_on}
                                onChange={(e) =>
                                    form.setData(
                                        'secret_expires_on',
                                        e.target.value,
                                    )
                                }
                            />
                        </label>
                    )}
                    <label className="flex items-center gap-3">
                        <Switch
                            checked={form.data.enabled}
                            disabled={!app.built}
                            onCheckedChange={(v) => form.setData('enabled', v)}
                        />
                        <span>
                            Brokers and agents can use {app.name}
                            <span className="block text-muted-foreground">
                                Turning it off pauses every connection (their
                                data is kept) until you turn it on again.
                            </span>
                        </span>
                    </label>
                    {Object.values(form.errors).map((e) => (
                        <p key={e} className="text-destructive">
                            {e}
                        </p>
                    ))}
                    <div>
                        <Button disabled={form.processing || !app.built}>
                            Save
                        </Button>
                    </div>
                </form>
            )}
        </li>
    );
}
