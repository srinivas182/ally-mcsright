import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

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

/** The steps every board goes through, in order (IDX4). */
const STEPS: { key: string; label: string }[] = [
    { key: 'demo', label: 'Your details' },
    { key: 'awaiting_signature', label: 'Signatures' },
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
}: {
    sources: Source[];
    boards?: Board[];
    available?: Available[];
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
                        You have no MLS boards yet. Add the first one below.
                    </p>
                )}
                {current.map((b) => (
                    <BoardCard key={b.ulid} board={b} />
                ))}

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
                        <p className="text-sm text-muted-foreground">
                            Your board is not listed? Tell RightAlly support and
                            we will add it.
                        </p>
                    </section>
                )}

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

function BoardCard({ board: b }: { board: Board }) {
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
    const canEdit = ['demo', 'needs_fixing', 'awaiting_signature'].includes(
        b.status,
    );

    return (
        <section className="grid gap-4 rounded-xl border bg-card p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-lg font-semibold">{b.board}</h2>
                    <p className="text-sm text-muted-foreground">
                        {b.state}
                        {b.fee_cents !== null
                            ? ` · ${money(b.fee_cents)} a month`
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
            {b.status === 'awaiting_signature' && (
                <p className="text-sm text-muted-foreground">
                    The IDX agreement is being prepared for signing. We will
                    email you and your managing broker when it is ready, and
                    remind you if it waits.
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
