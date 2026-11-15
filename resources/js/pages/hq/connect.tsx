import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { HqAppCard, type HqApp } from '@/components/connect/hq-app-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Status = {
    set: boolean;
    from: 'hq' | 'server' | null;
    client_id: string | null;
    secret_expires_on: string | null;
    updated_at: string | null;
};
type Props = {
    return_addresses: {
        connect: string;
        google_sign_in: string;
        microsoft_sign_in: string;
    };
    providers: { google: Status; microsoft: Status };
    categories: Record<string, string>;
    apps: HqApp[];
};

function CopyRow({ label, value }: { label: string; value: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <div className="grid gap-1">
            <span className="text-muted-foreground">{label}</span>
            <div className="flex flex-wrap items-center gap-2">
                <code className="rounded bg-muted px-2 py-1 break-all">
                    {value}
                </code>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => {
                        void navigator.clipboard
                            .writeText(value)
                            .then(() => setCopied(true));
                    }}
                >
                    {copied ? 'Copied' : 'Copy'}
                </Button>
            </div>
        </div>
    );
}

function ClientForm({
    provider,
    status,
}: {
    provider: 'google' | 'microsoft';
    status: Status;
}) {
    const form = useForm({
        client_id: status.client_id ?? '',
        client_secret: '',
        secret_expires_on: status.secret_expires_on ?? '',
    });
    const soon =
        status.secret_expires_on !== null &&
        new Date(status.secret_expires_on).getTime() - Date.now() <
            30 * 24 * 3600 * 1000;

    return (
        <form
            className="grid gap-3 rounded-md border p-3"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/hq/connect/${provider}`, {
                    preserveScroll: true,
                    onSuccess: () => form.reset('client_secret'),
                });
            }}
        >
            <label className="grid gap-1">
                Client ID
                <Input
                    value={form.data.client_id}
                    autoComplete="off"
                    onChange={(e) => form.setData('client_id', e.target.value)}
                />
                {form.errors.client_id && (
                    <span className="text-destructive">
                        {form.errors.client_id}
                    </span>
                )}
            </label>
            <label className="grid gap-1">
                Client secret
                <Input
                    type="password"
                    autoComplete="off"
                    placeholder={
                        status.from === 'hq'
                            ? 'Saved. Paste a new one only to replace it.'
                            : ''
                    }
                    value={form.data.client_secret}
                    onChange={(e) =>
                        form.setData('client_secret', e.target.value)
                    }
                />
                {form.errors.client_secret && (
                    <span className="text-destructive">
                        {form.errors.client_secret}
                    </span>
                )}
            </label>
            {provider === 'microsoft' && (
                <label className="grid gap-1">
                    Secret expires on (shown in Certificates &amp; secrets)
                    <Input
                        type="date"
                        value={form.data.secret_expires_on}
                        onChange={(e) =>
                            form.setData('secret_expires_on', e.target.value)
                        }
                    />
                    {soon && (
                        <span className="text-destructive">
                            The secret expires soon. Create a new one and paste
                            it here before then, or connections stop working.
                        </span>
                    )}
                </label>
            )}
            <div>
                <Button disabled={form.processing}>Save</Button>
            </div>
        </form>
    );
}

/**
 * HQ → Connect setup: RightAlly's Google and Microsoft sign-in apps, the return addresses to add, and the steps.
 */
export default function HqConnect({
    return_addresses,
    providers,
    categories,
    apps,
}: Props) {
    const badge = (s: Status) =>
        s.set ? (
            <Badge>
                Ready{s.from === 'server' ? ' (set on the server)' : ''}
            </Badge>
        ) : (
            <Badge variant="outline">Not set up</Badge>
        );

    return (
        <>
            <Head title="Connect setup" />
            <div className="grid max-w-3xl gap-6 p-4 text-sm">
                <PageHeader
                    title="Connect setup"
                    description="Everything brokers and agents can connect, in one place: switch each app on or off, follow its setup steps, and save RightAlly's keys. Set up the Google and Microsoft sign-in apps once for one-click calendars and sign-in."
                />

                <section className="grid gap-3 rounded-lg border p-4">
                    <h2 className="font-medium">Return addresses</h2>
                    <p className="text-muted-foreground">
                        Google and Microsoft only send people back to addresses
                        you have added. Add all of these exactly as shown (copy
                        them; a single missing character gives a
                        “redirect_uri_mismatch” error).
                    </p>
                    <CopyRow
                        label="One-click connect (calendars and other apps)"
                        value={return_addresses.connect}
                    />
                    <CopyRow
                        label="Sign in with Google"
                        value={return_addresses.google_sign_in}
                    />
                    <CopyRow
                        label="Sign in with Microsoft"
                        value={return_addresses.microsoft_sign_in}
                    />
                </section>

                <section className="grid gap-3 rounded-lg border p-4">
                    <h2 className="flex items-center gap-2 font-medium">
                        Google {badge(providers.google)}
                    </h2>
                    <ol className="grid list-decimal gap-2 pl-5">
                        <li>
                            Open{' '}
                            <a
                                className="underline"
                                href="https://console.cloud.google.com/"
                                target="_blank"
                                rel="noreferrer"
                            >
                                Google Cloud Console
                            </a>{' '}
                            and choose the RightAlly project at the top (or
                            create one called “RightAlly”).
                        </li>
                        <li>
                            Go to <strong>APIs &amp; Services → Library</strong>
                            , search for <strong>Google Calendar API</strong>{' '}
                            and press <strong>Enable</strong>.
                        </li>
                        <li>
                            Open <strong>Google Auth Platform</strong> (older
                            screens: APIs &amp; Services → OAuth consent
                            screen). Under <strong>Branding</strong>, set the
                            app name “RightAlly”, a support email and your
                            domains. Under <strong>Data access</strong>, add the
                            scopes <code>openid</code>, <code>email</code>,{' '}
                            <code>…/auth/calendar.events</code> and{' '}
                            <code>…/auth/calendar.freebusy</code>.
                        </li>
                        <li>
                            Go to <strong>Clients</strong> (older screens: APIs
                            &amp; Services → Credentials) and press{' '}
                            <strong>Create client</strong> →{' '}
                            <strong>Web application</strong>, named “RightAlly”.
                        </li>
                        <li>
                            Under <strong>Authorized redirect URIs</strong>,
                            press <strong>+ Add URI</strong> and paste each
                            return address above (one per line). Press{' '}
                            <strong>Save</strong>. Changes can take a few
                            minutes to work.
                        </li>
                        <li>
                            Copy the <strong>Client ID</strong> and{' '}
                            <strong>Client secret</strong> into the boxes below
                            and press Save.
                        </li>
                        <li>
                            Under <strong>Audience</strong>, publish the app
                            (“In production”) and submit it for verification:
                            Google reviews calendar access. Until it is
                            approved, only people you add as test users can
                            connect.
                        </li>
                    </ol>
                    <ClientForm provider="google" status={providers.google} />
                </section>

                <section className="grid gap-3 rounded-lg border p-4">
                    <h2 className="flex items-center gap-2 font-medium">
                        Microsoft {badge(providers.microsoft)}
                    </h2>
                    <ol className="grid list-decimal gap-2 pl-5">
                        <li>
                            Open the{' '}
                            <a
                                className="underline"
                                href="https://entra.microsoft.com/"
                                target="_blank"
                                rel="noreferrer"
                            >
                                Microsoft Entra admin center
                            </a>{' '}
                            and go to <strong>App registrations</strong>. Open
                            the RightAlly app, or press{' '}
                            <strong>New registration</strong>: name it
                            “RightAlly” and choose{' '}
                            <strong>
                                Accounts in any organizational directory and
                                personal Microsoft accounts
                            </strong>
                            .
                        </li>
                        <li>
                            Under <strong>Manage → Authentication</strong>,
                            press <strong>Add Redirect URI</strong>, choose the{' '}
                            <strong>Web</strong> tile, paste the first return
                            address above and press <strong>Configure</strong>.
                            Add the Microsoft sign-in address the same way.
                        </li>
                        <li>
                            Under <strong>API permissions</strong>, press{' '}
                            <strong>
                                Add a permission → Microsoft Graph → Delegated
                                permissions
                            </strong>
                            , tick <code>openid</code>, <code>email</code>,{' '}
                            <code>offline_access</code>, <code>User.Read</code>{' '}
                            and <code>Calendars.ReadWrite</code>, then{' '}
                            <strong>Add permissions</strong>.
                        </li>
                        <li>
                            Under <strong>Certificates &amp; secrets</strong>,
                            press <strong>New client secret</strong>. Copy its{' '}
                            <strong>Value</strong> straight away (it is shown
                            only once) and note when it expires.
                        </li>
                        <li>
                            On <strong>Overview</strong>, copy the{' '}
                            <strong>Application (client) ID</strong>. Paste it
                            and the secret below, with the expiry date, and
                            press Save.
                        </li>
                        <li>
                            Under <strong>Branding &amp; properties</strong>,
                            verify the publisher, so people see “verified” when
                            they approve.
                        </li>
                    </ol>
                    <ClientForm
                        provider="microsoft"
                        status={providers.microsoft}
                    />
                </section>
                <section className="grid gap-3">
                    <h2 className="text-base font-semibold">Apps</h2>
                    <p className="text-muted-foreground">
                        Brokers and agents can only connect apps that are on
                        here and fully set up. Open an app for its steps, the
                        return address, any partner programme, and its keys.
                    </p>
                    {Object.entries(categories).map(([key, title]) => {
                        const list = apps.filter((a) => a.category === key);
                        if (list.length === 0) return null;

                        return (
                            <div key={key} className="grid gap-2">
                                <h3 className="font-medium">{title}</h3>
                                <ul className="grid gap-2">
                                    {list.map((a) => (
                                        <HqAppCard key={a.key} app={a} />
                                    ))}
                                </ul>
                            </div>
                        );
                    })}
                </section>
            </div>
        </>
    );
}

HqConnect.layout = {
    breadcrumbs: [{ title: 'Connect setup', href: '/hq/connect' }],
};
