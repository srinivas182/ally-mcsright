import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Pricing = {
    models: string[];
    connection: { buyer: number; seller: number };
    appointment: number;
    share_pct: number;
};

type StateRow = {
    code: string;
    name: string;
    on: boolean;
    pricing: Pricing;
    offered: string[];
    live_agents: number;
    live_packages: number;
};

type AgentRow = {
    ulid: string;
    account: string;
    state: string;
    licence_number: string;
    licence_status: string;
    brokerage_name: string;
    brokerage_confirmed: boolean;
    seat: boolean;
    discipline_checked: boolean;
    profile: {
        display_name?: string;
        bio?: string;
        photo?: string | null;
        years?: number;
    };
    profile_status: string;
    review_note: string | null;
    terms_signed: boolean;
    status: string;
    pause_reason: string | null;
    tier: string;
    strikes: number;
    missing: string[];
};

type SeatRow = {
    ulid: string;
    name: string;
    email: string;
    state: string;
    licence_number: string;
    brokerage_name: string;
    licence_status: string;
    status: string;
    note: string | null;
};

type RefundRow = {
    ulid: string;
    account: string | null;
    agent: string;
    credits: number;
    reason: string | null;
    method: string;
    accepted_at: string | null;
};

type RelayRow = {
    id: number;
    number: string;
    area: string | null;
    active: boolean;
    in_use: number;
};

type Props = {
    relays: RelayRow[];
    refunds: RefundRow[];
    introductions: {
        offered: number;
        accepted_30d: number;
        expired_30d: number;
    };
    models: Record<string, string>;
    states: StateRow[];
    agents: AgentRow[];
    seats: SeatRow[];
};

function PricingForm({
    s,
    models,
}: {
    s: StateRow;
    models: Record<string, string>;
}) {
    const form = useForm({
        models: s.pricing.models,
        connection_buyer: String(s.pricing.connection.buyer),
        connection_seller: String(s.pricing.connection.seller),
        appointment: String(s.pricing.appointment),
        share_pct: String(s.pricing.share_pct),
    });

    return (
        <form
            className="grid gap-2 rounded-xl border p-3 text-sm"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(`/hq/movewise/program/states/${s.code}/pricing`, {
                    preserveScroll: true,
                });
            }}
        >
            <div className="flex flex-wrap items-center gap-2">
                <strong>{s.name}</strong>
                <Badge variant={s.on ? 'default' : 'outline'}>
                    {s.on ? 'Live' : 'Off'}
                </Badge>
                <span className="text-muted-foreground">
                    {s.live_agents} live agents · {s.live_packages} live
                    packages
                </span>
            </div>
            <div className="flex flex-wrap gap-4">
                {Object.entries(models).map(([k, l]) => (
                    <label key={k} className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={form.data.models.includes(k)}
                            onChange={() =>
                                form.setData(
                                    'models',
                                    form.data.models.includes(k)
                                        ? form.data.models.filter(
                                              (m) => m !== k,
                                          )
                                        : [...form.data.models, k],
                                )
                            }
                        />
                        {l}
                    </label>
                ))}
            </div>
            <div className="grid gap-2 md:grid-cols-4">
                <label className="grid gap-1">
                    Buyer introduction (credits)
                    <Input
                        inputMode="numeric"
                        value={form.data.connection_buyer}
                        onChange={(e) =>
                            form.setData('connection_buyer', e.target.value)
                        }
                    />
                </label>
                <label className="grid gap-1">
                    Seller introduction (credits)
                    <Input
                        inputMode="numeric"
                        value={form.data.connection_seller}
                        onChange={(e) =>
                            form.setData('connection_seller', e.target.value)
                        }
                    />
                </label>
                <label className="grid gap-1">
                    Held appointment (credits)
                    <Input
                        inputMode="numeric"
                        value={form.data.appointment}
                        onChange={(e) =>
                            form.setData('appointment', e.target.value)
                        }
                    />
                </label>
                <label className="grid gap-1">
                    Share at close (%)
                    <Input
                        inputMode="decimal"
                        value={form.data.share_pct}
                        onChange={(e) =>
                            form.setData('share_pct', e.target.value)
                        }
                    />
                </label>
            </div>
            <p className="text-muted-foreground">
                Offered now:{' '}
                {s.offered.length > 0
                    ? s.offered.map((m) => models[m]).join(', ')
                    : 'nothing (a model needs to be on and priced)'}
                . Changes apply to new introductions only.
            </p>
            {form.errors.share_pct && (
                <p className="text-destructive">{form.errors.share_pct}</p>
            )}
            <div>
                <Button size="sm" disabled={form.processing}>
                    Save prices
                </Button>
            </div>
        </form>
    );
}

