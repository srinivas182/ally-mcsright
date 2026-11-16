import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Partner = {
    ulid: string;
    name: string;
    broker_name: string;
    licence_number: string;
    licence_state: string;
    phone: string | null;
    email: string | null;
    address: string | null;
    iabs_url: string | null;
    contract_signed_on: string | null;
    status: 'active' | 'inactive';
};

type StateRow = {
    code: string;
    name: string;
    on: boolean;
    partner: string | null;
    checklist: Record<string, { done: boolean; note?: string | null }>;
    missing: string[];
    feeds: { ulid: string; name: string; status: string | null }[];
};

const blank = {
    name: '',
    broker_name: '',
    licence_number: '',
    licence_state: 'TX',
    phone: '',
    email: '',
    address: '',
    iabs_url: '',
    contract_signed_on: '',
    status: 'active',
};

function PartnerForm({
    partner,
    onDone,
}: {
    partner: Partner | null;
    onDone: () => void;
}) {
    const form = useForm(
        partner
            ? {
                  ...blank,
                  ...Object.fromEntries(
                      Object.entries(partner).map(([k, v]) => [k, v ?? '']),
                  ),
              }
            : blank,
    );
    const field = (k: keyof typeof blank, label: string, type = 'text') => (
        <label className="grid gap-1 text-sm">
            {label}
            <Input
                type={type}
                value={String(form.data[k] ?? '')}
                onChange={(e) => form.setData(k, e.target.value)}
            />
            {form.errors[k] && (
                <span className="text-destructive">{form.errors[k]}</span>
            )}
        </label>
    );

    return (
        <form
            className="grid gap-3 rounded-xl border p-4 sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(
                    partner
                        ? `/hq/movewise/partners/${partner.ulid}`
                        : '/hq/movewise/partners',
                    {
                        preserveScroll: true,
                        onSuccess: onDone,
                    },
                );
            }}
        >
            {field('name', 'Brokerage (licensed name)')}
            {field('broker_name', 'Managing broker')}
            {field('licence_number', 'Brokerage licence number')}
            <label className="grid gap-1 text-sm">
                Licensed in
                <select
                    className="h-10 rounded-md border bg-background px-2"
                    value={form.data.licence_state}
                    onChange={(e) =>
                        form.setData('licence_state', e.target.value)
                    }
                >
                    {['TX', 'LA', 'MS', 'AL'].map((s) => (
                        <option key={s}>{s}</option>
                    ))}
                </select>
            </label>
            {field('phone', 'Phone')}
            {field('email', 'Email', 'email')}
            {field('address', 'Office address')}
            {field('iabs_url', 'Texas IABS form link (https://)')}
            {field('contract_signed_on', 'Partner contract signed on', 'date')}
            <label className="grid gap-1 text-sm">
                Status
                <select
                    className="h-10 rounded-md border bg-background px-2"
                    value={form.data.status}
                    onChange={(e) => form.setData('status', e.target.value)}
                >
                    <option value="active">Active</option>
                    <option value="inactive">
                        Inactive (switches its states off)
                    </option>
                </select>
            </label>
            <div className="sm:col-span-2">
                <Button disabled={form.processing}>
                    {partner ? 'Save partner' : 'Add partner'}
                </Button>
            </div>
        </form>
    );
}

