import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/number-inputs';
import { PageHeader } from '@/components/ui/page-header';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';

type State = {
    code: string;
    name: string;
    enabled: boolean;
    boards: number;
    public: number;
};
type Connector = {
    key: string;
    name: string;
    kind: string;
    status: string;
    margin_percent: number;
    notes: string | null;
};
type Board = {
    ulid: string;
    code: string;
    name: string;
    state: string;
    other_states: string[];
    region: string | null;
    visibility: string;
    approval_days_min: number;
    approval_days_max: number;
    main_connector: string | null;
    main_cost_cents: number | null;
    backup_connector: string | null;
    backup_cost_cents: number | null;
    connector_code: string | null;
    monthly_fee_cents: number | null;
    fee_cents: number | null;
    setup_fee_cents: number;
    rules_profile_id: number | null;
    mls_source_id: number | null;
    checked: boolean;
    notes: string | null;
    ready: boolean;
    has_agreement_pdf: boolean;
    agreement_text: string | null;
    managing_broker_signs: boolean;
    send_to: string | null;
    send_cc: string | null;
    send_bcc: string | null;
    agreement_url?: string | null;
    agreement_version?: number;
    form_update?: boolean;
    form_watch_note?: string | null;
    form_confirmed?: string | null;
    form_due?: boolean;
};
type Option = { id: number; name: string };
type QueueItem = {
    ulid: string;
    brokerage: string;
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
    connector: string | null;
    feed_id: string | null;
    mls_source_id: number | null;
    reminders: number;
    agreement: string | null;
    has_copy: boolean;
    website: string | null;
    compliance_ok: boolean | null;
    compliance_note: string | null;
    check?: Check | null;
    form_version?: number | null;
};
/** IDX11: the check of a signed agreement (rules, then AI). */
type Check = {
    result: 'clear' | 'issues';
    source: 'ai' | 'rules';
    issues: { text: string; for: 'brokerage' | 'hq' }[];
    note: string | null;
    at: string;
};
type Props = {
    states: State[];
    boards: Board[];
    connectors: Connector[];
    profiles: Option[];
    sources: Option[];
    state: string | null;
    queue: QueueItem[];
    statuses: Record<string, string>;
    requests?: RequestItem[];
};

/** IDX8: a board a brokerage asked us to add. */
type RequestItem = {
    ulid: string;
    name: string;
    state: string;
    website: string | null;
    note: string | null;
    brokerage: string;
    status: 'open' | 'added' | 'closed';
    hq_note: string | null;
    same: number;
    asked: string;
};

const VISIBILITY: Record<string, string> = {
    public: 'Public',
    coming_soon: 'Coming soon',
    hidden: 'Hidden',
};

function money(cents: number | null): string {
    return cents === null
        ? '—'
        : `$${(cents / 100).toLocaleString('en-US', { maximumFractionDigits: 2 })}`;
}

const selectClass =
    'h-10 w-full rounded-md border bg-background px-3 text-base';

