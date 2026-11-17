import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { Textarea } from '@/components/ui/textarea';

type Stage = { key: string; label: string };
type Recruit = {
    ulid: string;
    name: string;
    brokerage: string | null;
    stage: string;
    source: string;
    next_step: string | null;
    next_step_on: string | null;
    overdue: boolean;
    email: string | null;
    phone: string | null;
    owner_user_id: number | null;
    current_split: number | null;
    current_cap: number | null;
    current_fee: number | null;
    not_now_reason: string | null;
};
type Production = {
    sides: number;
    list_sides: number;
    buyer_sides: number;
    volume_cents: number;
    sides_before: number;
    trend: number | null;
    active_listings: number;
    office: string | null;
    last_closed_on: string | null;
    moved_on: string | null;
    previous_office: string | null;
};
type Earn = {
    plan: string | null;
    plans: { id: number; name: string }[];
    deals: {
        date: string;
        side: string;
        price_cents: number;
        gci_cents: number;
        there_cents: number;
        here_cents: number;
    }[];
    there_cents: number;
    here_cents: number;
    difference_cents: number;
    assumptions: string[];
    problem: string | null;
};
type Note = { kind: string; body: string; at: string; by: string | null };

const usd = (cents: number) =>
    (cents / 100).toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 0,
    });
const kindLabel: Record<string, string> = {
    note: 'Note',
    call: 'Call',
    email: 'Email',
    meeting: 'Meeting',
    stage: 'Stage',
    alert: 'Alert',
};

