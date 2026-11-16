import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Profile = {
    display_name?: string;
    bio?: string;
    languages?: string[];
    years?: number;
    specialties?: string[];
    photo?: string | null;
};

type AgentRow = {
    ulid: string;
    state: string;
    licence_number: string;
    licence_status: string;
    brokerage_name: string;
    brokerage_confirmed: boolean;
    profile: Profile;
    profile_status: string;
    review_note: string | null;
    terms_signed: boolean;
    status: string;
    tier: string;
    seat: boolean;
    scorecard?: Scorecard | null;
    missing: string[];
    name?: string;
};

type Scorecard = {
    tier: string;
    why: string[];
    accepted: number;
    median_accept_minutes: number | null;
    follow_through: number | null;
    rating: number | null;
    ratings: number;
    appointments_per_100: number | null;
    closings_per_100: number | null;
    stops_share: number | null;
};

/** The published scorecard: every measure, and exactly why the tier is what it is. */
function ScorecardView({ s }: { s: Scorecard }) {
    const pct = (v: number | null) =>
        v === null ? '—' : `${Math.round(v * 100)}%`;

    return (
        <div className="grid gap-1 rounded-lg border p-3 text-sm">
            <strong>Scorecard (last 90 days): {s.tier}</strong>
            <span className="text-muted-foreground">
                Median accept {s.median_accept_minutes ?? '—'} min · status up
                to date {pct(s.follow_through)} · rating {s.rating ?? '—'} (
                {s.ratings}) · appointments {s.appointments_per_100 ?? '—'} and
                closings {s.closings_per_100 ?? '—'} per 100 · stops{' '}
                {pct(s.stops_share)}
            </span>
            <ul className="list-disc pl-5">
                {s.why.map((w) => (
                    <li key={w}>{w}</li>
                ))}
            </ul>
        </div>
    );
}

type Hours = { tz: string; days: Record<string, [string, string]> };

type PackageRow = {
    ulid: string;
    name: string;
    state: string;
    broker: boolean;
    status: string;
    pause_reason: string | null;
    model: string;
    lead_types: string[];
    languages: string[];
    price_min: number;
    price_max: number;
    zips: string;
    cities: string;
    monthly_cap: number;
    daily_cap: number | null;
    hours: Hours;
    agents: string[];
    missing: string[];
    balance: number;
};

type StateRow = {
    code: string;
    name: string;
    on: boolean;
    models: string[];
    pricing: {
        connection: { buyer: number; seller: number };
        appointment: number;
        share_pct: number;
    };
};

type Intro = {
    ulid: string;
    status: string;
    lead_type: string;
    offered_at: string;
    accept_by: string;
    credits: number;
    refund_status: string | null;
};

type Props = {
    introductions: Intro[];
    is_owner: boolean;
    terms_version: string;
    specialties: Record<string, string>;
    models: Record<string, string>;
    states: StateRow[];
    mine: AgentRow[];
    team: AgentRow[];
    packages: PackageRow[];
};

const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

const field = 'grid gap-1 text-sm';
const box = 'min-h-24 rounded-md border bg-background p-2 text-sm';

function toggle(list: string[], v: string): string[] {
    return list.includes(v) ? list.filter((x) => x !== v) : [...list, v];
}

function statusBadge(status: string) {
    const live = status === 'active';

    return (
        <Badge variant={live ? 'default' : 'outline'}>
            {live ? 'Live' : status === 'applying' ? 'Applying' : status}
        </Badge>
    );
}

/** The card people see on the "choose your agent" screen. */
function Card({ p, state }: { p: Profile; state: string }) {
    return (
        <div className="flex gap-3 rounded-xl border p-3">
            {p.photo ? (
                <img
                    src={p.photo}
                    alt=""
                    className="size-16 rounded-full object-cover"
                />
            ) : (
                <div className="grid size-16 place-items-center rounded-full bg-muted text-xs text-muted-foreground">
                    No photo
                </div>
            )}
            <div className="grid gap-1 text-sm">
                <strong>{p.display_name || 'Your name'}</strong>
                <span className="text-muted-foreground">
                    {state} · {p.years ?? 0} years ·{' '}
                    {(p.languages ?? ['en'])
                        .map((l) => (l === 'es' ? 'Español' : 'English'))
                        .join(', ')}
                </span>
                <p>{p.bio || 'Your introduction appears here.'}</p>
            </div>
        </div>
    );
}

