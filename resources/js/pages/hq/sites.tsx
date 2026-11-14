import { Head, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Props = {
    powered_by: { enabled: boolean; text: string; url: string };
    maps: { configured: boolean; hint: string | null; secret: boolean };
    tenants: {
        ulid: string;
        name: string;
        override: boolean | null;
        hosts: { host: string; verified: boolean }[];
    }[];
};

const opts = { preserveScroll: true };

/** HQ → Websites: the "Powered by RightAlly" credit on every website and assistant, and each brokerage's addresses. */
export default function HqSites({ powered_by, maps, tenants }: Props) {
    const form = useForm(powered_by);
    const map = useForm({ mapbox_token: '' });
    const secret = useForm({ mapbox_secret: '' });
    const choose = (ulid: string, value: string) =>
        router.post(
            `/hq/sites/powered-by/${ulid}`,
            { enabled: value === 'default' ? null : value === 'on' },
            opts,
        );

    return (
        <>
            <Head title="Websites" />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Websites"
                    description="The credit link shown on brokerage and agent websites and on the AIlly assistant. Brokers and agents can rename the assistant and change its avatar, but cannot remove the credit."
                />
                <form
                    className="grid gap-3 rounded-lg border p-4 text-sm"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/hq/sites/powered-by', opts);
                    }}
                >
                    <h2 className="font-semibold">Powered by</h2>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={form.data.enabled}
                            onChange={(e) =>
                                form.setData('enabled', e.target.checked)
                            }
                        />{' '}
                        Show the credit on every website
                    </label>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Input
                            aria-label="Wording"
                            maxLength={60}
                            value={form.data.text}
                            onChange={(e) =>
                                form.setData('text', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Link"
                            value={form.data.url}
                            onChange={(e) =>
                                form.setData('url', e.target.value)
                            }
                        />
                    </div>
                    {Object.values(form.errors).map((m) => (
                        <p key={m} className="text-destructive">
                            {m}
                        </p>
                    ))}
                    <div>
                        <Button disabled={form.processing}>Save</Button>
                    </div>
                </form>

                <form
                    className="grid gap-3 rounded-lg border p-4 text-sm"
                    onSubmit={(e) => {
                        e.preventDefault();
                        map.post('/hq/sites/maps', {
                            ...opts,
                            onSuccess: () => map.reset(),
                        });
                    }}
                >
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="font-semibold">Maps (Mapbox)</h2>
                        {maps.configured ? (
                            <Badge variant="secondary">
                                On · token ending {maps.hint}
                            </Badge>
                        ) : (
                            <Badge variant="outline">
                                Off: websites show lists only
                            </Badge>
                        )}
                    </div>
                    <p className="text-muted-foreground">
                        Paste a Mapbox public token (starts with pk.). It is
                        used for the small map images on listing pages, and for
                        the interactive map until a secret token is set below.
                        It is stored encrypted and not shown again. Leave empty
                        and save to switch maps off.
                    </p>
                    <Input
                        aria-label="Mapbox public token"
                        autoComplete="off"
                        placeholder="pk.…"
                        value={map.data.mapbox_token}
                        onChange={(e) =>
                            map.setData('mapbox_token', e.target.value)
                        }
                    />
                    {map.errors.mapbox_token && (
                        <p className="text-destructive">
                            {map.errors.mapbox_token}
                        </p>
                    )}
                    <div>
                        <Button disabled={map.processing}>
                            Save map token
                        </Button>
                    </div>
                </form>

                <form
                    className="grid gap-3 rounded-lg border p-4 text-sm"
                    onSubmit={(e) => {
                        e.preventDefault();
                        secret.post('/hq/sites/maps/secret', {
                            ...opts,
                            onSuccess: () => secret.reset(),
                        });
                    }}
                >
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="font-semibold">
                            Map tokens that expire within an hour
                        </h2>
                        {maps.secret ? (
                            <Badge variant="secondary">On</Badge>
                        ) : (
                            <Badge variant="outline">Off</Badge>
                        )}
                    </div>
                    <p className="text-muted-foreground">
                        A token in a web page can always be read, so instead of
                        hiding it, websites get a token that stops working
                        within an hour. Create a Mapbox secret token (starts
                        with sk.) with the tokens:write scope plus styles:tiles,
                        styles:read, fonts:read, datasets:read and vision:read.
                        It stays on the server, encrypted, and is never shown
                        again. Saving asks for your password.
                    </p>
                    <Input
                        aria-label="Mapbox secret token"
                        type="password"
                        autoComplete="off"
                        placeholder="sk.…"
                        value={secret.data.mapbox_secret}
                        onChange={(e) =>
                            secret.setData('mapbox_secret', e.target.value)
                        }
                    />
                    {secret.errors.mapbox_secret && (
                        <p className="text-destructive">
                            {secret.errors.mapbox_secret}
                        </p>
                    )}
                    <div>
                        <Button disabled={secret.processing}>
                            {maps.secret && secret.data.mapbox_secret === ''
                                ? 'Remove secret token'
                                : 'Save secret token'}
                        </Button>
                    </div>
                </form>

                <section className="grid gap-2 text-sm">
                    <h2 className="font-semibold">Brokerages</h2>
                    <ul className="grid gap-2">
                        {tenants.map((t) => (
                            <li
                                key={t.ulid}
                                className="flex flex-wrap items-center gap-2 rounded-lg border p-3"
                            >
                                <span className="font-medium">{t.name}</span>
                                {t.hosts.map((h) => (
                                    <Badge
                                        key={h.host}
                                        variant={
                                            h.verified ? 'secondary' : 'outline'
                                        }
                                    >
                                        {h.host}
                                    </Badge>
                                ))}
                                <select
                                    aria-label={`Credit for ${t.name}`}
                                    className="ml-auto rounded-md border bg-background px-2 py-1"
                                    value={
                                        t.override === null
                                            ? 'default'
                                            : t.override
                                              ? 'on'
                                              : 'off'
                                    }
                                    onChange={(e) =>
                                        choose(t.ulid, e.target.value)
                                    }
                                >
                                    <option value="default">
                                        Platform setting
                                    </option>
                                    <option value="on">Always show</option>
                                    <option value="off">
                                        Hide (white label)
                                    </option>
                                </select>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}