/** One recruit (RC1): stage, next step, their production, "what you'd earn here", details and history. */
export default function RecruitPage({
    recruit,
    production,
    notes,
    stages,
    recruiters,
    earn,
    manual,
}: {
    recruit: Recruit;
    production: Production | null;
    notes: Note[];
    stages: Stage[];
    recruiters: { id: number; name: string }[];
    earn: Earn;
    manual: { deals: number | null; price: number | null };
}) {
    const opts = { preserveScroll: true };
    const [reason, setReason] = useState('');
    const log = useForm({
        kind: 'call',
        body: '',
        next_step: recruit.next_step ?? '',
        next_step_on: recruit.next_step_on ?? '',
    });
    const details = useForm({
        name: recruit.name,
        email: recruit.email ?? '',
        phone: recruit.phone ?? '',
        brokerage: recruit.brokerage ?? '',
        current_split: recruit.current_split ?? '',
        current_cap: recruit.current_cap ?? '',
        current_fee: recruit.current_fee ?? '',
        owner_user_id: recruit.owner_user_id ?? '',
    });
    const [deals, setDeals] = useState(String(manual.deals ?? ''));
    const [price, setPrice] = useState(String(manual.price ?? ''));
    const open = ['prospect', 'talking', 'meeting', 'offer'].includes(
        recruit.stage,
    );

    return (
        <>
            <Head title={recruit.name} />
            <div className="grid gap-5 p-4 text-base">
                <PageHeader
                    title={recruit.name}
                    description={
                        [recruit.brokerage, recruit.phone, recruit.email]
                            .filter(Boolean)
                            .join(' · ') || 'No contact details yet.'
                    }
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/recruiting">Back to recruiting</Link>
                        </Button>
                    }
                />

                <section className="grid gap-2" aria-labelledby="stage">
                    <h2 id="stage" className="sr-only">
                        Stage
                    </h2>
                    <div
                        className="flex flex-wrap gap-2"
                        role="group"
                        aria-label="Stage"
                    >
                        {stages.map((s) => (
                            <Button
                                key={s.key}
                                variant={
                                    s.key === recruit.stage
                                        ? 'default'
                                        : 'outline'
                                }
                                aria-pressed={s.key === recruit.stage}
                                onClick={() =>
                                    router.post(
                                        `/recruiting/${recruit.ulid}/stage`,
                                        { stage: s.key, reason },
                                        opts,
                                    )
                                }
                            >
                                {s.label}
                            </Button>
                        ))}
                    </div>
                    {open && (
                        <label className="grid max-w-md gap-1 text-sm text-muted-foreground">
                            If “Not now”, why (optional)
                            <Input
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                            />
                        </label>
                    )}
                    {recruit.not_now_reason && (
                        <p className="text-sm text-muted-foreground">
                            Not now: {recruit.not_now_reason}
                        </p>
                    )}
                </section>

                <form
                    className="grid gap-3 rounded-lg border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        log.post(`/recruiting/${recruit.ulid}/note`, {
                            ...opts,
                            onSuccess: () => log.setData('body', ''),
                        });
                    }}
                >
                    <h2 className="font-semibold">Log what happened</h2>
                    <div
                        className="flex flex-wrap gap-2"
                        role="radiogroup"
                        aria-label="What it was"
                    >
                        {['call', 'email', 'meeting', 'note'].map((k) => (
                            <Button
                                key={k}
                                type="button"
                                variant={
                                    log.data.kind === k ? 'default' : 'outline'
                                }
                                aria-pressed={log.data.kind === k}
                                onClick={() => log.setData('kind', k)}
                            >
                                {kindLabel[k]}
                            </Button>
                        ))}
                    </div>
                    <Textarea
                        aria-label="What was said"
                        placeholder="What was said"
                        value={log.data.body}
                        onChange={(e) => log.setData('body', e.target.value)}
                    />
                    {open && (
                        <div className="flex flex-wrap gap-3">
                            <label className="grid gap-1 text-sm">
                                Next step
                                <Input
                                    className="w-72"
                                    value={log.data.next_step}
                                    onChange={(e) =>
                                        log.setData('next_step', e.target.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                By
                                <Input
                                    type="date"
                                    className="w-44"
                                    value={log.data.next_step_on}
                                    onChange={(e) =>
                                        log.setData(
                                            'next_step_on',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                        </div>
                    )}
                    <Button
                        className="justify-self-start"
                        disabled={log.processing}
                    >
                        Save
                    </Button>
                </form>

                {production && (
                    <section
                        className="grid gap-2 rounded-lg border p-4"
                        aria-labelledby="production"
                    >
                        <h2 id="production" className="font-semibold">
                            Last 12 months in the MLS
                        </h2>
                        <p>
                            <strong className="text-lg">
                                {production.sides} closed sides
                            </strong>{' '}
                            ({production.list_sides} listing,{' '}
                            {production.buyer_sides} buyer),{' '}
                            {usd(production.volume_cents)} volume
                            {production.trend !== null &&
                                `, ${production.trend >= 0 ? 'up' : 'down'} ${Math.abs(production.trend)}% on the year before (${production.sides_before})`}
                            .
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {production.office ?? 'Office not known'}
                            {production.active_listings > 0 &&
                                ` · ${production.active_listings} active listing(s)`}
                            {production.last_closed_on &&
                                ` · last closing ${production.last_closed_on}`}
                            {production.moved_on &&
                                ` · moved ${production.moved_on}${production.previous_office ? ` from ${production.previous_office}` : ''}`}
                        </p>
                    </section>
                )}

                <section
                    className="grid gap-3 rounded-lg border p-4"
                    aria-labelledby="earn"
                >
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <h2 id="earn" className="font-semibold">
                            What they’d earn here
                        </h2>
                        {earn.plans.length > 1 && (
                            <label className="grid gap-1 text-sm">
                                Plan
                                <select
                                    className="h-10 rounded-md border bg-background px-3"
                                    value={
                                        earn.plans.find(
                                            (p) => p.name === earn.plan,
                                        )?.id ?? ''
                                    }
                                    onChange={(e) =>
                                        router.post(
                                            `/recruiting/${recruit.ulid}/plan`,
                                            { plan_id: e.target.value || null },
                                            opts,
                                        )
                                    }
                                >
                                    {earn.plans.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                    </div>
                    {!production && (
                        <form
                            className="flex flex-wrap items-end gap-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                router.get(
                                    `/recruiting/${recruit.ulid}`,
                                    { deals, price },
                                    opts,
                                );
                            }}
                        >
                            <label className="grid gap-1 text-sm">
                                Deals a year
                                <Input
                                    className="w-24"
                                    type="number"
                                    min={1}
                                    max={100}
                                    value={deals}
                                    onChange={(e) => setDeals(e.target.value)}
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                Average price ($)
                                <Input
                                    className="w-36"
                                    type="number"
                                    min={1000}
                                    value={price}
                                    onChange={(e) => setPrice(e.target.value)}
                                />
                            </label>
                            <Button variant="outline">Work it out</Button>
                        </form>
                    )}
                    {earn.problem ? (
                        <p className="text-muted-foreground">{earn.problem}</p>
                    ) : (
                        <>
                            <div className="grid gap-3 sm:grid-cols-3">
                                <div className="rounded border p-3">
                                    <p className="text-sm text-muted-foreground">
                                        Where they are
                                    </p>
                                    <p className="text-lg font-semibold">
                                        {usd(earn.there_cents)}
                                    </p>
                                </div>
                                <div className="rounded border p-3">
                                    <p className="text-sm text-muted-foreground">
                                        Here ({earn.plan})
                                    </p>
                                    <p className="text-lg font-semibold">
                                        {usd(earn.here_cents)}
                                    </p>
                                </div>
                                <div
                                    className={`rounded border-2 p-3 ${earn.difference_cents >= 0 ? 'border-emerald-500' : 'border-amber-400'}`}
                                >
                                    <p className="text-sm text-muted-foreground">
                                        Difference a year
                                    </p>
                                    <p className="text-lg font-semibold">
                                        {earn.difference_cents >= 0 ? '+' : '−'}
                                        {usd(Math.abs(earn.difference_cents))}
                                    </p>
                                </div>
                            </div>
                            <ul className="list-disc pl-5 text-sm text-muted-foreground">
                                {earn.assumptions.map((a) => (
                                    <li key={a}>{a}</li>
                                ))}
                            </ul>
                            <details>
                                <summary className="cursor-pointer text-sm">
                                    Deal by deal ({earn.deals.length})
                                </summary>
                                <div className="overflow-x-auto">
                                    <table className="mt-2 w-full text-left text-sm">
                                        <thead className="text-muted-foreground">
                                            <tr>
                                                <th className="py-1 font-normal">
                                                    Closed
                                                </th>
                                                <th className="py-1 font-normal">
                                                    Side
                                                </th>
                                                <th className="py-1 font-normal">
                                                    Price
                                                </th>
                                                <th className="py-1 font-normal">
                                                    Commission
                                                </th>
                                                <th className="py-1 font-normal">
                                                    There
                                                </th>
                                                <th className="py-1 font-normal">
                                                    Here
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {earn.deals.map((d, i) => (
                                                <tr
                                                    key={i}
                                                    className="border-t"
                                                >
                                                    <td className="py-1">
                                                        {d.date}
                                                    </td>
                                                    <td className="py-1">
                                                        {d.side}
                                                    </td>
                                                    <td className="py-1">
                                                        {usd(d.price_cents)}
                                                    </td>
                                                    <td className="py-1">
                                                        {usd(d.gci_cents)}
                                                    </td>
                                                    <td className="py-1">
                                                        {usd(d.there_cents)}
                                                    </td>
                                                    <td className="py-1">
                                                        {usd(d.here_cents)}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        </>
                    )}
                </section>

                <form
                    className="grid gap-3 rounded-lg border p-4 sm:grid-cols-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        details.put(`/recruiting/${recruit.ulid}`, opts);
                    }}
                >
                    <h2 className="font-semibold sm:col-span-2">
                        Details and their plan today
                    </h2>
                    {(
                        [
                            ['name', 'Name', 'text'],
                            ['brokerage', 'Brokerage', 'text'],
                            ['email', 'Email', 'email'],
                            ['phone', 'Phone', 'tel'],
                            ['current_split', 'They keep (%)', 'number'],
                            [
                                'current_cap',
                                'Cap a year ($, empty if none)',
                                'number',
                            ],
                            ['current_fee', 'Fees a deal ($)', 'number'],
                        ] as const
                    ).map(([key, label, type]) => (
                        <label key={key} className="grid gap-1 text-sm">
                            {label}
                            <Input
                                type={type}
                                value={details.data[key]}
                                onChange={(e) =>
                                    details.setData(key, e.target.value)
                                }
                            />
                        </label>
                    ))}
                    {recruiters.length > 1 && (
                        <label className="grid gap-1 text-sm">
                            Recruiter
                            <select
                                className="h-10 rounded-md border bg-background px-3"
                                value={details.data.owner_user_id}
                                onChange={(e) =>
                                    details.setData(
                                        'owner_user_id',
                                        e.target.value,
                                    )
                                }
                            >
                                {recruiters.map((u) => (
                                    <option key={u.id} value={u.id}>
                                        {u.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    <div className="flex items-end">
                        <Button variant="outline" disabled={details.processing}>
                            Save details
                        </Button>
                    </div>
                    {Object.values(details.errors).map((m) => (
                        <p
                            key={m}
                            className="text-sm text-red-700 sm:col-span-2"
                        >
                            {m}
                        </p>
                    ))}
                </form>

                <section className="grid gap-2" aria-labelledby="history">
                    <h2 id="history" className="font-semibold">
                        History
                    </h2>
                    <ol className="grid gap-2">
                        {notes.map((n, i) => (
                            <li key={i} className="rounded border p-3 text-sm">
                                <span className="text-muted-foreground">
                                    {kindLabel[n.kind] ?? n.kind} · {n.at} ·{' '}
                                    {n.by ?? ''}
                                </span>
                                <p className="whitespace-pre-line">{n.body}</p>
                            </li>
                        ))}
                    </ol>
                </section>
            </div>
        </>
    );
}
