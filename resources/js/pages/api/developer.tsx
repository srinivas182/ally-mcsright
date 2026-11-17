import { Head, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Key = {
    ulid: string;
    name: string;
    last4: string;
    scopes: string[];
    last_used_at: string | null;
    expires_at: string | null;
    revoked: boolean;
    owner: string | null;
};
type App = {
    client_id: string;
    name: string;
    company: string;
    website: string;
    description: string;
    scopes: string[];
    verified: boolean;
    allowed: boolean;
};

/** Settings › API and partner apps (API1): personal API keys and which partner apps the brokerage allows. */
export default function Developer({
    keys,
    scopes,
    new_key,
    apps,
    owner,
    leader,
    base,
}: {
    keys: Key[];
    scopes: Record<string, string>;
    new_key: string | null;
    apps: App[];
    owner: boolean;
    leader: boolean;
    base: string;
}) {
    const form = useForm<{ name: string; scopes: string[]; expires: string }>({
        name: '',
        scopes: ['contacts:read'],
        expires: '',
    });

    return (
        <>
            <Head title="API and partner apps" />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 text-base md:p-6">
                <PageHeader
                    title="API and partner apps"
                    description="Connect other software to RightAlly. A key acts as you and sees only what you see. It can never delete, message many people, move money or see bank details."
                />
                <section className="grid gap-3 rounded-lg border p-4">
                    <h2 className="text-lg font-semibold">Your API keys</h2>
                    <p className="text-sm text-muted-foreground">
                        Address: <code>{base}</code> · description for
                        developers:{' '}
                        <a className="underline" href={`${base}/openapi.json`}>
                            openapi.json
                        </a>
                    </p>
                    {new_key && (
                        <p
                            role="status"
                            className="rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
                        >
                            Copy your key now; it is not shown again:
                            <code className="mt-1 block break-all select-all">
                                {new_key}
                            </code>
                        </p>
                    )}
                    <form
                        className="grid gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/settings/api/keys', {
                                preserveScroll: true,
                                onSuccess: () => form.reset('name'),
                            });
                        }}
                    >
                        <input
                            aria-label="Key name"
                            className="rounded border px-3 py-2"
                            placeholder="What it is for, e.g. Our accounting sync"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                        {Object.entries(scopes)
                            .filter(([k]) => k !== 'webhooks:manage' || leader)
                            .map(([k, label]) => (
                                <label
                                    key={k}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        className="size-4"
                                        checked={form.data.scopes.includes(k)}
                                        onChange={(e) =>
                                            form.setData(
                                                'scopes',
                                                e.target.checked
                                                    ? [...form.data.scopes, k]
                                                    : form.data.scopes.filter(
                                                          (x) => x !== k,
                                                      ),
                                            )
                                        }
                                    />
                                    {label}
                                </label>
                            ))}
                        <label className="flex items-center gap-2 text-sm">
                            Stops working on (optional)
                            <input
                                type="date"
                                className="rounded border px-2 py-1"
                                value={form.data.expires}
                                onChange={(e) =>
                                    form.setData('expires', e.target.value)
                                }
                            />
                        </label>
                        {Object.values(form.errors).map((m) => (
                            <p
                                key={m}
                                role="alert"
                                className="text-sm text-red-700"
                            >
                                {m}
                            </p>
                        ))}
                        <Button
                            className="justify-self-start"
                            disabled={form.processing || !form.data.name}
                        >
                            Make a key
                        </Button>
                    </form>
                    {keys.map((k) => (
                        <div
                            key={k.ulid}
                            className="flex flex-wrap items-center gap-2 border-t pt-2 text-sm"
                        >
                            <strong>{k.name}</strong>
                            <code>…{k.last4}</code>
                            {k.owner && owner && (
                                <span className="text-muted-foreground">
                                    ({k.owner})
                                </span>
                            )}
                            {k.scopes.map((s) => (
                                <Badge key={s} variant="outline">
                                    {s}
                                </Badge>
                            ))}
                            <span className="text-muted-foreground">
                                {k.last_used_at
                                    ? `last used ${k.last_used_at.slice(0, 10)}`
                                    : 'never used'}
                                {k.expires_at && ` · until ${k.expires_at}`}
                            </span>
                            {k.revoked ? (
                                <Badge variant="secondary">Turned off</Badge>
                            ) : (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        router.post(
                                            `/settings/api/keys/${k.ulid}/revoke`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Turn off
                                </Button>
                            )}
                        </div>
                    ))}
                </section>
                {apps.length > 0 && (
                    <section className="grid gap-3">
                        <h2 className="text-lg font-semibold">Partner apps</h2>
                        <p className="text-sm text-muted-foreground">
                            {owner
                                ? 'Allow an app before anyone in your office can connect it. Turning it off disconnects everyone at once.'
                                : 'Your broker decides which partner apps can connect.'}
                        </p>
                        {apps.map((a) => (
                            <div
                                key={a.client_id}
                                className="grid gap-1 rounded border p-3"
                            >
                                <p className="flex flex-wrap items-center gap-2">
                                    <strong>{a.name}</strong> by {a.company}
                                    {a.verified && (
                                        <Badge>Verified by RightAlly</Badge>
                                    )}
                                    {a.allowed && (
                                        <Badge variant="secondary">
                                            Allowed
                                        </Badge>
                                    )}
                                </p>
                                <p className="text-sm">{a.description}</p>
                                <p className="text-xs text-muted-foreground">
                                    Asks for:{' '}
                                    {a.scopes
                                        .map((s) => scopes[s] ?? s)
                                        .join('; ')}{' '}
                                    ·{' '}
                                    <a className="underline" href={a.website}>
                                        {a.website}
                                    </a>
                                </p>
                                {owner && (
                                    <Button
                                        size="sm"
                                        variant={
                                            a.allowed ? 'outline' : 'default'
                                        }
                                        className="justify-self-start"
                                        onClick={() =>
                                            router.post(
                                                `/settings/api/apps/${a.client_id}`,
                                                { allowed: !a.allowed },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {a.allowed
                                            ? 'Turn off for my brokerage'
                                            : 'Allow for my brokerage'}
                                    </Button>
                                )}
                            </div>
                        ))}
                    </section>
                )}
            </div>
        </>
    );
}
