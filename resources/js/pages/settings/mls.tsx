import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { Switch } from '@/components/ui/switch';

type Source = {
    ulid: string;
    name: string;
    states: string[];
    minutes_behind: number | null;
    licence: {
        status: string;
        broker_mls_id: string;
        scopes: string[];
        expires_on: string | null;
        note: string | null;
    } | null;
    rules: {
        program: string;
        sold_allowed: boolean;
        sold_delay_days: number;
        attribution: string;
    } | null;
};

const statusText: Record<string, string> = {
    pending: 'Waiting for RightAlly to confirm with the MLS',
    active: 'Active: listings show on your websites',
    revoked: 'Access ended',
    expired: 'Licence expired: renew your IDX agreement',
};

type Board = {
    ulid: string;
    board: string;
    board_code: string;
    state: string;
    status: string;
    status_label: string;
    since: string | null;
    note: string | null;
    member_id: string | null;
    office_id: string | null;
    license_number: string | null;
    managing_broker_name: string | null;
    managing_broker_email: string | null;
    has_own_key: boolean;
    fee_cents: number | null;
    approval: string | null;
    agreement: string | null;
    has_copy: boolean;
    form?: FormRow[] | null;
    /** IDX13: who the agreement is waiting on. */
    signing: {
        names: string[];
        emails: string[];
        sent_at: string | null;
        mine: boolean;
    } | null;
    by_hand: boolean;
    overdue: boolean;
};
type Available = {
    ulid: string;
    code: string;
    name: string;
    state: string;
    region: string | null;
    fee_cents: number;
    approval: string;
};

/** IDX7: one board is included in the package; the others are added to the monthly invoice. */
type Fees = {
    included: string | null;
    included_ulid: string | null;
    extra_cents: number;
    extras: { name: string; fee_cents: number }[];
};

/** IDX12: one field of the board's own agreement form, filled in for the brokerage to check. */
type FormRow = {
    key: string;
    label: string;
    type: 'text' | 'date' | 'checkbox';
    value: string;
    required: boolean;
    filled_by_us: boolean;
};

/** IDX8: a board the brokerage asked RightAlly to add. */
type BoardRequest = {
    ulid: string;
    name: string;
    state: string;
    status: 'open' | 'added' | 'closed';
    hq_note: string | null;
    asked: string;
};

const REQUEST_STATUS: Record<BoardRequest['status'], string> = {
    open: 'We are looking into it',
    added: 'Being set up; we will email you when it is ready',
    closed: 'Not possible yet',
};

/** The steps every board goes through, in order (IDX4). */
const STEPS: { key: string; label: string }[] = [
    { key: 'demo', label: 'Your details' },
    { key: 'review', label: 'Check the form' },
    { key: 'awaiting_signature', label: 'Signatures' },
    { key: 'hq_check', label: 'RightAlly check' },
    { key: 'sent', label: 'Sent to the MLS' },
    { key: 'mls_review', label: 'MLS review' },
    { key: 'live', label: 'Live' },
];

function money(cents: number | null): string {
    return cents === null ? '' : `$${(cents / 100).toLocaleString('en-US')}`;
}

