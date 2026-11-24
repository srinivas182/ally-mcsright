import { Head, router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Rank = {
    ulid?: string;
    name: string;
    min_closings: number;
    min_gci_cents: number;
    min_recruits: number;
    window_months: number;
    unlock_levels: number | null;
    plan?: string | null;
};

type Agent = {
    ulid: string;
    name: string;
    rank: string | null;
    next: string | null;
    needs: string[];
    closings: number;
    gci_cents: number;
    recruits: number;
    plan: string | null;
    move_to: { ulid: string; name: string } | null;
};

type Props = {
    ranks: Rank[];
    suggested: Rank[];
    plans: { ulid: string; name: string }[];
    agents: Agent[];
    canManage: boolean;
};

const blank: Rank = {
    name: '',
    min_closings: 0,
    min_gci_cents: 0,
    min_recruits: 0,
    window_months: 12,
    unlock_levels: null,
    plan: null,
};

function NumberCell({
    label,
    value,
    onChange,
    disabled,
    prefix,
}: {
    label: string;
    value: number | null;
    onChange: (v: number | null) => void;
    disabled: boolean;
    prefix?: string;
}) {
    return (
        <label className="grid gap-1 text-sm">
            <span className="text-muted-foreground">{label}</span>
            <span className="flex items-center gap-1">
                {prefix && <span aria-hidden="true">{prefix}</span>}
                <Input
                    type="number"
                    min={0}
                    inputMode="numeric"
                    className="h-10 w-28"
                    value={value ?? ''}
                    disabled={disabled}
                    onChange={(e) =>
                        onChange(
                            e.target.value === ''
                                ? null
                                : Math.max(
                                      0,
                                      Math.round(Number(e.target.value)),
                                  ),
                        )
                    }
                />
            </span>
        </label>
    );
}

/**
 * CM1: ranks. Agents move up by production (closed deals or GCI) and recruits; a rank can open more revenue-share
 * levels and can name a better commission plan. Moving someone to that plan is always the broker's decision.
 */
export default function Ranks({
    ranks,
    suggested,
    plans,
    agents,
    canManage,
}: Props) {
    const [rows, setRows] = useState<Rank[]>(ranks);
    const [saving, setSaving] = useState(false);
    const set = (i: number, patch: Partial<Rank>) =>
        setRows(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)));
    const ready = agents.filter((a) => a.move_to);
    const changed = JSON.stringify(rows) !== JSON.stringify(ranks);

    return (
        <>
            <Head title="Ranks" />
            <div className="flex max-w-5xl flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Ranks"
                    description="Agents move up by what they close and the agents they bring in. A rank can open more revenue-share levels and point to a better commission plan."
                />

                {ready.length > 0 && (
                    <section
                        aria-labelledby="ready"
                        className="grid gap-3 rounded-xl border border-primary/30 bg-primary/5 p-4"
                    >
                        <h2 id="ready" className="text-lg font-semibold">
                            Ready to move up ({ready.length})
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            These agents reached a rank that comes with a better
                            plan. Nothing changes until you move them.
                        </p>
                        <ul className="grid gap-2">
                            {ready.map((a) => (
                                <li
                                    key={a.ulid}
                                    className="flex flex-wrap items-center gap-3 rounded-lg bg-background p-3"
                                >
                                    <span className="flex-1">
                                        <span className="font-semibold">
                                            {a.name}
                                        </span>{' '}
                                        reached {a.rank}. Now on{' '}
                                        {a.plan ?? 'no plan'}.
                                    </span>
                                    {canManage && a.move_to && (
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    '/compensation/assignments',
                                                    {
                                                        agent: a.ulid,
                                                        plan: a.move_to?.ulid,
                                                        from: new Date()
                                                            .toISOString()
                                                            .slice(0, 10),
                                                    },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Move to {a.move_to.name}
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section aria-labelledby="ladder" className="grid gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="ladder" className="text-lg font-semibold">
                            Your ranks, lowest first
                        </h2>
                        {canManage && rows.length === 0 && (
                            <Button
                                variant="outline"
                                onClick={() => setRows(suggested)}
                            >
                                Start from suggested ranks
                            </Button>
                        )}
                    </div>
                    {rows.length === 0 && (
                        <p className="rounded-lg border border-dashed p-6 text-center text-muted-foreground">
                            No ranks yet. Start from the suggested ranks and
                            change the numbers to fit your brokerage.
                        </p>
                    )}
                    {rows.map((r, i) => (
                        <div
                            key={r.ulid ?? `new-${i}`}
                            className="grid gap-3 rounded-xl border bg-card p-4"
                        >
                            <div className="flex flex-wrap items-center gap-3">
                                <Badge variant="outline">Rank {i + 1}</Badge>
                                <Input
                                    aria-label={`Rank ${i + 1} name`}
                                    className="h-10 max-w-xs text-base font-semibold"
                                    value={r.name}
                                    disabled={!canManage}
                                    placeholder="Name, for example Producer"
                                    onChange={(e) =>
                                        set(i, { name: e.target.value })
                                    }
                                />
                                {canManage && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="ml-auto"
                                        aria-label={`Remove ${r.name || `rank ${i + 1}`}`}
                                        onClick={() =>
                                            setRows(
                                                rows.filter((_, j) => j !== i),
                                            )
                                        }
                                    >
                                        <Trash2 aria-hidden="true" /> Remove
                                    </Button>
                                )}
                            </div>
                            <p className="text-sm font-medium">
                                To reach it, in the last {r.window_months}{' '}
                                months:
                            </p>
                            <div className="flex flex-wrap items-end gap-4">
                                <NumberCell
                                    label="Closed deals"
                                    value={r.min_closings}
                                    disabled={!canManage}
                                    onChange={(v) =>
                                        set(i, { min_closings: v ?? 0 })
                                    }
                                />
                                <span className="pb-2 text-sm text-muted-foreground">
                                    or
                                </span>
                                <NumberCell
                                    label="GCI"
                                    prefix="$"
                                    value={Math.round(r.min_gci_cents / 100)}
                                    disabled={!canManage}
                                    onChange={(v) =>
                                        set(i, {
                                            min_gci_cents: (v ?? 0) * 100,
                                        })
                                    }
                                />
                                <span className="pb-2 text-sm text-muted-foreground">
                                    and
                                </span>
                                <NumberCell
                                    label="Recruits who closed"
                                    value={r.min_recruits}
                                    disabled={!canManage}
                                    onChange={(v) =>
                                        set(i, { min_recruits: v ?? 0 })
                                    }
                                />
                                <NumberCell
                                    label="Over months"
                                    value={r.window_months}
                                    disabled={!canManage}
                                    onChange={(v) =>
                                        set(i, {
                                            window_months: Math.min(
                                                36,
                                                Math.max(1, v ?? 12),
                                            ),
                                        })
                                    }
                                />
                            </div>
                            <p className="text-sm font-medium">It gives:</p>
                            <div className="flex flex-wrap items-end gap-4">
                                <NumberCell
                                    label="Revenue-share levels open"
                                    value={r.unlock_levels}
                                    disabled={!canManage}
                                    onChange={(v) =>
                                        set(i, {
                                            unlock_levels:
                                                v === null
                                                    ? null
                                                    : Math.min(15, v),
                                        })
                                    }
                                />
                                <label className="grid gap-1 text-sm">
                                    <span className="text-muted-foreground">
                                        Commission plan (optional)
                                    </span>
                                    <select
                                        className="h-10 rounded-md border bg-background px-3"
                                        value={r.plan ?? ''}
                                        disabled={!canManage}
                                        onChange={(e) =>
                                            set(i, {
                                                plan: e.target.value || null,
                                            })
                                        }
                                    >
                                        <option value="">No plan change</option>
                                        {plans.map((p) => (
                                            <option key={p.ulid} value={p.ulid}>
                                                {p.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            </div>
                        </div>
                    ))}
                    {canManage && (
                        <div className="flex flex-wrap gap-2">
                            {rows.length < 8 && (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        setRows([
                                            ...rows,
                                            {
                                                ...blank,
                                                ...(rows.length > 0
                                                    ? {
                                                          min_closings:
                                                              rows[
                                                                  rows.length -
                                                                      1
                                                              ].min_closings,
                                                          min_gci_cents:
                                                              rows[
                                                                  rows.length -
                                                                      1
                                                              ].min_gci_cents,
                                                          min_recruits:
                                                              rows[
                                                                  rows.length -
                                                                      1
                                                              ].min_recruits,
                                                      }
                                                    : {}),
                                            },
                                        ])
                                    }
                                >
                                    <Plus aria-hidden="true" /> Add a rank
                                </Button>
                            )}
                            <Button
                                disabled={!changed || saving}
                                onClick={() => {
                                    setSaving(true);
                                    router.post(
                                        '/compensation/ranks',
                                        { ranks: rows },
                                        {
                                            preserveScroll: true,
                                            onFinish: () => setSaving(false),
                                        },
                                    );
                                }}
                            >
                                Save ranks
                            </Button>
                        </div>
                    )}
                </section>

                <section aria-labelledby="standing" className="grid gap-3">
                    <h2 id="standing" className="text-lg font-semibold">
                        Where each agent stands
                    </h2>
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-muted/50 text-muted-foreground">
                                <tr>
                                    <th className="p-3 font-medium">Agent</th>
                                    <th className="p-3 font-medium">Rank</th>
                                    <th className="p-3 font-medium">
                                        Closed (window)
                                    </th>
                                    <th className="p-3 font-medium">GCI</th>
                                    <th className="p-3 font-medium">
                                        Recruits
                                    </th>
                                    <th className="p-3 font-medium">
                                        Next rank needs
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {agents.map((a) => (
                                    <tr key={a.ulid} className="border-t">
                                        <td className="p-3 font-medium">
                                            {a.name}
                                        </td>
                                        <td className="p-3">{a.rank ?? '—'}</td>
                                        <td className="p-3">{a.closings}</td>
                                        <td className="p-3">
                                            {usd(a.gci_cents)}
                                        </td>
                                        <td className="p-3">{a.recruits}</td>
                                        <td className="p-3 text-muted-foreground">
                                            {a.next
                                                ? `${a.next}: ${a.needs.join(', and ') || 'almost there'}`
                                                : a.rank
                                                  ? 'Top rank'
                                                  : '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </>
    );
}

Ranks.layout = {
    breadcrumbs: [{ title: 'Ranks', href: '/compensation/ranks' }],
};
