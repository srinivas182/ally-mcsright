import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { OutLink, SetupGuide } from '@/components/setup-guide';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Field = {
    key: string;
    label: string;
    secret: boolean;
    test: string | null;
    live: string | null;
};

type Service = {
    key: string;
    category: string;
    label: string;
    about: string;
    level: 'hq' | 'brokerage' | 'both';
    sprint: string;
    contacts: boolean;
    providers: {
        key: string;
        label: string;
        note: string | null;
        fields: Field[];
        guide: { site: string; steps: string[] } | null;
    }[];
    provider: string;
    saved: boolean;
    mode: 'off' | 'test' | 'live';
    test_contacts: string;
    checked_at: string | null;
    check_ok: boolean | null;
    check_mode: string | null;
    check_message: string | null;
    live_blocker: string | null;
    hq_mode: string | null;
};

type Props = { services: Service[]; level: 'hq' | 'brokerage' };

const modeLabel = { off: 'Off', test: 'Test', live: 'Live' } as const;

/**
 * FL1 (specs/core/16): outside services. One card per service: choose the provider, save Test and Live keys
 * (encrypted, never shown again), check the connection, then switch Off, Test or Live. Live unlocks after a test
 * passes. HQ sees RightAlly's own accounts; a brokerage sees the services it may connect itself.
 */
type Show = 'all' | 'todo' | 'test' | 'live';

const SHOW: { key: Show; label: string }[] = [
    { key: 'all', label: 'Any status' },
    { key: 'todo', label: 'Not set up yet' },
    { key: 'test', label: 'In Test' },
    { key: 'live', label: 'Live' },
];

const FILTER_KEY = 'services-filter';

function remembered(): { category: string; show: Show } {
    try {
        const v: unknown = JSON.parse(
            window.localStorage.getItem(FILTER_KEY) ?? 'null',
        );
        if (v && typeof v === 'object' && 'category' in v && 'show' in v) {
            const { category, show } = v as {
                category: unknown;
                show: unknown;
            };
            if (
                typeof category === 'string' &&
                SHOW.some((o) => o.key === show)
            ) {
                return { category, show: show as Show };
            }
        }
    } catch {
        // Storage can be unavailable; the page still works.
    }

    return { category: 'all', show: 'all' };
}

function matches(service: Service, show: Show): boolean {
    if (show === 'todo') {
        return service.mode === 'off';
    }

    return show === 'all' || service.mode === show;
}

