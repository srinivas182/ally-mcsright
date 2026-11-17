import { Head, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type App = {
    client_id: string;
    name: string;
    company: string;
    verified: boolean;
    active: boolean;
    scopes: string[];
};

/** HQ → Partner apps (API1): register a partner and hand over its client id and secret once. */
export default function HqPartners({
    apps,
    scopes,
    made,
}: {
    apps: App[];
    scopes: Record<string, string>;
    made: { client_id: string; secret: string | null } | null;
}) {
    const form = useForm<{
        name: string;
        company: string;
        website: string;
        description: string;
        scopes: string[];
        redirects: string;
        verified: boolean;
    }>({
        name: '',
        company: '',
        website: 'https://',
        description: '',
        scopes: ['contacts:read'],
        redirects: '',
        verified: false,
    });

    return (
        <>
            <Head title="Partner apps" />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Partner apps"
                    description="Software companies that connect to brokerages with OAuth. Each brokerage's owner still has to allow an app."
                />
                {made && (
                    <p
                        role="status"
                        className="rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
                    >
                        Give these to the partner now; the secret is not shown
                        again.
                        <code className="mt-1 block select-all">
                            client_id: {made.client_id}
                        </code>
                        <code className="block break-all select-all">
                            client_secret: {made.secret}
                        </code>
                    </p>
                )}
                <form
                    className="grid gap-2 rounded-lg border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/hq/partners');
                    }}
                >
                    {(
                        ['name', 'company', 'website', 'description'] as const
                    ).map((f) => (
                        <input
                            key={f}
                            aria-label={f}
                            placeholder={f[0].toUpperCase() + f.slice(1)}
                            className="rounded border px-3 py-2"
                            value={form.data[f]}
                            onChange={(e) => form.setData(f, e.target.value)}
                        />
                    ))}
                    <textarea
                        aria-label="Return addresses"
                        placeholder="Return addresses (https), one per line"
                        className="rounded border px-3 py-2"
                        value={form.data.redirects}
                        onChange={(e) =>
                            form.setData('redirects', e.target.value)
                        }
                    />
                    {Object.entries(scopes).map(([k, label]) => (
                        <label
                            key={k}
                            className="flex items-center gap-2 text-sm"
                        >
                            <input
                                type="checkbox"
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
                        <input
                            type="checkbox"
                            checked={form.data.verified}
                            onChange={(e) =>
                                form.setData('verified', e.target.checked)
                            }
                        />
                        Verified by RightAlly (we checked the company and its
                        security)
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
                    <Button className="justify-self-start">
                        Register partner
                    </Button>
                </form>
                {apps.map((a) => (
                    <div
                        key={a.client_id}
                        className="flex flex-wrap items-center gap-2 rounded border p-3 text-sm"
                    >
                        <strong>{a.name}</strong> · {a.company}
                        {a.verified && <Badge>Verified</Badge>}
                        <Badge variant={a.active ? 'secondary' : 'outline'}>
                            {a.active ? 'Active' : 'Off'}
                        </Badge>
                        <span className="text-muted-foreground">
                            {a.scopes.join(', ')}
                        </span>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                router.post(`/hq/partners/${a.client_id}`, {
                                    active: !a.active,
                                })
                            }
                        >
                            {a.active ? 'Turn off' : 'Turn on'}
                        </Button>
                    </div>
                ))}
            </div>
        </>
    );
}