/** Settings → MLS: the brokerage's MLS boards, each on its own track, and the boards it can add. */
export default function MlsSettings({
    sources,
    boards = [],
    available = [],
    demo = { on: true, showing: false },
    fees = { included: null, included_ulid: null, extra_cents: 0, extras: [] },
    requests = [],
    all_states = {},
}: {
    requests?: BoardRequest[];
    all_states?: Record<string, string>;
    sources: Source[];
    boards?: Board[];
    available?: Available[];
    demo?: { on: boolean; showing: boolean };
    fees?: Fees;
}) {
    const [adding, setAdding] = useState('');
    const current = boards.filter((b) => b.status !== 'cancelled');

    return (
        <>
            <Head title="MLS" />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Your MLS boards"
                    description="Add the MLS boards you belong to. Your website shows demo listings straight away; each board's real listings appear the day its MLS approves, with the MLS's notices shown automatically."
                />
                {current.length === 0 && (
                    <p className="rounded-xl border bg-card p-5 text-base">
                        {available.length > 0
                            ? 'You have no MLS boards yet. Add the first one below.'
                            : 'You have no MLS boards yet, and none are offered in your states yet. Press "Ask for a board" and tell us its name; we add it and email you the day it is ready.'}
                    </p>
                )}
                {current.length > 0 && (
                    <p className="rounded-xl border bg-card p-5 text-base">
                        {fees.extras.length === 0
                            ? 'Your plan includes one MLS board, so your boards cost nothing extra.'
                            : `Your plan includes one MLS board (${fees.included}). The other ${fees.extras.length} add ${money(fees.extra_cents)} a month, on your invoice on the 1st.`}
                    </p>
                )}
                {current.map((b) => (
                    <BoardCard
                        key={b.ulid}
                        board={b}
                        included={b.ulid === fees.included_ulid}
                    />
                ))}

                {current.length > 0 &&
                    !current.some((b) => b.status === 'live') && (
                        <label className="flex items-start justify-between gap-4 rounded-xl border bg-card p-5">
                            <span>
                                <span className="block font-semibold">
                                    Show demo listings until my first board is
                                    live
                                </span>
                                <span className="block text-sm text-muted-foreground">
                                    {demo.showing
                                        ? 'Your website shows sample homes now, each marked “Sample listing”.'
                                        : 'Your website shows no homes until your first board is live.'}
                                </span>
                            </span>
                            <Switch
                                checked={demo.on}
                                onCheckedChange={(on) =>
                                    router.put(
                                        '/settings/mls/demo',
                                        { on },
                                        { preserveScroll: true },
                                    )
                                }
                            />
                        </label>
                    )}

                {available.length > 0 && (
                    <section className="grid gap-3 rounded-xl border bg-card p-5">
                        <h2 className="text-lg font-semibold">Add a board</h2>
                        <div className="flex flex-wrap items-end gap-3">
                            <label className="grid flex-1 gap-1 text-sm">
                                Board
                                <select
                                    className="h-11 rounded-md border bg-background px-3 text-base"
                                    value={adding}
                                    onChange={(e) => setAdding(e.target.value)}
                                >
                                    <option value="">Choose your board…</option>
                                    {available.map((a) => (
                                        <option key={a.ulid} value={a.ulid}>
                                            {a.name} ({a.state}
                                            {a.region ? `, ${a.region}` : ''}) ·{' '}
                                            {money(a.fee_cents)} a month ·
                                            approval {a.approval}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <Button
                                size="lg"
                                disabled={adding === ''}
                                onClick={() =>
                                    router.post(
                                        '/settings/mls/boards',
                                        { board: adding },
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => setAdding(''),
                                        },
                                    )
                                }
                            >
                                Add board
                            </Button>
                        </div>
                    </section>
                )}

                <RequestBoard requests={requests} states={all_states} />

                {sources.length > 0 && (
                    <section className="grid gap-3">
                        <h2 className="text-lg font-semibold">
                            Direct MLS connections
                        </h2>
                        <ul className="grid gap-3 text-sm">
                            {sources.map((s) => (
                                <SourceRow key={s.ulid} source={s} />
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

/** IDX8: "My board isn't listed": name the board and we email you when it is ready. */
function RequestBoard({
    requests,
    states,
}: {
    requests: BoardRequest[];
    states: Record<string, string>;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({ name: '', state: '', website: '', note: '' });

    return (
        <section className="grid gap-3 rounded-xl border bg-card p-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-lg font-semibold">
                        My board isn’t listed
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Tell us its name and we will add it. We email you the
                        day it is ready.
                    </p>
                </div>
                {!open && (
                    <Button variant="outline" onClick={() => setOpen(true)}>
                        Ask for a board
                    </Button>
                )}
            </div>
            {open && (
                <form
                    className="grid gap-3 md:grid-cols-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/settings/mls/requests', {
                            preserveScroll: true,
                            onSuccess: () => {
                                form.reset();
                                setOpen(false);
                            },
                        });
                    }}
                >
                    <FormField
                        label="The board's name"
                        error={form.errors.name}
                        required
                    >
                        {(control) => (
                            <Input
                                {...control}
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                        )}
                    </FormField>
                    <FormField label="State" error={form.errors.state} required>
                        {(control) => (
                            <select
                                {...control}
                                className="h-11 rounded-md border bg-background px-3 text-base"
                                value={form.data.state}
                                onChange={(e) =>
                                    form.setData('state', e.target.value)
                                }
                            >
                                <option value="">Choose…</option>
                                {Object.entries(states).map(([code, name]) => (
                                    <option key={code} value={code}>
                                        {name}
                                    </option>
                                ))}
                            </select>
                        )}
                    </FormField>
                    <FormField
                        label="Its website (optional)"
                        error={form.errors.website}
                    >
                        {(control) => (
                            <Input
                                {...control}
                                type="url"
                                placeholder="https://"
                                value={form.data.website}
                                onChange={(e) =>
                                    form.setData('website', e.target.value)
                                }
                            />
                        )}
                    </FormField>
                    <FormField
                        label="Anything else (optional)"
                        error={form.errors.note}
                    >
                        {(control) => (
                            <Input
                                {...control}
                                value={form.data.note}
                                onChange={(e) =>
                                    form.setData('note', e.target.value)
                                }
                            />
                        )}
                    </FormField>
                    <div className="flex gap-3 md:col-span-2">
                        <Button
                            size="lg"
                            type="submit"
                            disabled={form.processing}
                        >
                            Send
                        </Button>
                        <Button
                            size="lg"
                            type="button"
                            variant="ghost"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                    </div>
                </form>
            )}
            {requests.length > 0 && (
                <ul className="grid gap-2 text-sm">
                    {requests.map((r) => (
                        <li
                            key={r.ulid}
                            className="flex flex-wrap justify-between gap-2 rounded-lg border p-3"
                        >
                            <span>
                                {r.name} ({r.state}), asked {r.asked}
                            </span>
                            <span className="text-muted-foreground">
                                {REQUEST_STATUS[r.status]}
                                {r.hq_note ? `: ${r.hq_note}` : ''}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function BoardCard({
    board: b,
    included,
}: {
    board: Board;
    included: boolean;
}) {
    const [editing, setEditing] = useState(
        b.status === 'demo' || b.status === 'needs_fixing',
    );
    const form = useForm({
        member_id: b.member_id ?? '',
        office_id: b.office_id ?? '',
        license_number: b.license_number ?? '',
        managing_broker_name: b.managing_broker_name ?? '',
        managing_broker_email: b.managing_broker_email ?? '',
        own_key: '',
    });
    const at = STEPS.findIndex((s) => s.key === b.status);
    const canEdit = [
        'demo',
        'needs_fixing',
        'awaiting_signature',
        'review',
    ].includes(b.status);

    return (
        <section className="grid gap-4 rounded-xl border bg-card p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-lg font-semibold">{b.board}</h2>
                    <p className="text-sm text-muted-foreground">
                        {b.state}
                        {included
                            ? ' · Included in your plan'
                            : b.fee_cents !== null
                              ? ` · +${money(b.fee_cents)} a month on your invoice`
                              : ''}
                        {b.approval
                            ? ` · MLS approval usually ${b.approval}`
                            : ''}
                    </p>
                </div>
                <Badge
                    variant={
                        b.status === 'live'
                            ? 'default'
                            : b.status === 'needs_fixing'
                              ? 'destructive'
                              : 'secondary'
                    }
                >
                    {b.status_label}
                </Badge>
            </div>

            <ol className="grid grid-cols-5 gap-2 text-center text-xs">
                {STEPS.map((s, i) => {
                    const done = at >= 0 && i < at;
                    const now = i === at;
                    return (
                        <li key={s.key} className="grid gap-1">
                            <span
                                className={`h-1.5 rounded-full ${done || (now && b.status === 'live') ? 'bg-primary' : now ? 'bg-primary/50' : 'bg-muted'}`}
                            />
                            <span
                                className={
                                    now
                                        ? 'font-semibold'
                                        : 'text-muted-foreground'
                                }
                            >
                                {s.label}
                            </span>
                        </li>
                    );
                })}
            </ol>

            {b.status === 'needs_fixing' && b.note && (
                <p className="rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
                    What to fix: {b.note}
                </p>
            )}
            {b.status === 'review' && b.form && (
                <ReviewForm board={b} rows={b.form} />
            )}
            {b.status === 'awaiting_signature' && <Signing board={b} />}
            {b.overdue && (
                <p className="text-sm text-muted-foreground">
                    The MLS is taking a little longer than usual. RightAlly is
                    following it up for you; your demo listings keep showing
                    meanwhile.
                </p>
            )}

            {canEdit && !editing && (
                <div>
                    <Button variant="outline" onClick={() => setEditing(true)}>
                        Change my details
                    </Button>
                </div>
            )}
            {canEdit && editing && (
                <form
                    className="grid gap-4 sm:grid-cols-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(`/settings/mls/boards/${b.ulid}`, {
                            preserveScroll: true,
                            onSuccess: () => {
                                setEditing(false);
                                form.reset('own_key');
                            },
                        });
                    }}
                >
                    <FormField
                        label="Your MLS member ID"
                        error={form.errors.member_id}
                        required
                    >
                        {(control) => (
                            <Input
                                {...control}
                                value={form.data.member_id}
                                onChange={(e) =>
                                    form.setData('member_id', e.target.value)
                                }
                            />
                        )}
                    </FormField>
                    <FormField
                        label="Office ID (if you have one)"
                        error={form.errors.office_id}
                    >
                        {(control) => (
                            <Input
                                {...control}
                                value={form.data.office_id}
                                onChange={(e) =>
                                    form.setData('office_id', e.target.value)
                                }
                            />
                        )}
                    </FormField>
                    <FormField
                        label="Real estate licence number"
                        error={form.errors.license_number}
                        required
                    >
                        {(control) => (
                            <Input
                                {...control}
                                value={form.data.license_number}
                                onChange={(e) =>
                                    form.setData(
                                        'license_number',
                                        e.target.value,
                                    )
                                }
                            />
                        )}
                    </FormField>
                    <FormField
                        label="Managing broker's full name"
                        error={form.errors.managing_broker_name}
                        required
                    >
                        {(control) => (
                            <Input
                                {...control}
                                value={form.data.managing_broker_name}
                                onChange={(e) =>
                                    form.setData(
                                        'managing_broker_name',
                                        e.target.value,
                                    )
                                }
                            />
                        )}
                    </FormField>
                    <FormField
                        label="Managing broker's email"
                        help="Use the email your MLS has on file: the MLS may send a confirmation there."
                        error={form.errors.managing_broker_email}
                        required
                        className="sm:col-span-2"
                    >
                        {(control) => (
                            <Input
                                {...control}
                                type="email"
                                value={form.data.managing_broker_email}
                                onChange={(e) =>
                                    form.setData(
                                        'managing_broker_email',
                                        e.target.value,
                                    )
                                }
                            />
                        )}
                    </FormField>
                    <FormField
                        label="Already have your own IDX feed key from this MLS? (optional)"
                        help={
                            b.has_own_key
                                ? 'A key is saved. Paste a new one to replace it.'
                                : 'Paste it here and we will use it for your listings only. It is locked away and never shown again.'
                        }
                        error={form.errors.own_key}
                        className="sm:col-span-2"
                    >
                        {(control) => (
                            <Input
                                {...control}
                                type="password"
                                autoComplete="off"
                                value={form.data.own_key}
                                onChange={(e) =>
                                    form.setData('own_key', e.target.value)
                                }
                            />
                        )}
                    </FormField>
                    <div className="flex flex-wrap gap-2 sm:col-span-2">
                        <Button size="lg" disabled={form.processing}>
                            Send my details
                        </Button>
                        {b.status !== 'demo' && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setEditing(false)}
                            >
                                Cancel
                            </Button>
                        )}
                    </div>
                </form>
            )}

            <div>
                <Button
                    variant="ghost"
                    className="text-muted-foreground"
                    onClick={() =>
                        router.delete(`/settings/mls/boards/${b.ulid}`, {
                            preserveScroll: true,
                        })
                    }
                >
                    Remove this board
                </Button>
            </div>
        </section>
    );
}

function SourceRow({ source: s }: { source: Source }) {
    const form = useForm({
        broker_mls_id: s.licence?.broker_mls_id ?? '',
        office_mls_ids: '',
        agreement_ref: '',
    });
    const canRequest =
        s.licence === null || ['revoked', 'expired'].includes(s.licence.status);

    return (
        <li className="grid gap-2 rounded-lg border p-3">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">{s.name}</span>
                <span className="text-muted-foreground">
                    {s.states.join(', ')}
                </span>
                {s.licence && (
                    <Badge
                        variant={
                            s.licence.status === 'active'
                                ? 'outline'
                                : 'destructive'
                        }
                    >
                        {statusText[s.licence.status] ?? s.licence.status}
                    </Badge>
                )}
                {s.licence?.status === 'active' && (
                    <span className="ml-auto text-xs text-muted-foreground">
                        {s.minutes_behind === null
                            ? ''
                            : `Updated ${s.minutes_behind} min ago`}
                        {s.licence.expires_on
                            ? ` · licence to ${s.licence.expires_on}`
                            : ''}
                    </span>
                )}
            </div>
            {s.licence?.note && (
                <p className="text-muted-foreground">{s.licence.note}</p>
            )}
            {s.rules && (
                <p className="text-xs text-muted-foreground">
                    Rules: {s.rules.program.toUpperCase()} ·{' '}
                    {s.rules.sold_allowed
                        ? `sold listings after ${s.rules.sold_delay_days} days`
                        : 'no sold listings'}{' '}
                    · "{s.rules.attribution}"
                </p>
            )}
            {canRequest && (
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/settings/mls/${s.ulid}/request`, {
                            preserveScroll: true,
                        });
                    }}
                >
                    <Input
                        aria-label="Broker MLS ID"
                        placeholder="Broker MLS ID"
                        className="w-40"
                        value={form.data.broker_mls_id}
                        onChange={(e) =>
                            form.setData('broker_mls_id', e.target.value)
                        }
                    />
                    <Input
                        aria-label="Office MLS IDs"
                        placeholder="Office MLS IDs (optional)"
                        className="w-56"
                        value={form.data.office_mls_ids}
                        onChange={(e) =>
                            form.setData('office_mls_ids', e.target.value)
                        }
                    />
                    <Input
                        aria-label="IDX agreement reference"
                        placeholder="IDX agreement reference (optional)"
                        className="w-64"
                        value={form.data.agreement_ref}
                        onChange={(e) =>
                            form.setData('agreement_ref', e.target.value)
                        }
                    />
                    <Button size="sm" disabled={form.processing}>
                        Request access
                    </Button>
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

/**
 * IDX12: the board's own agreement, filled in. The brokerage checks what RightAlly filled in, completes the rest,
 * previews the form exactly as the MLS will see it, then sends it for signature.
 */
function ReviewForm({ board: b, rows }: { board: Board; rows: FormRow[] }) {
    const form = useForm<{ values: Record<string, string>; send: boolean }>({
        values: Object.fromEntries(rows.map((r) => [r.key, r.value])),
        send: false,
    });
    const [saved, setSaved] = useState(false);
    const set = (key: string, value: string) => {
        setSaved(false);
        form.setData('values', { ...form.data.values, [key]: value });
    };
    const ours = rows.filter((r) => r.filled_by_us);
    const theirs = rows.filter((r) => !r.filled_by_us);
    const field = (r: FormRow) =>
        r.type === 'checkbox' ? (
            <label key={r.key} className="flex items-center gap-3 text-base">
                <Switch
                    checked={['1', 'yes', 'x', 'true', 'on'].includes(
                        (form.data.values[r.key] ?? '').toLowerCase(),
                    )}
                    onCheckedChange={(on) => set(r.key, on ? 'Yes' : '')}
                />
                {r.label}
            </label>
        ) : (
            <FormField key={r.key} label={r.label} required={r.required}>
                {(control) => (
                    <Input
                        {...control}
                        className="h-11 text-base"
                        value={form.data.values[r.key] ?? ''}
                        onChange={(e) => set(r.key, e.target.value)}
                    />
                )}
            </FormField>
        );
    const save = (send: boolean, after?: () => void) => {
        form.transform((d) => ({ ...d, send }));
        form.put(`/settings/mls/boards/${b.ulid}/review`, {
            preserveScroll: true,
            onSuccess: () => after?.(),
        });
    };

    return (
        <section className="grid gap-4 rounded-lg border bg-muted/20 p-4">
            <div>
                <h3 className="text-base font-semibold">
                    Check your {b.board_code} agreement
                </h3>
                <p className="text-sm text-muted-foreground">
                    This is the MLS's own form. We filled in what we know;
                    please check it, complete anything empty, and send it for
                    signature.
                </p>
            </div>
            {ours.length > 0 && (
                <div className="grid gap-3 sm:grid-cols-2">
                    <p className="text-sm font-medium sm:col-span-2">
                        Filled in for you (change anything that is wrong)
                    </p>
                    {ours.map(field)}
                </div>
            )}
            {theirs.length > 0 && (
                <div className="grid gap-3 sm:grid-cols-2">
                    <p className="text-sm font-medium sm:col-span-2">
                        Please complete
                    </p>
                    {theirs.map(field)}
                </div>
            )}
            {(form.errors as Record<string, string>).values && (
                <p className="text-sm text-destructive">
                    {(form.errors as Record<string, string>).values}
                </p>
            )}
            <div className="flex flex-wrap gap-3">
                <Button
                    size="lg"
                    variant="outline"
                    disabled={form.processing}
                    onClick={() => save(false, () => setSaved(true))}
                >
                    Save
                </Button>
                {saved && (
                    <Button size="lg" variant="outline" asChild>
                        <a
                            href={`/settings/mls/boards/${b.ulid}/preview.pdf`}
                            target="_blank"
                            rel="noreferrer"
                        >
                            Open the preview (PDF)
                        </a>
                    </Button>
                )}
                <Button
                    size="lg"
                    disabled={form.processing}
                    onClick={() => save(true)}
                >
                    Looks right: send for signature
                </Button>
            </div>
        </section>
    );
}

/** IDX13: who the agreement is waiting on, with a way to sign here or get the email again. */
function Signing({ board: b }: { board: Board }) {
    const [busy, setBusy] = useState(false);
    const problem = (usePage().props.errors as Record<string, string>).signing;
    const post = (path: string) => {
        setBusy(true);
        router.post(
            `/settings/mls/boards/${b.ulid}/${path}`,
            {},
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    if (!b.signing) {
        return (
            <div className="grid gap-2 text-sm text-muted-foreground">
                <p>
                    {b.by_hand
                        ? 'RightAlly is preparing this agreement with the MLS. We will email you when there is something to sign.'
                        : 'The agreement is not out for signature yet.'}
                </p>
                {!b.by_hand && (
                    <div>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => post('resend')}
                        >
                            Send me the agreement to sign
                        </Button>
                    </div>
                )}
                {problem && (
                    <p className="text-destructive" role="alert">
                        {problem}
                    </p>
                )}
            </div>
        );
    }
    const when = b.signing.sent_at
        ? new Date(b.signing.sent_at).toLocaleDateString(undefined, {
              day: 'numeric',
              month: 'short',
          })
        : null;

    return (
        <div className="grid gap-3 rounded-lg border bg-muted/40 p-4">
            <p className="text-base">
                {b.signing.mine
                    ? 'Your MLS agreement is ready for you to sign.'
                    : `Waiting for ${b.signing.names.join(' and ')} to sign.`}
            </p>
            <p className="text-sm text-muted-foreground">
                {when
                    ? `We emailed the signing link to ${b.signing.emails.join(', ')} on ${when}.`
                    : `The signing link goes to ${b.signing.emails.join(', ')}.`}{' '}
                Can&apos;t find it? Check the spam folder, or send it again.
            </p>
            <div className="flex flex-wrap gap-2">
                {b.signing.mine && (
                    <Button disabled={busy} onClick={() => post('sign')}>
                        Sign now
                    </Button>
                )}
                <Button
                    variant="outline"
                    disabled={busy}
                    onClick={() => post('resend')}
                >
                    Email the link again
                </Button>
            </div>
            {problem && (
                <p className="text-sm text-destructive" role="alert">
                    {problem}
                </p>
            )}
        </div>
    );
}