export default function Services({ services, level }: Props) {
    const base = level === 'hq' ? '/hq/services' : '/settings/services';
    const categories = [...new Set(services.map((s) => s.category))];
    const [filter, setFilterState] = useState(() =>
        typeof window === 'undefined'
            ? { category: 'all', show: 'all' as Show }
            : remembered(),
    );
    const category = categories.includes(filter.category)
        ? filter.category
        : 'all';
    const setFilter = (next: { category: string; show: Show }) => {
        setFilterState(next);
        try {
            window.localStorage.setItem(FILTER_KEY, JSON.stringify(next));
        } catch {
            // Not remembered in private windows; nothing else changes.
        }
    };
    const shown = services.filter(
        (s) =>
            (category === 'all' || s.category === category) &&
            matches(s, filter.show),
    );
    const groups = categories
        .map((c) => ({ name: c, items: shown.filter((s) => s.category === c) }))
        .filter((g) => g.items.length > 0);

    return (
        <>
            <Head title="Outside services" />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Outside services"
                    description={
                        level === 'hq'
                            ? "RightAlly's own accounts with outside providers. Each one is Off, Test or Live. In Test nothing reaches real people and no real money moves."
                            : "Use your brokerage's own account for these services, or leave them to RightAlly's account. In Test nothing reaches real people."
                    }
                />
                {services.length > 0 && (
                    <SetupGuide
                        title="How to connect a service"
                        defaultOpen={!services.some((x) => x.saved)}
                        intro="Each card below is one outside service. You only need the ones you use; the rest can stay Off. The recommended provider is listed first."
                        steps={[
                            {
                                title: 'Choose the provider',
                                body: (
                                    <p>
                                        Keep the recommended one unless you
                                        already pay for another.
                                    </p>
                                ),
                            },
                            {
                                title: 'Get the keys from the provider',
                                body: (
                                    <p>
                                        Open{' '}
                                        <strong>Where do I get these?</strong>{' '}
                                        under the provider: it links to their
                                        website and says where the keys are.
                                        Most providers give separate test (or
                                        sandbox) keys and live keys.
                                    </p>
                                ),
                            },
                            {
                                title: 'Paste the Test keys and save',
                                body: (
                                    <p>
                                        Paste them in <strong>Test keys</strong>{' '}
                                        and click Save. Keys are locked away and
                                        never shown again; RightAlly may ask for
                                        your password.
                                    </p>
                                ),
                            },
                            {
                                title: 'Check the connection, then try it',
                                body: (
                                    <p>
                                        Click{' '}
                                        <strong>Check connection (Test)</strong>
                                        . When it shows a green tick, switch the
                                        service to <strong>Test</strong> and try
                                        it: in Test nothing reaches real people
                                        and no real money moves.
                                    </p>
                                ),
                            },
                            {
                                title: 'Go live',
                                body: (
                                    <p>
                                        Paste the <strong>Live keys</strong>,
                                        save, click{' '}
                                        <strong>Check connection (Live)</strong>
                                        , then switch to <strong>Live</strong>.
                                    </p>
                                ),
                            },
                        ]}
                    />
                )}
                {services.length === 0 && (
                    <p className="text-base text-muted-foreground">
                        RightAlly runs every outside service for you. There is
                        nothing to set up here.
                    </p>
                )}
                {categories.length > 1 && (
                    <nav
                        aria-label="Filter services"
                        className="sticky top-0 z-10 grid gap-2 rounded-lg border bg-background/95 p-3 backdrop-blur"
                    >
                        <div
                            role="group"
                            aria-label="Category"
                            className="flex flex-wrap gap-2"
                        >
                            {['all', ...categories].map((c) => {
                                const count =
                                    c === 'all'
                                        ? services.length
                                        : services.filter(
                                              (s) => s.category === c,
                                          ).length;

                                return (
                                    <Button
                                        key={c}
                                        type="button"
                                        size="sm"
                                        variant={
                                            category === c
                                                ? 'default'
                                                : 'outline'
                                        }
                                        aria-pressed={category === c}
                                        onClick={() =>
                                            setFilter({
                                                ...filter,
                                                category: c,
                                            })
                                        }
                                    >
                                        {c === 'all' ? 'All' : c} ({count})
                                    </Button>
                                );
                            })}
                        </div>
                        <div
                            role="group"
                            aria-label="Status"
                            className="flex flex-wrap items-center gap-2 text-sm"
                        >
                            {SHOW.map((o) => (
                                <label
                                    key={o.key}
                                    className="flex items-center gap-1"
                                >
                                    <input
                                        type="radio"
                                        name="services-status"
                                        className="size-4"
                                        checked={filter.show === o.key}
                                        onChange={() =>
                                            setFilter({
                                                ...filter,
                                                show: o.key,
                                            })
                                        }
                                    />
                                    {o.label} (
                                    {
                                        services.filter(
                                            (s) =>
                                                (category === 'all' ||
                                                    s.category === category) &&
                                                matches(s, o.key),
                                        ).length
                                    }
                                    )
                                </label>
                            ))}
                        </div>
                    </nav>
                )}
                {services.length > 0 && groups.length === 0 && (
                    <p className="text-muted-foreground">
                        Nothing matches. Choose All or Any status to see every
                        service.
                    </p>
                )}
                {groups.map((g) => (
                    <section
                        key={g.name}
                        className="grid gap-4"
                        aria-labelledby={`cat-${g.name.replace(/\W+/g, '-')}`}
                    >
                        <h2
                            id={`cat-${g.name.replace(/\W+/g, '-')}`}
                            className="text-base font-semibold text-muted-foreground"
                        >
                            {g.name}
                        </h2>
                        {g.items.map((s) => (
                            <ServiceCard
                                key={s.key + s.provider + s.mode}
                                service={s}
                                base={base}
                                level={level}
                            />
                        ))}
                    </section>
                ))}
            </div>
        </>
    );
}

/** HQ4: where this service is up to, in plain words. */
function status(service: Service): string {
    if (service.mode === 'live') {
        return 'Live';
    }
    if (service.mode === 'test') {
        return 'In Test: try it, then go live';
    }
    if (!service.saved) {
        return 'Not set up';
    }
    if (service.check_ok) {
        return 'Connected: switch to Test to try it';
    }

    return 'Keys saved: check the connection';
}

