import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Source = {
    ulid: string;
    key: string;
    name: string;
    provider: string;
    base_url: string;
    states: string[];
    originating_system: string | null;
    status: string;
    has_credentials: boolean;
    sync_interval_minutes: number;
    rate_limit_per_hour: number;
    rules_profile_id: number | null;
    minutes_behind: number | null;
    full_sync_done: boolean;
    last_error: string | null;
    backoff_until: string | null;
    listings: number;
    on_market: number;
    runs: {
        kind: string;
        result: string;
        received: number;
        changed: number;
        removed: number;
        at: string;
        error: string | null;
    }[];
};
type Profile = {
    id: number;
    name: string;
    program: string;
    attribution_template: string;
    disclaimer: string | null;
    logo_url: string | null;
    sold_allowed: boolean;
    sold_delay_days: number;
    suppressed_fields: string[] | null;
    freshness_minutes: number;
    index_off_market: boolean;
};
type Licence = {
    ulid: string;
    brokerage: string;
    mls: string | null;
    broker_mls_id: string;
    office_mls_ids: string[];
    scopes: string[];
    status: string;
    expires_on: string | null;
    agreement_ref: string | null;
    requested: string | null;
};
type Props = {
    sources: Source[];
    profiles: Profile[];
    licences: Licence[];
    providers: string[];
    presets: Record<
        string,
        {
            base_url: string | null;
            token_url: string | null;
            auth: string;
            help: string;
        }
    >;
    scopes: string[];
};

const providerLabels: Record<string, string> = {
    mls_grid: 'MLS Grid',
    trestle: 'Trestle (CoreLogic)',
    bridge: 'Bridge Interactive',
    direct_reso: 'Direct RESO Web API',
    spark: 'Spark API (Flexmls, MLS United)',
    repliers: 'Repliers (Texas, Florida)',
};