function Application({
    agent,
    props,
}: {
    agent: AgentRow | null;
    props: Props;
}) {
    const form = useForm({
        state: agent?.state ?? props.states[0]?.code ?? 'TX',
        licence_number: agent?.licence_number ?? '',
        brokerage_name: agent?.brokerage_name ?? '',
        display_name: agent?.profile.display_name ?? '',
        bio: agent?.profile.bio ?? '',
        years: String(agent?.profile.years ?? ''),
        languages: agent?.profile.languages ?? ['en'],
        specialties: agent?.profile.specialties ?? [],
    });
    const [agree, setAgree] = useState(false);

    return (
        <section className="grid gap-4 rounded-xl border p-4">
            <div className="flex flex-wrap items-center gap-2">
                <h2 className="text-lg font-semibold">
                    {agent
                        ? `${props.states.find((s) => s.code === agent.state)?.name ?? agent.state}`
                        : 'Apply in a state'}
                </h2>
                {agent && statusBadge(agent.status)}
                {agent?.seat && (
                    <Badge variant="outline">Movewise Agent seat</Badge>
                )}
            </div>
            {agent?.scorecard && <ScorecardView s={agent.scorecard} />}
            {agent && agent.missing.length > 0 && (
                <div className="rounded-lg bg-muted p-3 text-sm">
                    <p className="font-medium">Before you go live:</p>
                    <ul className="list-disc pl-5">
                        {agent.missing.map((m) => (
                            <li key={m}>{m}</li>
                        ))}
                    </ul>
                </div>
            )}
            <form
                className="grid gap-3 md:grid-cols-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/movewise/apply', { preserveScroll: true });
                }}
            >
                {!agent && (
                    <label className={field}>
                        State
                        <select
                            className="h-9 rounded-md border bg-background px-2"
                            value={form.data.state}
                            onChange={(e) =>
                                form.setData('state', e.target.value)
                            }
                        >
                            {props.states.map((s) => (
                                <option key={s.code} value={s.code}>
                                    {s.name}
                                </option>
                            ))}
                        </select>
                    </label>
                )}
                <label className={field}>
                    Licence number
                    <Input
                        value={form.data.licence_number}
                        onChange={(e) =>
                            form.setData('licence_number', e.target.value)
                        }
                    />
                    {form.errors.licence_number && (
                        <span className="text-destructive">
                            {form.errors.licence_number}
                        </span>
                    )}
                </label>
                <label className={field}>
                    Name on your card
                    <Input
                        value={form.data.display_name}
                        onChange={(e) =>
                            form.setData('display_name', e.target.value)
                        }
                    />
                </label>
                <label className={field}>
                    Years in real estate
                    <Input
                        inputMode="numeric"
                        value={form.data.years}
                        onChange={(e) => form.setData('years', e.target.value)}
                    />
                </label>
                <label className={`${field} md:col-span-2`}>
                    A short introduction (what you do and how you work)
                    <textarea
                        className={box}
                        maxLength={600}
                        value={form.data.bio}
                        onChange={(e) => form.setData('bio', e.target.value)}
                    />
                    {form.errors.bio && (
                        <span className="text-destructive">
                            {form.errors.bio}
                        </span>
                    )}
                </label>
                <fieldset className="grid gap-1 text-sm">
                    <legend>Languages you work in</legend>
                    {[
                        ['en', 'English'],
                        ['es', 'Español'],
                    ].map(([k, l]) => (
                        <label key={k} className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.data.languages.includes(k)}
                                onChange={() =>
                                    form.setData(
                                        'languages',
                                        toggle(form.data.languages, k),
                                    )
                                }
                            />
                            {l}
                        </label>
                    ))}
                </fieldset>
                <fieldset className="grid gap-1 text-sm">
                    <legend>Specialties (up to 4)</legend>
                    <div className="grid grid-cols-2 gap-1">
                        {Object.entries(props.specialties).map(([k, l]) => (
                            <label key={k} className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={form.data.specialties.includes(k)}
                                    onChange={() =>
                                        form.setData(
                                            'specialties',
                                            toggle(form.data.specialties, k),
                                        )
                                    }
                                />
                                {l}
                            </label>
                        ))}
                    </div>
                </fieldset>
                <div className="md:col-span-2">
                    <Button disabled={form.processing}>Save</Button>
                </div>
            </form>
            {agent && (
                <div className="grid gap-3">
                    <p className="text-sm text-muted-foreground">
                        Your card (the photo comes from Studio → Brand kit):
                    </p>
                    <Card p={agent.profile} state={agent.state} />
                    {agent.review_note && (
                        <p className="text-sm">Movewise: {agent.review_note}</p>
                    )}
                    <div className="flex flex-wrap gap-2">
                        {['draft', 'changes'].includes(
                            agent.profile_status,
                        ) && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        `/movewise/agents/${agent.ulid}/submit`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Send my card for review
                            </Button>
                        )}
                    </div>
                    {!agent.terms_signed && (
                        <div className="grid gap-2 rounded-lg border p-3 text-sm">
                            <p>
                                Movewise program terms (version{' '}
                                {props.terms_version}): you reply to
                                introductions within your working hours, keep
                                each person's status up to date at least every
                                14 days, never bulk-message a Movewise
                                introduction, and pay as your package says.
                                Three strikes end your place in the program.
                            </p>
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={agree}
                                    onChange={(e) => setAgree(e.target.checked)}
                                />
                                I agree to the Movewise program terms
                            </label>
                            <div>
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            `/movewise/agents/${agent.ulid}/terms`,
                                            { agree },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Sign the terms
                                </Button>
                            </div>
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}

function PackageForm({
    pkg,
    props,
    onDone,
}: {
    pkg: PackageRow | null;
    props: Props;
    onDone: () => void;
}) {
    const live = props.states.filter((s) => s.models.length > 0);
    const form = useForm({
        broker: pkg?.broker ?? false,
        name: pkg?.name ?? '',
        state: pkg?.state ?? live[0]?.code ?? props.states[0]?.code ?? 'TX',
        model: pkg?.model ?? '',
        lead_types: pkg?.lead_types ?? ['buyer', 'seller'],
        languages: pkg?.languages ?? ['en'],
        price_min: String(pkg?.price_min ?? ''),
        price_max: String(pkg?.price_max || ''),
        zips: pkg?.zips ?? '',
        cities: pkg?.cities ?? '',
        monthly_cap: String(pkg?.monthly_cap ?? 5),
        daily_cap: pkg?.daily_cap ? String(pkg.daily_cap) : '',
        tz: pkg?.hours.tz ?? 'America/Chicago',
        days:
            pkg?.hours.days ??
            ({
                '1': ['08:00', '19:00'],
                '2': ['08:00', '19:00'],
                '3': ['08:00', '19:00'],
                '4': ['08:00', '19:00'],
                '5': ['08:00', '19:00'],
                '6': ['09:00', '17:00'],
            } as Record<string, [string, string]>),
        agents: pkg?.agents ?? [],
    });
    const state = props.states.find((s) => s.code === form.data.state);
    const errors = Object.values(form.errors);

    return (
        <form
            className="grid gap-3 rounded-xl border p-4 md:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(
                    pkg
                        ? `/movewise/packages/${pkg.ulid}`
                        : '/movewise/packages',
                    { preserveScroll: true, onSuccess: onDone },
                );
            }}
        >
            {props.is_owner && !pkg && (
                <label className="flex items-center gap-2 text-sm md:col-span-2">
                    <input
                        type="checkbox"
                        checked={form.data.broker}
                        onChange={(e) =>
                            form.setData('broker', e.target.checked)
                        }
                    />
                    A broker package: the brokerage pays, and you choose which
                    agents can be shown
                </label>
            )}
            <label className={field}>
                Name
                <Input
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                />
            </label>
            <label className={field}>
                State
                <select
                    className="h-9 rounded-md border bg-background px-2"
                    value={form.data.state}
                    disabled={pkg !== null}
                    onChange={(e) => form.setData('state', e.target.value)}
                >
                    {props.states.map((s) => (
                        <option key={s.code} value={s.code}>
                            {s.name}
                        </option>
                    ))}
                </select>
            </label>
            <label className={field}>
                How you pay
                <select
                    className="h-9 rounded-md border bg-background px-2"
                    value={form.data.model}
                    onChange={(e) => form.setData('model', e.target.value)}
                >
                    <option value="">Choose</option>
                    {(state?.models ?? []).map((m) => (
                        <option key={m} value={m}>
                            {props.models[m]}
                            {m === 'connection' && state
                                ? ` (buyer ${state.pricing.connection.buyer}, seller ${state.pricing.connection.seller} credits)`
                                : m === 'appointment' && state
                                  ? ` (${state.pricing.appointment} credits)`
                                  : m === 'share' && state
                                    ? ` (${state.pricing.share_pct}% at closing)`
                                    : ''}
                        </option>
                    ))}
                </select>
                {state && state.models.length === 0 && (
                    <span className="text-muted-foreground">
                        Movewise hasn't priced {state.name} yet.
                    </span>
                )}
            </label>
            <fieldset className="grid gap-1 text-sm">
                <legend>Introductions to</legend>
                <div className="flex gap-4">
                    {['buyer', 'seller'].map((t) => (
                        <label key={t} className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.data.lead_types.includes(t)}
                                onChange={() =>
                                    form.setData(
                                        'lead_types',
                                        toggle(form.data.lead_types, t),
                                    )
                                }
                            />
                            {t === 'buyer' ? 'Buyers' : 'Sellers'}
                        </label>
                    ))}
                    {['en', 'es'].map((l) => (
                        <label key={l} className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.data.languages.includes(l)}
                                onChange={() =>
                                    form.setData(
                                        'languages',
                                        toggle(form.data.languages, l),
                                    )
                                }
                            />
                            {l === 'es' ? 'Español' : 'English'}
                        </label>
                    ))}
                </div>
            </fieldset>
            <label className={field}>
                ZIP codes you cover
                <textarea
                    className={box}
                    value={form.data.zips}
                    placeholder="77001 77002 77003"
                    onChange={(e) => form.setData('zips', e.target.value)}
                />
            </label>
            <label className={field}>
                Cities you cover
                <textarea
                    className={box}
                    value={form.data.cities}
                    placeholder="Houston, Katy, Sugar Land"
                    onChange={(e) => form.setData('cities', e.target.value)}
                />
            </label>
            <label className={field}>
                Lowest price ($)
                <Input
                    inputMode="numeric"
                    value={form.data.price_min}
                    onChange={(e) => form.setData('price_min', e.target.value)}
                />
            </label>
            <label className={field}>
                Highest price ($, blank for no limit)
                <Input
                    inputMode="numeric"
                    value={form.data.price_max}
                    onChange={(e) => form.setData('price_max', e.target.value)}
                />
            </label>
            <label className={field}>
                Most introductions a month
                <Input
                    inputMode="numeric"
                    value={form.data.monthly_cap}
                    onChange={(e) =>
                        form.setData('monthly_cap', e.target.value)
                    }
                />
            </label>
            <label className={field}>
                Most a day (optional)
                <Input
                    inputMode="numeric"
                    value={form.data.daily_cap}
                    onChange={(e) => form.setData('daily_cap', e.target.value)}
                />
            </label>
            <fieldset className="grid gap-2 text-sm md:col-span-2">
                <legend>
                    Working hours (the reply timer only runs in these hours)
                </legend>
                <select
                    aria-label="Time zone"
                    className="h-9 w-fit rounded-md border bg-background px-2"
                    value={form.data.tz}
                    onChange={(e) => form.setData('tz', e.target.value)}
                >
                    <option value="America/Chicago">Central time</option>
                    <option value="America/Denver">
                        Mountain time (El Paso)
                    </option>
                </select>
                {DAYS.map((d, i) => {
                    const span = form.data.days[String(i)];
                    const set = (v: [string, string] | null) => {
                        const next = { ...form.data.days };
                        if (v) {
                            next[String(i)] = v;
                        } else {
                            delete next[String(i)];
                        }
                        form.setData('days', next);
                    };

                    return (
                        <div key={d} className="flex items-center gap-2">
                            <label className="flex w-20 items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={!!span}
                                    onChange={(e) =>
                                        set(
                                            e.target.checked
                                                ? ['09:00', '17:00']
                                                : null,
                                        )
                                    }
                                />
                                {d}
                            </label>
                            {span && (
                                <>
                                    <Input
                                        aria-label={`${d} start`}
                                        type="time"
                                        className="w-32"
                                        value={span[0]}
                                        onChange={(e) =>
                                            set([e.target.value, span[1]])
                                        }
                                    />
                                    <Input
                                        aria-label={`${d} end`}
                                        type="time"
                                        className="w-32"
                                        value={span[1]}
                                        onChange={(e) =>
                                            set([span[0], e.target.value])
                                        }
                                    />
                                </>
                            )}
                        </div>
                    );
                })}
            </fieldset>
            {form.data.broker && (
                <fieldset className="grid gap-1 text-sm md:col-span-2">
                    <legend>Agents this package can show</legend>
                    {props.team
                        .filter(
                            (a) =>
                                a.state === form.data.state &&
                                a.brokerage_confirmed,
                        )
                        .map((a) => (
                            <label
                                key={a.ulid}
                                className="flex items-center gap-2"
                            >
                                <input
                                    type="checkbox"
                                    checked={form.data.agents.includes(a.ulid)}
                                    onChange={() =>
                                        form.setData(
                                            'agents',
                                            toggle(form.data.agents, a.ulid),
                                        )
                                    }
                                />
                                {a.name} {statusBadge(a.status)}
                            </label>
                        ))}
                </fieldset>
            )}
            {errors.length > 0 && (
                <ul className="text-sm text-destructive md:col-span-2">
                    {errors.map((e) => (
                        <li key={e}>{e}</li>
                    ))}
                </ul>
            )}
            <div className="flex gap-2 md:col-span-2">
                <Button disabled={form.processing}>Save package</Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