function StateCard({
    s,
    partners,
    checklist,
}: {
    s: StateRow;
    partners: Partner[];
    checklist: Record<string, string>;
}) {
    const [mls, setMls] = useState(s.feeds[0]?.ulid ?? '');
    const [brokerId, setBrokerId] = useState('');
    const post = (path: string, data: Record<string, string | boolean>) =>
        router.post(`/hq/movewise/states/${s.code}/${path}`, data, {
            preserveScroll: true,
        });

    return (
        <section className="grid gap-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-lg font-semibold">{s.name}</h2>
                <div className="flex items-center gap-2">
                    <Badge variant={s.on ? 'default' : 'outline'}>
                        {s.on ? 'Live' : 'Off'}
                    </Badge>
                    <Button
                        size="sm"
                        variant={s.on ? 'outline' : 'default'}
                        disabled={!s.on && s.missing.length > 0}
                        onClick={() => post('switch', { on: !s.on })}
                    >
                        {s.on ? 'Switch off' : 'Switch on'}
                    </Button>
                </div>
            </div>
            {!s.on && s.missing.length > 0 && (
                <p className="text-sm text-muted-foreground">
                    Before it can go live: {s.missing.join('; ')}.
                </p>
            )}
            <label className="grid gap-1 text-sm">
                Managing broker partner
                <select
                    className="h-10 rounded-md border bg-background px-2"
                    value={s.partner ?? ''}
                    onChange={(e) =>
                        post('partner', { partner: e.target.value })
                    }
                >
                    <option value="">None</option>
                    {partners
                        .filter((p) => p.licence_state === s.code)
                        .map((p) => (
                            <option key={p.ulid} value={p.ulid}>
                                {p.name}
                            </option>
                        ))}
                </select>
            </label>
            <fieldset className="grid gap-1 text-sm">
                <legend className="mb-1 font-medium">Legal checklist</legend>
                {Object.entries(checklist).map(([k, label]) => (
                    <label key={k} className="flex items-start gap-2">
                        <input
                            type="checkbox"
                            className="mt-1 size-4"
                            checked={s.checklist[k]?.done === true}
                            onChange={(e) =>
                                post('checklist', {
                                    item: k,
                                    done: e.target.checked,
                                })
                            }
                        />
                        <span>{label}</span>
                    </label>
                ))}
            </fieldset>
            <div className="grid gap-2 text-sm">
                <span className="font-medium">MLS feeds</span>
                {s.feeds.length === 0 && (
                    <span className="text-muted-foreground">
                        No MLS set up for this state yet (HQ → MLS).
                    </span>
                )}
                {s.feeds.map((f) => (
                    <span key={f.ulid}>
                        {f.name}: {f.status ?? 'not requested'}
                    </span>
                ))}
                {s.feeds.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        <select
                            className="h-10 rounded-md border bg-background px-2"
                            value={mls}
                            onChange={(e) => setMls(e.target.value)}
                        >
                            {s.feeds.map((f) => (
                                <option key={f.ulid} value={f.ulid}>
                                    {f.name}
                                </option>
                            ))}
                        </select>
                        <Input
                            className="w-48"
                            placeholder="Partner's broker MLS ID"
                            value={brokerId}
                            onChange={(e) => setBrokerId(e.target.value)}
                        />
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                post('feeds', { mls, broker_mls_id: brokerId })
                            }
                        >
                            Request feed
                        </Button>
                    </div>
                )}
            </div>
        </section>
    );
}

/**
 * HQ → Movewise (specs/movewise/01): each launch state's switch, its managing broker partner, the legal checklist
 * that gates it, and the MLS feeds Movewise shows there.
 */
export default function HqMovewise({
    host,
    checklist,
    partners,
    states,
    feed_account,
}: {
    host: string;
    checklist: Record<string, string>;
    partners: Partner[];
    states: StateRow[];
    feed_account: string | null;
}) {
    const [editing, setEditing] = useState<string | null>(null);

    return (
        <>
            <Head title="Movewise" />
            <div className="grid max-w-5xl gap-6 p-4">
                <PageHeader
                    title="Movewise Homes"
                    description={`HQ's consumer site at ${host}. Each state goes live only with a broker of record, a complete legal checklist and a live MLS feed.`}
                />
                {feed_account === null && (
                    <p className="text-destructive">
                        There's no HQ account to hold Movewise's MLS feeds.
                    </p>
                )}
                <div className="grid gap-4 md:grid-cols-2">
                    {states.map((s) => (
                        <StateCard
                            key={s.code}
                            s={s}
                            partners={partners}
                            checklist={checklist}
                        />
                    ))}
                </div>
                <section className="grid gap-3">
                    <h2 className="text-lg font-semibold">
                        Managing broker partners
                    </h2>
                    {partners.map((p) =>
                        editing === p.ulid ? (
                            <PartnerForm
                                key={p.ulid}
                                partner={p}
                                onDone={() => setEditing(null)}
                            />
                        ) : (
                            <div
                                key={p.ulid}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-xl border p-3 text-sm"
                            >
                                <span>
                                    <strong>{p.name}</strong> ({p.licence_state}{' '}
                                    {p.licence_number}), {p.broker_name}
                                    {p.contract_signed_on
                                        ? ` · signed ${p.contract_signed_on}`
                                        : ' · contract not signed'}
                                </span>
                                <span className="flex items-center gap-2">
                                    <Badge variant="outline">{p.status}</Badge>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setEditing(p.ulid)}
                                    >
                                        Edit
                                    </Button>
                                </span>
                            </div>
                        ),
                    )}
                    {editing === 'new' ? (
                        <PartnerForm
                            partner={null}
                            onDone={() => setEditing(null)}
                        />
                    ) : (
                        <div>
                            <Button
                                variant="outline"
                                onClick={() => setEditing('new')}
                            >
                                Add a partner
                            </Button>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}