function Review({ a }: { a: AgentRow }) {
    const [note, setNote] = useState('');
    const [discipline, setDiscipline] = useState(a.discipline_checked);
    const [licence, setLicence] = useState(false);
    const [brokerage, setBrokerage] = useState(false);
    const send = (approve: boolean) =>
        router.post(
            `/hq/movewise/program/agents/${a.ulid}/review`,
            {
                approve,
                note,
                discipline_clear: discipline,
                licence_verified: licence,
                brokerage_confirmed: brokerage,
            },
            { preserveScroll: true },
        );

    return (
        <div className="grid gap-2 rounded-xl border p-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <strong>{a.profile.display_name ?? 'Agent'}</strong>
                <span className="text-muted-foreground">
                    {a.account} · {a.state} licence {a.licence_number} (
                    {a.licence_status}) · {a.brokerage_name}
                </span>
                <Badge variant={a.status === 'active' ? 'default' : 'outline'}>
                    {a.status}
                </Badge>
                {a.seat && <Badge variant="outline">Seat</Badge>}
                <Badge variant="outline">Card: {a.profile_status}</Badge>
            </div>
            <div className="flex gap-3">
                {a.profile.photo && (
                    <img
                        src={a.profile.photo}
                        alt=""
                        className="size-14 rounded-full object-cover"
                    />
                )}
                <p>{a.profile.bio}</p>
            </div>
            {a.missing.length > 0 && (
                <p className="text-muted-foreground">
                    Missing: {a.missing.join('; ')}
                </p>
            )}
            {a.pause_reason && (
                <p className="text-muted-foreground">
                    Paused: {a.pause_reason}
                </p>
            )}
            <div className="flex flex-wrap gap-4">
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        checked={discipline}
                        onChange={(e) => setDiscipline(e.target.checked)}
                    />
                    No open public discipline in {a.state}
                </label>
                {['manual', 'unchecked'].includes(a.licence_status) && (
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={licence}
                            onChange={(e) => setLicence(e.target.checked)}
                        />
                        Licence checked by hand: active
                    </label>
                )}
                {a.seat && !a.brokerage_confirmed && (
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={brokerage}
                            onChange={(e) => setBrokerage(e.target.checked)}
                        />
                        Brokerage confirmed by phone or email
                    </label>
                )}
            </div>
            <Input
                aria-label="Note to the agent"
                placeholder="Note to the agent (needed to ask for changes)"
                value={note}
                onChange={(e) => setNote(e.target.value)}
            />
            <div className="flex flex-wrap gap-2">
                <Button size="sm" onClick={() => send(true)}>
                    Approve card
                </Button>
                <Button size="sm" variant="outline" onClick={() => send(false)}>
                    Ask for changes
                </Button>
                {a.status === 'active' && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            router.post(
                                `/hq/movewise/program/agents/${a.ulid}/status`,
                                { status: 'paused', reason: note },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Pause (give a reason)
                    </Button>
                )}
                {a.status === 'paused' && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            router.post(
                                `/hq/movewise/program/agents/${a.ulid}/status`,
                                { status: 'applying' },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Resume
                    </Button>
                )}
                {a.status !== 'removed' && (
                    <Button
                        size="sm"
                        variant="destructive"
                        onClick={() =>
                            router.post(
                                `/hq/movewise/program/agents/${a.ulid}/status`,
                                { status: 'removed', reason: note },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Remove (give a reason)
                    </Button>
                )}
            </div>
        </div>
    );
}

export default function HqMovewiseProgram({
    models,
    states,
    agents,
    seats,
    refunds,
    introductions,
    relays,
}: Props) {
    const [declineNote, setDeclineNote] = useState('');
    const relayForm = useForm({ number: '', area: '' });

    return (
        <>
            <Head title="Movewise agents" />
            <div className="grid gap-6 p-4">
                <PageHeader
                    title="Movewise agents"
                    description="Prices per state, agent reviews and free Movewise Agent seats."
                />
                <p className="text-sm text-muted-foreground">
                    Introductions: {introductions.offered} waiting now,{' '}
                    {introductions.accepted_30d} accepted and{' '}
                    {introductions.expired_30d} not answered in the last 30
                    days.
                </p>
                {refunds.length > 0 && (
                    <section className="grid gap-3">
                        <h2 className="text-lg font-semibold">
                            Refund requests
                        </h2>
                        {refunds.map((r) => (
                            <div
                                key={r.ulid}
                                className="flex flex-wrap items-center gap-2 rounded-xl border p-3 text-sm"
                            >
                                <strong>{r.agent}</strong>
                                <span className="text-muted-foreground">
                                    {r.account} · {r.credits} credits ·{' '}
                                    {r.method} · {r.reason}
                                </span>
                                <span className="grow" />
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            `/hq/movewise/program/refunds/${r.ulid}`,
                                            { approve: true },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Refund
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            `/hq/movewise/program/refunds/${r.ulid}`,
                                            { approve: false },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Decline
                                </Button>
                            </div>
                        ))}
                    </section>
                )}
                <section className="grid gap-2 text-sm">
                    <h2 className="text-lg font-semibold">Relay numbers</h2>
                    <p className="text-muted-foreground">
                        Twilio numbers in HQ's account, with their texts webhook
                        set to RightAlly. Each introduction texts through one.
                    </p>
                    {relays.map((r) => (
                        <div
                            key={r.id}
                            className="flex flex-wrap items-center gap-2 rounded-xl border p-2"
                        >
                            <strong>{r.number}</strong>
                            <span className="text-muted-foreground">
                                {r.area ?? ''} · {r.in_use} in use
                            </span>
                            <Badge variant={r.active ? 'default' : 'outline'}>
                                {r.active ? 'Active' : 'Off'}
                            </Badge>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        '/hq/movewise/program/relays',
                                        { id: r.id, active: !r.active },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {r.active ? 'Turn off' : 'Turn on'}
                            </Button>
                        </div>
                    ))}
                    <form
                        className="flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            relayForm.post('/hq/movewise/program/relays', {
                                preserveScroll: true,
                                onSuccess: () => relayForm.reset(),
                            });
                        }}
                    >
                        <Input
                            aria-label="Number"
                            placeholder="+1 713 555 0100"
                            className="w-48"
                            value={relayForm.data.number}
                            onChange={(e) =>
                                relayForm.setData('number', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Area"
                            placeholder="Houston"
                            className="w-40"
                            value={relayForm.data.area}
                            onChange={(e) =>
                                relayForm.setData('area', e.target.value)
                            }
                        />
                        <Button size="sm" disabled={relayForm.processing}>
                            Add number
                        </Button>
                        {relayForm.errors.number && (
                            <span className="text-destructive">
                                {relayForm.errors.number}
                            </span>
                        )}
                    </form>
                </section>
                <section className="grid gap-3">
                    <h2 className="text-lg font-semibold">Prices</h2>
                    {states.map((s) => (
                        <PricingForm key={s.code} s={s} models={models} />
                    ))}
                </section>
                <section className="grid gap-3">
                    <h2 className="text-lg font-semibold">Agents</h2>
                    {agents.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No applications yet.
                        </p>
                    )}
                    {agents.map((a) => (
                        <Review key={a.ulid} a={a} />
                    ))}
                </section>
                <section className="grid gap-3">
                    <h2 className="text-lg font-semibold">
                        Movewise Agent seat requests
                    </h2>
                    {seats.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No requests yet.
                        </p>
                    )}
                    {seats.map((r) => (
                        <div
                            key={r.ulid}
                            className="flex flex-wrap items-center gap-2 rounded-xl border p-3 text-sm"
                        >
                            <strong>{r.name}</strong>
                            <span className="text-muted-foreground">
                                {r.email} · {r.state} licence {r.licence_number}{' '}
                                ({r.licence_status}) · {r.brokerage_name}
                            </span>
                            <Badge variant="outline">{r.status}</Badge>
                            {r.note && (
                                <span className="text-muted-foreground">
                                    {r.note}
                                </span>
                            )}
                            <span className="grow" />
                            {r.status === 'pending' && (
                                <>
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                `/hq/movewise/program/seats/${r.ulid}`,
                                                { approve: true },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Approve
                                    </Button>
                                    <Input
                                        aria-label="Reason"
                                        className="w-48"
                                        placeholder="Reason"
                                        value={declineNote}
                                        onChange={(e) =>
                                            setDeclineNote(e.target.value)
                                        }
                                    />
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                `/hq/movewise/program/seats/${r.ulid}`,
                                                {
                                                    approve: false,
                                                    note: declineNote,
                                                },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Decline
                                    </Button>
                                </>
                            )}
                        </div>
                    ))}
                </section>
            </div>
        </>
    );
}