/** HQ → MLS connections: feeds, write-only credentials, display rules, freshness and brokerage licences. */
export default function HqMls({
    sources,
    profiles,
    licences,
    providers,
    presets,
    scopes,
}: Props) {
    const opts = { preserveScroll: true };
    const add = useForm({
        key: '',
        name: '',
        provider: 'mls_grid',
        base_url: '',
        states: 'TX',
        originating_system: '',
        timezone: 'America/Chicago',
        sync_interval_minutes: 5,
        rate_limit_per_hour: 7200,
    });

    return (
        <>
            <Head title="MLS connections" />
            <div className="mx-auto grid w-full max-w-6xl gap-8 p-4 md:p-6">
                <PageHeader
                    title="MLS connections"
                    description="Each MLS feed is stored once and shared by every brokerage licensed for it. Credentials are write-only."
                />
                <p>
                    <Link href="/hq/mls/search" className="underline">
                        Fast listing search: server health and Test-mode
                        comparison
                    </Link>
                    {' · '}
                    <Link href="/hq/mls/photos-places" className="underline">
                        Photo tags and nearby places: check the results
                    </Link>
                </p>

                <section className="grid gap-3 text-sm">
                    <h2 className="font-semibold">Feeds</h2>
                    {sources.length === 0 && (
                        <p className="text-muted-foreground">
                            No MLS connected yet.
                        </p>
                    )}
                    {sources.map((s) => (
                        <SourceCard
                            key={s.ulid}
                            source={s}
                            profiles={profiles}
                            opts={opts}
                        />
                    ))}
                    <form
                        className="grid gap-2 rounded-lg border p-3 md:grid-cols-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            add.transform((d) => ({
                                ...d,
                                states: d.states
                                    .split(/[\s,]+/)
                                    .filter(Boolean),
                            }));
                            add.post('/hq/mls/sources', {
                                ...opts,
                                onSuccess: () => add.reset(),
                            });
                        }}
                    >
                        <h3 className="font-medium md:col-span-3">
                            Add an MLS
                        </h3>
                        <Input
                            aria-label="Key"
                            placeholder="Key, e.g. actris"
                            value={add.data.key}
                            onChange={(e) => add.setData('key', e.target.value)}
                        />
                        <Input
                            aria-label="Name"
                            placeholder="Name"
                            value={add.data.name}
                            onChange={(e) =>
                                add.setData('name', e.target.value)
                            }
                        />
                        <select
                            aria-label="Provider"
                            className="rounded border bg-background px-2 py-1.5"
                            value={add.data.provider}
                            onChange={(e) =>
                                add.setData('provider', e.target.value)
                            }
                        >
                            {providers.map((p) => (
                                <option key={p} value={p}>
                                    {providerLabels[p] ?? p}
                                </option>
                            ))}
                        </select>
                        <Input
                            aria-label="Base URL"
                            placeholder={
                                presets[add.data.provider]?.base_url ??
                                'Address from the MLS'
                            }
                            className="md:col-span-2"
                            value={add.data.base_url}
                            onChange={(e) =>
                                add.setData('base_url', e.target.value)
                            }
                        />
                        <p className="text-sm text-muted-foreground md:col-span-3">
                            {presets[add.data.provider]?.help}
                        </p>
                        <Input
                            aria-label="States"
                            placeholder="States, e.g. TX"
                            value={add.data.states}
                            onChange={(e) =>
                                add.setData('states', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Originating system"
                            placeholder="Originating system (MLS Grid)"
                            value={add.data.originating_system}
                            onChange={(e) =>
                                add.setData(
                                    'originating_system',
                                    e.target.value,
                                )
                            }
                        />
                        <Input
                            aria-label="Time zone"
                            value={add.data.timezone}
                            onChange={(e) =>
                                add.setData('timezone', e.target.value)
                            }
                        />
                        <span className="flex gap-2">
                            <Input
                                aria-label="Minutes between syncs"
                                type="number"
                                value={add.data.sync_interval_minutes}
                                onChange={(e) =>
                                    add.setData(
                                        'sync_interval_minutes',
                                        Number(e.target.value),
                                    )
                                }
                            />
                            <Input
                                aria-label="Requests per hour"
                                type="number"
                                value={add.data.rate_limit_per_hour}
                                onChange={(e) =>
                                    add.setData(
                                        'rate_limit_per_hour',
                                        Number(e.target.value),
                                    )
                                }
                            />
                        </span>
                        <Button
                            className="justify-self-start"
                            disabled={add.processing}
                        >
                            Add MLS
                        </Button>
                        {Object.values(add.errors)[0] && (
                            <p
                                role="alert"
                                className="text-destructive md:col-span-3"
                            >
                                {Object.values(add.errors)[0]}
                            </p>
                        )}
                    </form>
                </section>

                <section className="grid gap-3 text-sm">
                    <h2 className="font-semibold">Brokerage licences</h2>
                    {licences.length === 0 ? (
                        <p className="text-muted-foreground">
                            No requests yet.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {licences.map((l) => (
                                <LicenceRow
                                    key={l.ulid}
                                    licence={l}
                                    scopes={scopes}
                                    opts={opts}
                                />
                            ))}
                        </ul>
                    )}
                </section>

                <ProfileForm profiles={profiles} opts={opts} />
            </div>
        </>
    );
}