/** IDX1: HQ › IDX coverage: open states, the MLS board directory, and the data connectors behind each board. */
export default function HqIdx({
    states,
    boards,
    connectors,
    profiles,
    sources,
    state,
    queue,
    statuses,
    requests = [],
}: Props) {
    const open = states.filter((s) => s.enabled);
    const [filter, setFilter] = useState<string>(
        state ?? open[0]?.code ?? 'all',
    );
    const [editing, setEditing] = useState<Board | 'new' | null>(null);
    const names = useMemo(
        () => Object.fromEntries(connectors.map((c) => [c.key, c.name])),
        [connectors],
    );
    const shown = boards.filter(
        (b) =>
            filter === 'all' ||
            b.state === filter ||
            b.other_states.includes(filter),
    );

    return (
        <>
            <Head title="IDX coverage" />
            <div className="grid gap-6 p-4 md:p-8">
                <PageHeader
                    title="IDX coverage"
                    description="Which states are open, the MLS boards in each, and the data connector behind every board. Brokers and agents see board names and fees only, never connector names."
                />
                <Tabs defaultValue="queue">
                    <TabsList>
                        <TabsTrigger value="queue">
                            Brokerage boards (
                            {
                                queue.filter(
                                    (q) =>
                                        !['live', 'cancelled'].includes(
                                            q.status,
                                        ),
                                ).length
                            }{' '}
                            to do)
                        </TabsTrigger>
                        <TabsTrigger value="boards">MLS boards</TabsTrigger>
                        <TabsTrigger value="requests">
                            Asked for (
                            {requests.filter((r) => r.status === 'open').length}
                            )
                        </TabsTrigger>
                        <TabsTrigger value="states">
                            States ({open.length} open)
                        </TabsTrigger>
                        <TabsTrigger value="connectors">Connectors</TabsTrigger>
                    </TabsList>

                    <TabsContent value="queue" className="grid gap-3">
                        <p className="text-sm text-muted-foreground">
                            Each brokerage's boards, one at a time. Move a board
                            along as the paperwork goes; going live turns on its
                            real listings, cancelling hides them at once.
                        </p>
                        {queue.length === 0 && (
                            <p className="rounded-xl border bg-card p-6 text-center text-muted-foreground">
                                No brokerage has added a board yet.
                            </p>
                        )}
                        {queue.map((q) => (
                            <QueueRow
                                key={q.ulid}
                                item={q}
                                statuses={statuses}
                                connectors={connectors}
                                sources={sources}
                                names={names}
                            />
                        ))}
                    </TabsContent>

                    <TabsContent value="boards" className="grid gap-4">
                        <div className="flex flex-wrap items-end gap-3">
                            <label className="grid gap-1 text-sm">
                                State
                                <select
                                    className={selectClass}
                                    value={filter}
                                    onChange={(e) => setFilter(e.target.value)}
                                >
                                    {open.map((s) => (
                                        <option key={s.code} value={s.code}>
                                            {s.name}
                                        </option>
                                    ))}
                                    <option value="all">All states</option>
                                </select>
                            </label>
                            <Button onClick={() => setEditing('new')}>
                                Add a board
                            </Button>
                            <p className="text-sm text-muted-foreground">
                                A board is offered at sign-up when its state is
                                open, it is Public, and it has a connector and a
                                fee.
                            </p>
                        </div>
                        <div className="overflow-x-auto rounded-xl border bg-card">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3">Board</th>
                                        <th className="p-3">Main connector</th>
                                        <th className="p-3">Our cost</th>
                                        <th className="p-3">Client fee</th>
                                        <th className="p-3">Shown</th>
                                        <th className="p-3" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {shown.map((b) => (
                                        <tr key={b.ulid} className="border-t">
                                            <td className="p-3">
                                                <p className="font-medium">
                                                    {b.name}
                                                </p>
                                                <p className="text-muted-foreground">
                                                    {b.code} · {b.state}
                                                    {b.region
                                                        ? ` · ${b.region}`
                                                        : ''}
                                                </p>
                                                {b.form_update && (
                                                    <Badge className="mt-1">
                                                        New form version
                                                    </Badge>
                                                )}
                                                {!b.form_update &&
                                                    b.form_due && (
                                                        <Badge
                                                            variant="secondary"
                                                            className="mt-1"
                                                        >
                                                            Confirm the form
                                                        </Badge>
                                                    )}
                                            </td>
                                            <td className="p-3">
                                                {b.main_connector ? (
                                                    <span className="grid gap-1">
                                                        {names[
                                                            b.main_connector
                                                        ] ?? b.main_connector}
                                                        {!b.ready && (
                                                            <Badge variant="secondary">
                                                                Not switched on
                                                                yet
                                                            </Badge>
                                                        )}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        Not chosen
                                                    </span>
                                                )}
                                            </td>
                                            <td className="p-3 tabular-nums">
                                                {money(b.main_cost_cents)}
                                            </td>
                                            <td className="p-3 tabular-nums">
                                                {money(b.fee_cents)}
                                                {b.fee_cents !== null &&
                                                    b.monthly_fee_cents ===
                                                        null && (
                                                        <span className="block text-xs text-muted-foreground">
                                                            cost + margin
                                                        </span>
                                                    )}
                                            </td>
                                            <td className="p-3">
                                                <Badge
                                                    variant={
                                                        b.visibility ===
                                                        'public'
                                                            ? 'default'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {VISIBILITY[b.visibility]}
                                                </Badge>
                                                {!b.checked && (
                                                    <span className="mt-1 block text-xs text-muted-foreground">
                                                        To check
                                                    </span>
                                                )}
                                            </td>
                                            <td className="p-3 text-right">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        setEditing(b)
                                                    }
                                                >
                                                    Edit
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                    {shown.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={6}
                                                className="p-6 text-center text-muted-foreground"
                                            >
                                                No boards in this state yet. Add
                                                one with “Add a board”.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </TabsContent>

                    <TabsContent value="requests" className="grid gap-3">
                        <p className="text-sm text-muted-foreground">
                            Boards brokerages could not find. Add one to the
                            directory (it starts hidden), give it a connector
                            and a fee, then make it public: everyone who asked
                            is emailed that day.
                        </p>
                        {requests.length === 0 && (
                            <p className="rounded-xl border bg-card p-6 text-center text-muted-foreground">
                                No brokerage has asked for a board yet.
                            </p>
                        )}
                        {requests.map((r) => (
                            <RequestRow key={r.ulid} item={r} />
                        ))}
                    </TabsContent>

                    <TabsContent value="states" className="grid gap-3">
                        <p className="text-sm text-muted-foreground">
                            Open a state to offer its public boards at sign-up.
                            Closing a state stops new sign-ups there; brokerages
                            already live keep their listings.
                        </p>
                        <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            {[...states]
                                .sort(
                                    (a, b) =>
                                        Number(b.enabled) - Number(a.enabled),
                                )
                                .map((s) => (
                                    <li
                                        key={s.code}
                                        className="flex items-center justify-between gap-3 rounded-xl border bg-card p-3"
                                    >
                                        <span>
                                            <span className="font-medium">
                                                {s.name}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {s.boards} boards, {s.public}{' '}
                                                public
                                            </span>
                                        </span>
                                        <Switch
                                            aria-label={`${s.name} open`}
                                            checked={s.enabled}
                                            onCheckedChange={(v) =>
                                                router.put(
                                                    `/hq/idx/states/${s.code}`,
                                                    { enabled: v },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        />
                                    </li>
                                ))}
                        </ul>
                    </TabsContent>

                    <TabsContent value="connectors" className="grid gap-3">
                        <p className="text-sm text-muted-foreground">
                            The client fee for a board is our cost plus the
                            connector's margin, unless you set a fee on the
                            board. Keys for each connector are added when the
                            connector is switched on.
                        </p>
                        {connectors.map((c) => (
                            <ConnectorRow key={c.key} connector={c} />
                        ))}
                    </TabsContent>
                </Tabs>
            </div>
            {editing && (
                <BoardDialog
                    board={editing === 'new' ? null : editing}
                    states={states}
                    connectors={connectors}
                    profiles={profiles}
                    defaultState={filter === 'all' ? 'TX' : filter}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

const NEXT: Record<string, string[]> = {
    demo: ['needs_fixing'],
    awaiting_signature: ['sent', 'needs_fixing'],
    sent: ['mls_review', 'needs_fixing'],
    mls_review: ['sent', 'needs_fixing'],
    hq_check: ['needs_fixing'],
    needs_fixing: ['awaiting_signature', 'hq_check', 'sent', 'mls_review'],
};

/**
 * IDX11: a signed agreement waiting for HQ. The check says "Clear" or lists what to fix (the brokerage's and ours);
 * HQ sends it to the MLS, or sends it back with the note (written by AI or the rules) plus anything it adds.
 */
function CheckPanel({ item: q }: { item: QueueItem }) {
    const c = q.check ?? null;
    const [note, setNote] = useState(c?.note ?? '');
    const opts = { preserveScroll: true };
    const theirs = (c?.issues ?? []).filter((i) => i.for === 'brokerage');
    const ours = (c?.issues ?? []).filter((i) => i.for === 'hq');

    return (
        <section className="grid gap-3 rounded-lg border bg-muted/30 p-3 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-semibold">
                    {c === null
                        ? 'Checking the signed agreement…'
                        : c.result === 'clear'
                          ? 'Check: clear to send'
                          : `Check: ${c.issues.length} thing(s) to look at`}
                </p>
                {c !== null && (
                    <span className="text-xs text-muted-foreground">
                        {c.source === 'ai' ? 'Rules and AI' : 'Rules only'} ·{' '}
                        {new Date(c.at).toLocaleString()}
                        {q.form_version ? ` · form v${q.form_version}` : ''}
                    </span>
                )}
            </div>
            {ours.length > 0 && (
                <ul className="list-disc pl-5 text-destructive">
                    {ours.map((i) => (
                        <li key={i.text}>For RightAlly: {i.text}</li>
                    ))}
                </ul>
            )}
            {theirs.length > 0 && (
                <ul className="list-disc pl-5">
                    {theirs.map((i) => (
                        <li key={i.text}>{i.text}</li>
                    ))}
                </ul>
            )}
            {theirs.length > 0 && (
                <label className="grid gap-1">
                    Note to the brokerage (edit or add to it)
                    <Textarea
                        rows={4}
                        maxLength={1500}
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                    />
                </label>
            )}
            <div className="flex flex-wrap gap-2">
                <Button
                    size="sm"
                    variant={c?.result === 'clear' ? 'default' : 'outline'}
                    onClick={() =>
                        router.post(
                            `/hq/idx/subscriptions/${q.ulid}/send`,
                            {},
                            opts,
                        )
                    }
                >
                    {c?.result === 'clear'
                        ? 'Send to the MLS'
                        : 'Send to the MLS anyway'}
                </Button>
                <Button
                    size="sm"
                    variant={c?.result === 'issues' ? 'default' : 'outline'}
                    disabled={note.trim() === ''}
                    onClick={() =>
                        router.post(
                            `/hq/idx/subscriptions/${q.ulid}/move`,
                            { to: 'needs_fixing', note },
                            opts,
                        )
                    }
                >
                    Send back to the brokerage
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                        router.post(
                            `/hq/idx/subscriptions/${q.ulid}/recheck`,
                            {},
                            opts,
                        )
                    }
                >
                    Check again
                </Button>
            </div>
        </section>
    );
}

function QueueRow({
    item: q,
    statuses,
    connectors,
    sources,
    names,
}: {
    item: QueueItem;
    statuses: Record<string, string>;
    connectors: Connector[];
    sources: Option[];
    names: Record<string, string>;
}) {
    const [note, setNote] = useState('');
    const [source, setSource] = useState<string>(
        q.mls_source_id !== null ? String(q.mls_source_id) : '',
    );
    const [feedId, setFeedId] = useState(q.feed_id ?? '');
    const opts = { preserveScroll: true };
    const open = !['live', 'cancelled'].includes(q.status);

    return (
        <article className="grid gap-3 rounded-xl border bg-card p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="font-semibold">
                        {q.brokerage} · {q.board}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {q.state} · MLS ID {q.member_id ?? '—'}
                        {q.office_id ? ` · office ${q.office_id}` : ''} ·
                        licence {q.license_number ?? '—'}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        Managing broker: {q.managing_broker_name ?? '—'}{' '}
                        {q.managing_broker_email
                            ? `<${q.managing_broker_email}>`
                            : ''}
                        {q.has_own_key ? ' · gave its own feed key' : ''}
                        {q.reminders > 0
                            ? ` · ${q.reminders} reminder(s) sent`
                            : ''}
                    </p>
                </div>
                <div className="text-right">
                    <Badge
                        variant={
                            q.status === 'live'
                                ? 'default'
                                : q.status === 'needs_fixing'
                                  ? 'destructive'
                                  : 'secondary'
                        }
                    >
                        {statuses[q.status] ?? q.status}
                    </Badge>
                    {q.since && (
                        <p className="mt-1 text-xs text-muted-foreground">
                            since {new Date(q.since).toLocaleDateString()}
                        </p>
                    )}
                </div>
            </div>
            {q.note && (
                <p className="text-sm whitespace-pre-line text-destructive">
                    To fix: {q.note}
                </p>
            )}
            {q.status === 'hq_check' && <CheckPanel item={q} />}
            {(q.agreement || q.website || q.compliance_ok !== null) && (
                <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                    {q.agreement && <span>Agreement: {q.agreement}</span>}
                    {q.website && (
                        <span className="text-muted-foreground">
                            {q.website}
                        </span>
                    )}
                    {q.compliance_ok !== null && (
                        <span
                            className={
                                q.compliance_ok
                                    ? 'text-emerald-700'
                                    : 'text-destructive'
                            }
                        >
                            Website check:{' '}
                            {q.compliance_ok
                                ? 'passed'
                                : (q.compliance_note ?? 'failed')}
                        </span>
                    )}
                    {q.has_copy && (
                        <a
                            className="font-medium underline"
                            href={`/hq/idx/subscriptions/${q.ulid}/agreement.pdf`}
                        >
                            Signed copy
                        </a>
                    )}
                    {q.has_copy && !['live', 'hq_check'].includes(q.status) && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    `/hq/idx/subscriptions/${q.ulid}/send`,
                                    {},
                                    opts,
                                )
                            }
                        >
                            Send signed agreement to the MLS now
                        </Button>
                    )}
                </p>
            )}
            {open && (
                <div className="grid gap-3 md:grid-cols-3">
                    <label className="grid gap-1 text-sm">
                        Connector (this brokerage only)
                        <select
                            className={selectClass}
                            value={q.connector ?? ''}
                            onChange={(e) =>
                                router.post(
                                    `/hq/idx/subscriptions/${q.ulid}/connector`,
                                    { connector: e.target.value },
                                    opts,
                                )
                            }
                        >
                            <option value="" disabled>
                                Choose…
                            </option>
                            {connectors.map((c) => (
                                <option key={c.key} value={c.key}>
                                    {names[c.key] ?? c.key}
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="grid gap-1 text-sm">
                        Move to
                        <div className="flex flex-wrap gap-2">
                            {(NEXT[q.status] ?? [])
                                .filter((to) => to !== 'needs_fixing')
                                .map((to) => (
                                    <Button
                                        key={to}
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                `/hq/idx/subscriptions/${q.ulid}/move`,
                                                { to },
                                                opts,
                                            )
                                        }
                                    >
                                        {statuses[to]}
                                    </Button>
                                ))}
                        </div>
                    </div>
                    <div className="grid gap-1 text-sm">
                        Needs fixing (tell the brokerage what)
                        <div className="flex gap-2">
                            <Input
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                placeholder="For example: MLS ID not found"
                            />
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={note.trim() === ''}
                                onClick={() =>
                                    router.post(
                                        `/hq/idx/subscriptions/${q.ulid}/move`,
                                        { to: 'needs_fixing', note },
                                        {
                                            ...opts,
                                            onSuccess: () => setNote(''),
                                        },
                                    )
                                }
                            >
                                Send
                            </Button>
                        </div>
                    </div>
                </div>
            )}
            {['sent', 'mls_review'].includes(q.status) && (
                <div className="flex flex-wrap items-end gap-3 rounded-lg bg-muted/40 p-3">
                    <label className="grid gap-1 text-sm">
                        Feed
                        <select
                            className={selectClass}
                            value={source}
                            onChange={(e) => setSource(e.target.value)}
                        >
                            <option value="">
                                {q.has_own_key
                                    ? "Brokerage's own key (made for you)"
                                    : 'The board’s linked feed'}
                            </option>
                            {sources.map((o) => (
                                <option key={o.id} value={o.id}>
                                    {o.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm">
                        Feed ID from the connector (if any)
                        <Input
                            value={feedId}
                            onChange={(e) => setFeedId(e.target.value)}
                        />
                    </label>
                    <Button
                        onClick={() =>
                            router.post(
                                `/hq/idx/subscriptions/${q.ulid}/live`,
                                {
                                    source_id:
                                        source === '' ? null : Number(source),
                                    feed_id: feedId,
                                },
                                opts,
                            )
                        }
                    >
                        Turn on live
                    </Button>
                </div>
            )}
            {q.status !== 'cancelled' && (
                <div>
                    <Button
                        size="sm"
                        variant="ghost"
                        className="text-muted-foreground"
                        onClick={() =>
                            router.post(
                                `/hq/idx/subscriptions/${q.ulid}/cancel`,
                                {},
                                opts,
                            )
                        }
                    >
                        Cancel this board
                    </Button>
                </div>
            )}
        </article>
    );
}

function ConnectorRow({ connector }: { connector: Connector }) {
    const form = useForm({
        status: connector.status,
        margin_percent: connector.margin_percent,
        notes: connector.notes ?? '',
    });

    return (
        <form
            className="grid items-end gap-3 rounded-xl border bg-card p-4 md:grid-cols-[1fr_10rem_8rem_1.5fr_auto]"
            onSubmit={(e) => {
                e.preventDefault();
                form.put(`/hq/idx/connectors/${connector.key}`, {
                    preserveScroll: true,
                });
            }}
        >
            <div>
                <p className="font-medium">{connector.name}</p>
                <p className="text-sm text-muted-foreground">
                    {connector.kind === 'live'
                        ? 'Searched live at the provider'
                        : 'Listings copied into RightAlly'}
                </p>
            </div>
            <FormField label="Status" error={form.errors.status}>
                {(control) => (
                    <select
                        {...control}
                        className={selectClass}
                        value={form.data.status}
                        onChange={(e) => form.setData('status', e.target.value)}
                    >
                        <option value="active">On</option>
                        <option value="maintenance">Paused</option>
                        <option value="disabled">Off</option>
                    </select>
                )}
            </FormField>
            <FormField label="Margin %" error={form.errors.margin_percent}>
                {(control) => (
                    <Input
                        {...control}
                        type="number"
                        min={0}
                        max={500}
                        value={form.data.margin_percent}
                        onChange={(e) =>
                            form.setData(
                                'margin_percent',
                                Number(e.target.value),
                            )
                        }
                    />
                )}
            </FormField>
            <FormField label="Notes (HQ only)" error={form.errors.notes}>
                {(control) => (
                    <Input
                        {...control}
                        value={form.data.notes}
                        onChange={(e) => form.setData('notes', e.target.value)}
                    />
                )}
            </FormField>
            <Button type="submit" variant="outline" disabled={form.processing}>
                Save
            </Button>
        </form>
    );
}

function BoardDialog({
    board,
    states,
    connectors,
    profiles,
    defaultState,
    onClose,
}: {
    board: Board | null;
    states: State[];
    connectors: Connector[];
    profiles: Option[];
    defaultState: string;
    onClose: () => void;
}) {
    const form = useForm({
        code: board?.code ?? '',
        name: board?.name ?? '',
        state: board?.state ?? defaultState,
        other_states: board?.other_states ?? ([] as string[]),
        region: board?.region ?? '',
        visibility: board?.visibility ?? 'coming_soon',
        approval_days_min: board?.approval_days_min ?? 3,
        approval_days_max: board?.approval_days_max ?? 7,
        main_connector: board?.main_connector ?? '',
        main_cost_cents: board?.main_cost_cents ?? null,
        backup_connector: board?.backup_connector ?? '',
        backup_cost_cents: board?.backup_cost_cents ?? null,
        connector_code: board?.connector_code ?? '',
        monthly_fee_cents: board?.monthly_fee_cents ?? null,
        setup_fee_cents: board?.setup_fee_cents ?? 0,
        rules_profile_id: board?.rules_profile_id ?? null,
        checked: board?.checked ?? false,
        notes: board?.notes ?? '',
        agreement_text: board?.agreement_text ?? '',
        managing_broker_signs: board?.managing_broker_signs ?? true,
        send_to: board?.send_to ?? '',
        send_cc: board?.send_cc ?? '',
        send_bcc: board?.send_bcc ?? '',
        agreement_url: board?.agreement_url ?? '',
    });
    const submit = () => {
        form.transform((d) => ({
            ...d,
            main_connector: d.main_connector || null,
            backup_connector: d.backup_connector || null,
            connector_code: d.connector_code || null,
            code: d.code.toUpperCase(),
        }));
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (board) {
            form.put(`/hq/idx/boards/${board.ulid}`, opts);
        } else {
            form.post('/hq/idx/boards', opts);
        }
    };
    const connectorSelect = (
        field: 'main_connector' | 'backup_connector',
        label: string,
    ) => (
        <FormField label={label} error={form.errors[field]}>
            {(control) => (
                <select
                    {...control}
                    className={selectClass}
                    value={form.data[field]}
                    onChange={(e) => form.setData(field, e.target.value)}
                >
                    <option value="">None</option>
                    {connectors.map((c) => (
                        <option key={c.key} value={c.key}>
                            {c.name}
                            {c.status !== 'active' ? ' (off)' : ''}
                        </option>
                    ))}
                </select>
            )}
        </FormField>
    );

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {board ? board.name : 'Add a board'}
                    </DialogTitle>
                    <DialogDescription>
                        Name, area, connector, our cost and the client's fee.
                        Leave the client fee empty to use our cost plus the
                        connector's margin.
                    </DialogDescription>
                </DialogHeader>
                <form
                    id="board-form"
                    className="grid gap-4 sm:grid-cols-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                >
                    <FormField
                        label="Board name"
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
                    <FormField
                        label="Short code"
                        help="For example HAR"
                        error={form.errors.code}
                        required
                    >
                        {(control) => (
                            <Input
                                {...control}
                                value={form.data.code}
                                onChange={(e) =>
                                    form.setData('code', e.target.value)
                                }
                            />
                        )}
                    </FormField>
                    <FormField label="Main state" error={form.errors.state}>
                        {(control) => (
                            <select
                                {...control}
                                className={selectClass}
                                value={form.data.state}
                                onChange={(e) =>
                                    form.setData('state', e.target.value)
                                }
                            >
                                {states.map((s) => (
                                    <option key={s.code} value={s.code}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                        )}
                    </FormField>
                    <FormField
                        label="Area covered"
                        help="For example Greater Houston"
                        error={form.errors.region}
                    >
                        {(control) => (
                            <Input
                                {...control}
                                value={form.data.region}
                                onChange={(e) =>
                                    form.setData('region', e.target.value)
                                }
                            />
                        )}
                    </FormField>
                    <FormField
                        label="Also shown in states"
                        help="Codes separated by commas, for example AL, FL"
                        error={form.errors.other_states}
                    >
                        {(control) => (
                            <Input
                                {...control}
                                value={form.data.other_states.join(', ')}
                                onChange={(e) =>
                                    form.setData(
                                        'other_states',
                                        e.target.value
                                            .split(',')
                                            .map((x) => x.trim().toUpperCase())
                                            .filter(Boolean),
                                    )
                                }
                            />
                        )}
                    </FormField>
                    <FormField label="Shown" error={form.errors.visibility}>
                        {(control) => (
                            <select
                                {...control}
                                className={selectClass}
                                value={form.data.visibility}
                                onChange={(e) =>
                                    form.setData('visibility', e.target.value)
                                }
                            >
                                {Object.entries(VISIBILITY).map(([k, v]) => (
                                    <option key={k} value={k}>
                                        {v}
                                    </option>
                                ))}
                            </select>
                        )}
                    </FormField>
                    {connectorSelect('main_connector', 'Main connector')}
                    <FormField
                        label="Our cost on the main connector (a month)"
                        error={form.errors.main_cost_cents}
                    >
                        {(control) => (
                            <MoneyInput
                                {...control}
                                value={form.data.main_cost_cents}
                                onChange={(v) =>
                                    form.setData('main_cost_cents', v)
                                }
                            />
                        )}
                    </FormField>
                    {connectorSelect('backup_connector', 'Backup connector')}
                    <FormField
                        label="Our cost on the backup (a month)"
                        error={form.errors.backup_cost_cents}
                    >
                        {(control) => (
                            <MoneyInput
                                {...control}
                                value={form.data.backup_cost_cents}
                                onChange={(v) =>
                                    form.setData('backup_cost_cents', v)
                                }
                            />
                        )}
                    </FormField>
                    <FormField
                        label="Board's code at the connector"
                        help="For example HAR or mlsunited"
                        error={form.errors.connector_code}
                    >
                        {(control) => (
                            <Input
                                {...control}
                                value={form.data.connector_code}
                                onChange={(e) =>
                                    form.setData(
                                        'connector_code',
                                        e.target.value,
                                    )
                                }
                            />
                        )}
                    </FormField>
                    <FormField
                        label="IDX display template"
                        help="Logo, disclaimer and attribution (HQ › MLS connections)"
                        error={form.errors.rules_profile_id}
                    >
                        {(control) => (
                            <select
                                {...control}
                                className={selectClass}
                                value={form.data.rules_profile_id ?? ''}
                                onChange={(e) =>
                                    form.setData(
                                        'rules_profile_id',
                                        e.target.value === ''
                                            ? null
                                            : Number(e.target.value),
                                    )
                                }
                            >
                                <option value="">Not set yet</option>
                                {profiles.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name}
                                    </option>
                                ))}
                            </select>
                        )}
                    </FormField>
                    <FormField
                        label="Client fee (a month)"
                        help="Empty: our cost + margin"
                        error={form.errors.monthly_fee_cents}
                    >
                        {(control) => (
                            <MoneyInput
                                {...control}
                                value={form.data.monthly_fee_cents}
                                onChange={(v) =>
                                    form.setData('monthly_fee_cents', v)
                                }
                            />
                        )}
                    </FormField>
                    <FormField
                        label="Set-up fee (once)"
                        error={form.errors.setup_fee_cents}
                    >
                        {(control) => (
                            <MoneyInput
                                {...control}
                                value={form.data.setup_fee_cents}
                                onChange={(v) =>
                                    form.setData('setup_fee_cents', v ?? 0)
                                }
                            />
                        )}
                    </FormField>
                    <FormField
                        label="Approval takes (business days)"
                        error={
                            form.errors.approval_days_min ??
                            form.errors.approval_days_max
                        }
                    >
                        {(control) => (
                            <span className="flex items-center gap-2">
                                <Input
                                    {...control}
                                    type="number"
                                    min={0}
                                    max={90}
                                    aria-label="From"
                                    value={form.data.approval_days_min}
                                    onChange={(e) =>
                                        form.setData(
                                            'approval_days_min',
                                            Number(e.target.value),
                                        )
                                    }
                                />
                                to
                                <Input
                                    type="number"
                                    min={0}
                                    max={90}
                                    aria-label="To"
                                    value={form.data.approval_days_max}
                                    onChange={(e) =>
                                        form.setData(
                                            'approval_days_max',
                                            Number(e.target.value),
                                        )
                                    }
                                />
                            </span>
                        )}
                    </FormField>
                    <FormField
                        label="Notes (HQ only)"
                        error={form.errors.notes}
                        className="sm:col-span-2"
                    >
                        {(control) => (
                            <Textarea
                                {...control}
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                            />
                        )}
                    </FormField>
                    <fieldset className="grid gap-4 rounded-xl border p-4 sm:col-span-2 sm:grid-cols-2">
                        <legend className="px-1 text-sm font-semibold">
                            IDX agreement
                        </legend>
                        <p className="text-sm text-muted-foreground sm:col-span-2">
                            When a brokerage sends its details, RightAlly fills
                            this board's agreement, collects the signatures,
                            keeps a copy and emails the signed agreement to the
                            addresses below. Leave “Send to” empty to handle the
                            paperwork by hand.
                        </p>
                        <FormField
                            label="Send the signed agreement to"
                            help="For example the Repliers onboarding desk, or the MLS (commas between addresses)"
                            error={form.errors.send_to}
                            className="sm:col-span-2"
                        >
                            {(control) => (
                                <Input
                                    {...control}
                                    value={form.data.send_to}
                                    onChange={(e) =>
                                        form.setData('send_to', e.target.value)
                                    }
                                />
                            )}
                        </FormField>
                        <FormField label="CC" error={form.errors.send_cc}>
                            {(control) => (
                                <Input
                                    {...control}
                                    value={form.data.send_cc}
                                    onChange={(e) =>
                                        form.setData('send_cc', e.target.value)
                                    }
                                />
                            )}
                        </FormField>
                        <FormField
                            label="BCC"
                            help="For example HQ's own address"
                            error={form.errors.send_bcc}
                        >
                            {(control) => (
                                <Input
                                    {...control}
                                    value={form.data.send_bcc}
                                    onChange={(e) =>
                                        form.setData('send_bcc', e.target.value)
                                    }
                                />
                            )}
                        </FormField>
                        <FormField
                            label="Terms printed on the signature page"
                            help="You can use {brokerage}, {mls_id}, {office_id}, {license}, {website}, {managing_broker}, {board}, {date}"
                            error={form.errors.agreement_text}
                            className="sm:col-span-2"
                        >
                            {(control) => (
                                <Textarea
                                    {...control}
                                    rows={4}
                                    value={form.data.agreement_text}
                                    onChange={(e) =>
                                        form.setData(
                                            'agreement_text',
                                            e.target.value,
                                        )
                                    }
                                />
                            )}
                        </FormField>
                        <label className="flex items-center gap-3 text-sm sm:col-span-2">
                            <Switch
                                checked={form.data.managing_broker_signs}
                                onCheckedChange={(v) =>
                                    form.setData('managing_broker_signs', v)
                                }
                            />
                            The managing broker signs too (after the brokerage)
                        </label>
                        {board && (
                            <label className="grid gap-1 text-sm sm:col-span-2">
                                {board.has_agreement_pdf
                                    ? "The MLS's own agreement is saved. Upload a new PDF to replace it:"
                                    : "The MLS's own agreement (PDF, optional): a signature page is added to it"}
                                <input
                                    type="file"
                                    accept="application/pdf"
                                    onChange={(e) => {
                                        const file = e.target.files?.[0];
                                        if (file) {
                                            router.post(
                                                `/hq/idx/boards/${board.ulid}/agreement`,
                                                { file },
                                                {
                                                    forceFormData: true,
                                                    preserveScroll: true,
                                                },
                                            );
                                        }
                                    }}
                                />
                            </label>
                        )}
                        <FormField
                            label="Where the board publishes this form (web address, optional)"
                            help="Checked every week. When the board posts a new version, you are told and choose whether to use it."
                            error={form.errors.agreement_url}
                        >
                            {(control) => (
                                <Input
                                    {...control}
                                    type="url"
                                    placeholder="https://"
                                    value={form.data.agreement_url}
                                    onChange={(e) =>
                                        form.setData(
                                            'agreement_url',
                                            e.target.value,
                                        )
                                    }
                                />
                            )}
                        </FormField>
                        {board && <FormWatch board={board} />}
                    </fieldset>
                    <label className="flex items-center gap-3 text-sm sm:col-span-2">
                        <Switch
                            checked={form.data.checked}
                            onCheckedChange={(v) => form.setData('checked', v)}
                        />
                        I have checked this board's name, connector and fee
                    </label>
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        form="board-form"
                        disabled={form.processing}
                    >
                        Save board
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** IDX8: one request: add the board (with a short code) or close it with a reason the brokerage sees. */
function RequestRow({ item: r }: { item: RequestItem }) {
    const [code, setCode] = useState(
        r.name
            .replace(/\(([^)]+)\)/, ' $1 ')
            .match(/\b[A-Za-z0-9]/g)
            ?.join('')
            .toUpperCase()
            .slice(0, 20) ?? '',
    );
    const [note, setNote] = useState('');

    return (
        <section className="grid gap-3 rounded-xl border bg-card p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h3 className="font-semibold">
                        {r.name} ({r.state})
                    </h3>
                    <p className="text-sm text-muted-foreground">
                        {r.brokerage}, asked {r.asked}
                        {r.same > 1 ? ` · ${r.same} brokerages asked` : ''}
                        {r.website ? ` · ${r.website}` : ''}
                    </p>
                    {r.note && <p className="text-sm">{r.note}</p>}
                </div>
                <Badge variant={r.status === 'open' ? 'default' : 'secondary'}>
                    {r.status === 'open'
                        ? 'Open'
                        : r.status === 'added'
                          ? 'Added'
                          : 'Closed'}
                </Badge>
            </div>
            {r.hq_note && (
                <p className="text-sm text-muted-foreground">{r.hq_note}</p>
            )}
            {r.status === 'open' && (
                <div className="flex flex-wrap items-end gap-3">
                    <label className="grid gap-1 text-sm">
                        Short code
                        <Input
                            className="w-40"
                            value={code}
                            onChange={(e) =>
                                setCode(e.target.value.toUpperCase())
                            }
                        />
                    </label>
                    <Button
                        disabled={code === ''}
                        onClick={() =>
                            router.post(
                                `/hq/idx/requests/${r.ulid}/add`,
                                { code },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Add to MLS boards
                    </Button>
                    <label className="grid flex-1 gap-1 text-sm">
                        Or close, with a reason the brokerage sees
                        <Input
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                        />
                    </label>
                    <Button
                        variant="outline"
                        disabled={note.trim() === ''}
                        onClick={() =>
                            router.post(
                                `/hq/idx/requests/${r.ulid}/close`,
                                { note },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Close
                    </Button>
                </div>
            )}
        </section>
    );
}

/** IDX11: the watch on a board's own agreement form: a newer version found, or a reminder to confirm it. */
function FormWatch({ board }: { board: Board }) {
    const act = (action: string) =>
        router.post(
            `/hq/idx/boards/${board.ulid}/form`,
            { action },
            { preserveScroll: true },
        );

    return (
        <div className="grid gap-2 rounded-lg bg-muted/40 p-3 text-sm">
            <p>
                Form version {board.agreement_version ?? 1}
                {board.form_confirmed
                    ? ` · confirmed current on ${board.form_confirmed}`
                    : ''}
            </p>
            {board.form_watch_note && (
                <p className="text-muted-foreground">{board.form_watch_note}</p>
            )}
            {board.form_update && (
                <p className="font-medium text-amber-700">
                    The board published a new version of this form.
                </p>
            )}
            <div className="flex flex-wrap gap-2">
                {board.form_update && (
                    <>
                        <Button size="sm" onClick={() => act('use_new')}>
                            Use the new version
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => act('keep')}
                        >
                            Keep current
                        </Button>
                    </>
                )}
                {board.agreement_url && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => act('check')}
                    >
                        Check now
                    </Button>
                )}
                {board.has_agreement_pdf && (
                    <Button
                        size="sm"
                        variant={board.form_due ? 'default' : 'outline'}
                        onClick={() => act('confirm')}
                    >
                        {board.form_due
                            ? 'Confirm this form is current'
                            : 'Confirm current'}
                    </Button>
                )}
            </div>
        </div>
    );
}
