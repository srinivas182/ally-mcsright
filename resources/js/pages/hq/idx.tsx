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
};
type Option = { id: number; name: string };
type Props = {
    states: State[];
    boards: Board[];
    connectors: Connector[];
    profiles: Option[];
    sources: Option[];
    state: string | null;
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
    state,
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
                <Tabs defaultValue="boards">
                    <TabsList>
                        <TabsTrigger value="boards">MLS boards</TabsTrigger>
                        <TabsTrigger value="states">
                            States ({open.length} open)
                        </TabsTrigger>
                        <TabsTrigger value="connectors">Connectors</TabsTrigger>
                    </TabsList>

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