export default function MovewiseProgram(props: Props) {
    const [editing, setEditing] = useState<string | null>(null);
    const applied = new Set(props.mine.map((a) => a.state));
    const canApply = props.states.some((s) => !applied.has(s.code));

    return (
        <>
            <Head title="Movewise" />
            <div className="grid gap-6 p-4">
                <PageHeader
                    title="Movewise"
                    description="Introductions to people who chose you on movewisehomes.com. One agent per person, never shared or resold."
                />
                {props.mine.map((a) => (
                    <Application key={a.ulid} agent={a} props={props} />
                ))}
                {canApply && <Application agent={null} props={props} />}

                {props.introductions.length > 0 && (
                    <section className="grid gap-2">
                        <h2 className="text-lg font-semibold">Introductions</h2>
                        {props.introductions.map((i) => (
                            <a
                                key={i.ulid}
                                href={`/movewise/introductions/${i.ulid}`}
                                className="flex flex-wrap items-center gap-2 rounded-xl border p-3 text-sm hover:bg-muted/50"
                            >
                                {statusBadge(
                                    i.status === 'offered'
                                        ? 'waiting'
                                        : i.status,
                                )}
                                <span>
                                    A {i.lead_type},{' '}
                                    {new Date(i.offered_at).toLocaleString()}
                                </span>
                                {i.status === 'offered' && (
                                    <strong>
                                        Accept by{' '}
                                        {new Date(
                                            i.accept_by,
                                        ).toLocaleTimeString()}
                                    </strong>
                                )}
                                {i.credits > 0 && (
                                    <span className="text-muted-foreground">
                                        {i.credits} credits
                                    </span>
                                )}
                                {i.refund_status && (
                                    <Badge variant="outline">
                                        Refund {i.refund_status}
                                    </Badge>
                                )}
                            </a>
                        ))}
                    </section>
                )}

                <section className="grid gap-3">
                    <div className="flex items-center justify-between">
                        <h2 className="text-lg font-semibold">Packages</h2>
                        <Button
                            variant="outline"
                            onClick={() => setEditing('new')}
                        >
                            New package
                        </Button>
                    </div>
                    {editing === 'new' && (
                        <PackageForm
                            pkg={null}
                            props={props}
                            onDone={() => setEditing(null)}
                        />
                    )}
                    {props.packages.length === 0 && editing !== 'new' && (
                        <p className="text-sm text-muted-foreground">
                            No packages yet. A package sets where you work, your
                            price range, buyers or sellers, your monthly cap and
                            how you pay.
                        </p>
                    )}
                    {props.packages.map((p) =>
                        editing === p.ulid ? (
                            <PackageForm
                                key={p.ulid}
                                pkg={p}
                                props={props}
                                onDone={() => setEditing(null)}
                            />
                        ) : (
                            <div
                                key={p.ulid}
                                className="grid gap-2 rounded-xl border p-3 text-sm"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <strong>{p.name}</strong>
                                    {statusBadge(p.status)}
                                    {p.broker && (
                                        <Badge variant="outline">
                                            Broker package
                                        </Badge>
                                    )}
                                    <span className="text-muted-foreground">
                                        {props.models[p.model]} · up to{' '}
                                        {p.monthly_cap} a month · wallet{' '}
                                        {p.balance.toLocaleString()} credits
                                    </span>
                                </div>
                                {p.pause_reason && p.status !== 'active' && (
                                    <p className="text-muted-foreground">
                                        {p.pause_reason}
                                    </p>
                                )}
                                {p.missing.length > 0 && (
                                    <ul className="list-disc pl-5 text-muted-foreground">
                                        {p.missing.map((m) => (
                                            <li key={m}>{m}</li>
                                        ))}
                                    </ul>
                                )}
                                <div className="flex gap-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setEditing(p.ulid)}
                                    >
                                        Edit
                                    </Button>
                                    {p.status === 'active' ? (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    `/movewise/packages/${p.ulid}/pause`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Pause
                                        </Button>
                                    ) : (
                                        <Button
                                            size="sm"
                                            disabled={p.missing.length > 0}
                                            onClick={() =>
                                                router.post(
                                                    `/movewise/packages/${p.ulid}/activate`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Go live
                                        </Button>
                                    )}
                                </div>
                            </div>
                        ),
                    )}
                </section>

                {props.is_owner && props.team.length > 0 && (
                    <section className="grid gap-3">
                        <h2 className="text-lg font-semibold">
                            Your agents on Movewise
                        </h2>
                        {props.team.map((a) => (
                            <div
                                key={a.ulid}
                                className="flex flex-wrap items-center gap-2 rounded-xl border p-3 text-sm"
                            >
                                <strong>{a.name}</strong>
                                <span className="text-muted-foreground">
                                    {a.state} · licence {a.licence_number}
                                </span>
                                {statusBadge(a.status)}
                                <span className="grow" />
                                <Button
                                    size="sm"
                                    variant={
                                        a.brokerage_confirmed
                                            ? 'outline'
                                            : 'default'
                                    }
                                    onClick={() =>
                                        router.post(
                                            `/movewise/agents/${a.ulid}/confirm`,
                                            { yes: !a.brokerage_confirmed },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    {a.brokerage_confirmed
                                        ? 'Withdraw confirmation'
                                        : 'Confirm they work with us'}
                                </Button>
                            </div>
                        ))}
                    </section>
                )}
            </div>
        </>
    );
}