function SourceCard({
    source: s,
    profiles,
    opts,
}: {
    source: Source;
    profiles: Profile[];
    opts: { preserveScroll: boolean };
}) {
    const creds = useForm({
        token: '',
        client_id: '',
        client_secret: '',
        token_url: '',
        scope: 'api',
    });
    const [oauth, setOauth] = useState(s.provider === 'trestle');

    return (
        <div className="grid gap-2 rounded-lg border p-3">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">{s.name}</span>
                <Badge variant="outline">
                    {providerLabels[s.provider] ?? s.provider}
                </Badge>
                <Badge
                    variant={s.status === 'error' ? 'destructive' : 'outline'}
                >
                    {s.status}
                </Badge>
                <span className="text-muted-foreground">
                    {s.states.join(', ')}
                </span>
                <span className="ml-auto text-xs text-muted-foreground">
                    {s.minutes_behind === null
                        ? 'Never synced'
                        : `${s.minutes_behind} min behind`}{' '}
                    · {s.on_market.toLocaleString()} on market of{' '}
                    {s.listings.toLocaleString()}
                    {s.full_sync_done ? '' : ' · first full sync in progress'}
                </span>
            </div>
            {s.last_error && (
                <p className="text-destructive">Last error: {s.last_error}</p>
            )}
            <div className="flex flex-wrap items-center gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        router.post(`/hq/mls/sources/${s.ulid}/test`, {}, opts)
                    }
                    disabled={!s.has_credentials}
                >
                    Test connection
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        router.post(`/hq/mls/sources/${s.ulid}/sync`, {}, opts)
                    }
                    disabled={!s.has_credentials}
                >
                    Sync now
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        router.patch(
                            `/hq/mls/sources/${s.ulid}`,
                            {
                                status:
                                    s.status === 'paused' ? 'active' : 'paused',
                            },
                            opts,
                        )
                    }
                >
                    {s.status === 'paused' ? 'Resume' : 'Pause'}
                </Button>
                <label className="flex items-center gap-2">
                    Display rules
                    <select
                        className="rounded border bg-background px-2 py-1"
                        value={s.rules_profile_id ?? ''}
                        onChange={(e) =>
                            router.patch(
                                `/hq/mls/sources/${s.ulid}`,
                                {
                                    rules_profile_id:
                                        e.target.value === ''
                                            ? null
                                            : Number(e.target.value),
                                },
                                opts,
                            )
                        }
                    >
                        <option value="">Defaults (no sold data)</option>
                        {profiles.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name}
                            </option>
                        ))}
                    </select>
                </label>
            </div>
            <details>
                <summary>
                    {s.has_credentials
                        ? 'Replace credentials (current ones are hidden)'
                        : 'Add credentials'}
                </summary>
                <form
                    className="mt-2 grid gap-2 md:grid-cols-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        creds.post(`/hq/mls/sources/${s.ulid}/credentials`, {
                            ...opts,
                            onSuccess: () => creds.reset(),
                        });
                    }}
                >
                    <label className="flex items-center gap-2 md:col-span-2">
                        <input
                            type="checkbox"
                            className="size-4"
                            checked={oauth}
                            onChange={(e) => setOauth(e.target.checked)}
                        />
                        OAuth client credentials (Trestle, some direct RESO
                        servers)
                    </label>
                    {oauth ? (
                        <>
                            <Input
                                aria-label="Client ID"
                                placeholder="Client ID"
                                value={creds.data.client_id}
                                onChange={(e) =>
                                    creds.setData('client_id', e.target.value)
                                }
                            />
                            <Input
                                aria-label="Client secret"
                                type="password"
                                autoComplete="off"
                                placeholder="Client secret"
                                value={creds.data.client_secret}
                                onChange={(e) =>
                                    creds.setData(
                                        'client_secret',
                                        e.target.value,
                                    )
                                }
                            />
                            <Input
                                aria-label="Token URL"
                                placeholder="Token URL (filled in for Trestle)"
                                value={creds.data.token_url}
                                onChange={(e) =>
                                    creds.setData('token_url', e.target.value)
                                }
                            />
                            <Input
                                aria-label="Scope"
                                value={creds.data.scope}
                                onChange={(e) =>
                                    creds.setData('scope', e.target.value)
                                }
                            />
                        </>
                    ) : (
                        <Input
                            aria-label="Access token"
                            type="password"
                            autoComplete="off"
                            placeholder="Access token"
                            className="md:col-span-2"
                            value={creds.data.token}
                            onChange={(e) =>
                                creds.setData('token', e.target.value)
                            }
                        />
                    )}
                    <Button
                        className="justify-self-start"
                        disabled={creds.processing}
                    >
                        Save credentials
                    </Button>
                    {Object.values(creds.errors)[0] && (
                        <p className="text-destructive">
                            {Object.values(creds.errors)[0]}
                        </p>
                    )}
                </form>
            </details>
            {s.runs.length > 0 && (
                <ul className="text-xs text-muted-foreground">
                    {s.runs.map((r, i) => (
                        <li key={i}>
                            {new Date(r.at).toLocaleString()} · {r.kind} ·{' '}
                            {r.result} · {r.received} received, {r.changed}{' '}
                            changed{r.removed ? `, ${r.removed} removed` : ''}
                            {r.error ? ` · ${r.error}` : ''}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function LicenceRow({
    licence: l,
    scopes,
    opts,
}: {
    licence: Licence;
    scopes: string[];
    opts: { preserveScroll: boolean };
}) {
    const form = useForm({
        scopes: l.scopes,
        expires_on: l.expires_on ?? '',
        agreement_ref: l.agreement_ref ?? '',
    });

    return (
        <li className="grid gap-2 p-3">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">{l.brokerage}</span>
                <span>{l.mls}</span>
                <Badge
                    variant={l.status === 'pending' ? 'destructive' : 'outline'}
                >
                    {l.status}
                </Badge>
                <span className="text-muted-foreground">
                    Broker MLS ID {l.broker_mls_id}
                    {l.office_mls_ids.length
                        ? ` · offices ${l.office_mls_ids.join(', ')}`
                        : ''}
                    {l.expires_on ? ` · expires ${l.expires_on}` : ''}
                </span>
            </div>
            {l.status !== 'revoked' && (
                <form
                    className="flex flex-wrap items-center gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/hq/mls/licences/${l.ulid}/approve`, opts);
                    }}
                >
                    {scopes.map((sc) => (
                        <label key={sc} className="flex items-center gap-1">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={form.data.scopes.includes(sc)}
                                onChange={(e) =>
                                    form.setData(
                                        'scopes',
                                        e.target.checked
                                            ? [...form.data.scopes, sc]
                                            : form.data.scopes.filter(
                                                  (x) => x !== sc,
                                              ),
                                    )
                                }
                            />
                            {sc.replace('_', ' ')}
                        </label>
                    ))}
                    <Input
                        aria-label="Expires on"
                        type="date"
                        className="w-40"
                        value={form.data.expires_on}
                        onChange={(e) =>
                            form.setData('expires_on', e.target.value)
                        }
                    />
                    <Input
                        aria-label="Agreement reference"
                        placeholder="Agreement reference"
                        className="w-48"
                        value={form.data.agreement_ref}
                        onChange={(e) =>
                            form.setData('agreement_ref', e.target.value)
                        }
                    />
                    <Button size="sm" disabled={form.processing}>
                        {l.status === 'active' ? 'Update' : 'Approve'}
                    </Button>
                    {l.status === 'active' && (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    `/hq/mls/licences/${l.ulid}/revoke`,
                                    {},
                                    opts,
                                )
                            }
                        >
                            Revoke
                        </Button>
                    )}
                    {Object.values(form.errors)[0] && (
                        <span className="text-destructive">
                            {Object.values(form.errors)[0]}
                        </span>
                    )}
                </form>
            )}
        </li>
    );
}

const suppressible = [
    'close_price',
    'original_list_price',
    'lot_size',
    'year_built',
    'subdivision',
    'public_remarks',
    'days_on_market',
    'virtual_tour',
];

function ProfileForm({
    profiles,
    opts,
}: {
    profiles: Profile[];
    opts: { preserveScroll: boolean };
}) {
    const blank = {
        id: null as number | null,
        name: '',
        program: 'idx',
        attribution_template: 'Listing courtesy of {ListOfficeName}',
        disclaimer: '',
        logo_url: '',
        sold_allowed: false,
        sold_delay_days: 0,
        suppressed_fields: [] as string[],
        freshness_minutes: 720,
        index_off_market: false,
    };
    const form = useForm(blank);

    return (
        <section className="grid gap-3 text-sm">
            <h2 className="font-semibold">Display rules</h2>
            <div className="flex flex-wrap gap-2">
                {profiles.map((p) => (
                    <Button
                        key={p.id}
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            form.setData({
                                ...p,
                                disclaimer: p.disclaimer ?? '',
                                logo_url: p.logo_url ?? '',
                                suppressed_fields: p.suppressed_fields ?? [],
                            })
                        }
                    >
                        Edit {p.name}
                    </Button>
                ))}
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => form.setData(blank)}
                >
                    New
                </Button>
            </div>
            <form
                className="grid gap-2 rounded-lg border p-3 md:grid-cols-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/hq/mls/profiles', opts);
                }}
            >
                <Input
                    aria-label="Profile name"
                    placeholder="Name, e.g. ACTRIS IDX"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                />
                <select
                    aria-label="Program"
                    className="rounded border bg-background px-2 py-1.5"
                    value={form.data.program}
                    onChange={(e) => form.setData('program', e.target.value)}
                >
                    <option value="idx">IDX</option>
                    <option value="vow">VOW</option>
                </select>
                <Input
                    aria-label="Attribution"
                    className="md:col-span-2"
                    value={form.data.attribution_template}
                    onChange={(e) =>
                        form.setData('attribution_template', e.target.value)
                    }
                />
                <textarea
                    aria-label="Disclaimer"
                    rows={3}
                    className="rounded border bg-background p-2 md:col-span-2"
                    placeholder="Disclaimer ({LastUpdated} is replaced)"
                    value={form.data.disclaimer}
                    onChange={(e) => form.setData('disclaimer', e.target.value)}
                />
                <Input
                    aria-label="Logo URL"
                    placeholder="Logo URL (https)"
                    value={form.data.logo_url}
                    onChange={(e) => form.setData('logo_url', e.target.value)}
                />
                <label className="flex items-center gap-2">
                    Data older than (minutes) shows "updating"
                    <Input
                        type="number"
                        className="w-24"
                        value={form.data.freshness_minutes}
                        onChange={(e) =>
                            form.setData(
                                'freshness_minutes',
                                Number(e.target.value),
                            )
                        }
                    />
                </label>
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        className="size-4"
                        checked={form.data.sold_allowed}
                        onChange={(e) =>
                            form.setData('sold_allowed', e.target.checked)
                        }
                    />
                    Sold listings may be shown after
                    <Input
                        type="number"
                        className="w-20"
                        value={form.data.sold_delay_days}
                        onChange={(e) =>
                            form.setData(
                                'sold_delay_days',
                                Number(e.target.value),
                            )
                        }
                    />
                    days
                </label>
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        className="size-4"
                        checked={form.data.index_off_market}
                        onChange={(e) =>
                            form.setData('index_off_market', e.target.checked)
                        }
                    />
                    Search engines may index sold/off-market pages
                </label>
                <fieldset className="flex flex-wrap gap-3 md:col-span-2">
                    <legend className="text-xs text-muted-foreground">
                        Hide these fields
                    </legend>
                    {suppressible.map((f) => (
                        <label key={f} className="flex items-center gap-1">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={form.data.suppressed_fields.includes(
                                    f,
                                )}
                                onChange={(e) =>
                                    form.setData(
                                        'suppressed_fields',
                                        e.target.checked
                                            ? [
                                                  ...form.data
                                                      .suppressed_fields,
                                                  f,
                                              ]
                                            : form.data.suppressed_fields.filter(
                                                  (x) => x !== f,
                                              ),
                                    )
                                }
                            />
                            {f.replaceAll('_', ' ')}
                        </label>
                    ))}
                </fieldset>
                <Button
                    className="justify-self-start"
                    disabled={form.processing}
                >
                    Save rules
                </Button>
                {Object.values(form.errors)[0] && (
                    <p className="text-destructive md:col-span-2">
                        {Object.values(form.errors)[0]}
                    </p>
                )}
            </form>
        </section>
    );
}