function ServiceCard({
    service,
    base,
    level,
}: {
    service: Service;
    base: string;
    level: 'hq' | 'brokerage';
}) {
    const form = useForm<{
        provider: string;
        test: Record<string, string>;
        live: Record<string, string>;
        test_contacts: string;
    }>({
        provider: service.provider,
        test: {},
        live: {},
        test_contacts: service.test_contacts,
    });
    const provider =
        service.providers.find((p) => p.key === form.data.provider) ??
        service.providers[0];
    const savedProvider =
        service.saved && form.data.provider === service.provider;
    const opts = { preserveScroll: true };
    const url = `${base}/${service.key}`;
    // SVC1: each service is one short row; its settings open below it.
    const [expanded, setExpanded] = useState(false);
    const panel = `svc-panel-${service.key}`;

    return (
        <section
            className="grid gap-4 rounded-xl border bg-card p-4 text-base shadow-xs sm:p-5"
            aria-labelledby={`svc-${service.key}`}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="grid min-w-0 flex-1 gap-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3
                            id={`svc-${service.key}`}
                            className="text-base font-semibold"
                        >
                            {service.label}
                        </h3>
                        <Badge
                            variant={
                                service.mode === 'live' ? 'default' : 'outline'
                            }
                        >
                            {modeLabel[service.mode]}
                        </Badge>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {service.about}
                    </p>
                    <p className="text-sm">
                        <span className="text-muted-foreground">
                            {service.saved
                                ? (service.providers.find(
                                      (p) => p.key === service.provider,
                                  )?.label ?? '') + ' · '
                                : ''}
                        </span>
                        {status(service)}
                    </p>
                </div>
                <Button
                    type="button"
                    variant={service.saved ? 'outline' : 'default'}
                    aria-expanded={expanded}
                    aria-controls={panel}
                    onClick={() => setExpanded(!expanded)}
                >
                    {expanded ? 'Close' : service.saved ? 'Manage' : 'Set up'}
                </Button>
            </div>
            {expanded && (
                <div id={panel} className="grid gap-4 border-t pt-4">
                    {level === 'brokerage' && !service.saved && (
                        <p className="text-sm">
                            {service.level === 'both'
                                ? `Now using RightAlly's account (${modeLabel[(service.hq_mode ?? 'off') as keyof typeof modeLabel]}). Add your own below to use yours instead.`
                                : 'Connect your own account to use this.'}
                        </p>
                    )}

                    <form
                        className="grid gap-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(url, {
                                ...opts,
                                onSuccess: () =>
                                    form.setData({
                                        ...form.data,
                                        test: {},
                                        live: {},
                                    }),
                            });
                        }}
                    >
                        <fieldset className="grid gap-2">
                            <legend className="mb-1 font-medium">
                                Provider
                            </legend>
                            {service.providers.map((p) => (
                                <label
                                    key={p.key}
                                    className="flex items-start gap-2"
                                >
                                    <input
                                        type="radio"
                                        className="mt-1 size-4"
                                        name={`provider-${service.key}`}
                                        checked={form.data.provider === p.key}
                                        onChange={() =>
                                            form.setData('provider', p.key)
                                        }
                                    />
                                    <span>
                                        {p.label}
                                        {p.note && (
                                            <span className="block text-sm text-muted-foreground">
                                                {p.note}
                                            </span>
                                        )}
                                    </span>
                                </label>
                            ))}
                            {provider.guide && (
                                <details
                                    className="rounded-md bg-muted/50 p-3 text-sm"
                                    open={!service.saved}
                                >
                                    <summary className="cursor-pointer font-medium">
                                        Where do I get these?
                                    </summary>
                                    <ol className="mt-2 grid list-decimal gap-1 pl-5 text-muted-foreground">
                                        {provider.guide.site !== '' && (
                                            <li>
                                                Sign in or sign up at{' '}
                                                <OutLink
                                                    href={provider.guide.site}
                                                >
                                                    {new URL(
                                                        provider.guide.site,
                                                    ).hostname.replace(
                                                        /^www\./,
                                                        '',
                                                    )}
                                                </OutLink>
                                                .
                                            </li>
                                        )}
                                        {provider.guide.steps.map((step) => (
                                            <li key={step}>{step}</li>
                                        ))}
                                    </ol>
                                </details>
                            )}
                            {service.saved &&
                                form.data.provider !== service.provider && (
                                    <p className="text-sm text-amber-700 dark:text-amber-400">
                                        Changing provider clears the saved keys
                                        and switches this off until you test
                                        again.
                                    </p>
                                )}
                        </fieldset>

                        {provider.fields.length > 0 && (
                            <div className="grid gap-3 md:grid-cols-2">
                                {(['test', 'live'] as const).map((m) => (
                                    <div key={m} className="grid gap-2">
                                        <h3 className="font-medium">
                                            {m === 'test'
                                                ? 'Test keys'
                                                : 'Live keys'}
                                        </h3>
                                        {provider.fields.map((f) => {
                                            const saved = savedProvider
                                                ? f[m]
                                                : null;

                                            return (
                                                <label
                                                    key={f.key}
                                                    className="grid gap-1"
                                                >
                                                    <span className="text-sm">
                                                        {f.label}
                                                    </span>
                                                    <Input
                                                        type={
                                                            f.secret
                                                                ? 'password'
                                                                : 'text'
                                                        }
                                                        autoComplete="off"
                                                        placeholder={
                                                            f.secret
                                                                ? (saved ??
                                                                  'Paste here (stored encrypted)')
                                                                : ''
                                                        }
                                                        value={
                                                            form.data[m][
                                                                f.key
                                                            ] ??
                                                            (f.secret
                                                                ? ''
                                                                : (saved ?? ''))
                                                        }
                                                        onChange={(e) =>
                                                            form.setData(m, {
                                                                ...form.data[m],
                                                                [f.key]:
                                                                    e.target
                                                                        .value,
                                                            })
                                                        }
                                                    />
                                                </label>
                                            );
                                        })}
                                    </div>
                                ))}
                            </div>
                        )}

                        {service.contacts && (
                            <label className="grid gap-1">
                                <span className="font-medium">
                                    Test contacts
                                </span>
                                <span className="text-sm text-muted-foreground">
                                    In Test mode only these phone numbers or
                                    emails are ever reached. Separate them with
                                    commas.
                                </span>
                                <Input
                                    value={form.data.test_contacts}
                                    onChange={(e) =>
                                        form.setData(
                                            'test_contacts',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="+15125550100, you@example.com"
                                />
                            </label>
                        )}

                        <Button
                            className="justify-self-start"
                            disabled={form.processing}
                        >
                            Save
                        </Button>
                        {Object.values(form.errors)[0] && (
                            <p role="alert" className="text-destructive">
                                {Object.values(form.errors)[0]}
                            </p>
                        )}
                    </form>

                    {service.saved && (
                        <div className="grid gap-3 border-t pt-4">
                            <div className="flex flex-wrap gap-2">
                                {(['test', 'live'] as const).map((m) => (
                                    <Button
                                        key={m}
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                `${url}/check`,
                                                { mode: m },
                                                opts,
                                            )
                                        }
                                    >
                                        Check connection (
                                        {m === 'test' ? 'Test' : 'Live'})
                                    </Button>
                                ))}
                            </div>
                            {service.check_message && (
                                <p
                                    role="status"
                                    className={
                                        service.check_ok
                                            ? 'text-green-700 dark:text-green-400'
                                            : 'text-destructive'
                                    }
                                >
                                    {service.check_ok ? '✓ ' : '✗ '}
                                    {service.check_mode === 'live'
                                        ? 'Live'
                                        : 'Test'}
                                    : {service.check_message}
                                </p>
                            )}
                            <div
                                role="radiogroup"
                                aria-label={`${service.label} mode`}
                                className="flex flex-wrap gap-2"
                            >
                                {(['off', 'test', 'live'] as const).map((m) => (
                                    <Button
                                        key={m}
                                        role="radio"
                                        aria-checked={service.mode === m}
                                        variant={
                                            service.mode === m
                                                ? 'default'
                                                : 'outline'
                                        }
                                        disabled={
                                            m === 'live' &&
                                            service.live_blocker !== null
                                        }
                                        onClick={() =>
                                            router.post(
                                                `${url}/mode`,
                                                { mode: m },
                                                opts,
                                            )
                                        }
                                    >
                                        {modeLabel[m]}
                                    </Button>
                                ))}
                            </div>
                            {service.live_blocker && (
                                <p className="text-sm text-muted-foreground">
                                    Live is locked: {service.live_blocker}
                                </p>
                            )}
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}
