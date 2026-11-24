import { router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type DealAgent = {
    ulid: string;
    name: string;
    share: string;
    primary: boolean;
};

/**
 * CM5: the agents on a deal (co-listing, co-buying, a team) and each one's share of the commission. Each agent is
 * paid on their own plan (revenue share or flat fee, their own cap). The brokerage can change it until closing.
 */
export function DealAgents({
    deal,
    agents,
    choices,
    locked,
}: {
    deal: string;
    agents: DealAgent[];
    choices: { ulid: string; name: string }[] | null;
    locked: boolean;
}) {
    const [rows, setRows] = useState(
        agents.map((a) => ({ user: a.ulid, share: a.share })),
    );
    const [editing, setEditing] = useState(false);
    const total = rows.reduce((s, r) => s + (Number(r.share) || 0), 0);
    const primary = agents.find((a) => a.primary)?.ulid;
    const canEdit = choices !== null && !locked;

    return (
        <section
            aria-labelledby="deal-agents"
            className="grid gap-3 rounded-lg border p-4 text-sm"
        >
            <div className="flex flex-wrap items-center gap-2">
                <h2 id="deal-agents" className="text-base font-semibold">
                    Agents on this deal
                </h2>
                {canEdit && !editing && (
                    <Button
                        size="sm"
                        variant="outline"
                        className="ml-auto"
                        onClick={() => setEditing(true)}
                    >
                        Change agents or shares
                    </Button>
                )}
            </div>
            {!editing ? (
                <ul className="grid gap-1">
                    {agents.map((a) => (
                        <li
                            key={a.ulid}
                            className="flex items-center justify-between gap-3"
                        >
                            <span>
                                {a.name}
                                {a.primary && (
                                    <Badge variant="secondary" className="ml-2">
                                        Deal agent
                                    </Badge>
                                )}
                            </span>
                            <span className="tabular-nums">{a.share}%</span>
                        </li>
                    ))}
                </ul>
            ) : (
                <form
                    className="grid gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post(
                            `/deals/${deal}/agents`,
                            { agents: rows },
                            {
                                preserveScroll: true,
                                onSuccess: () => setEditing(false),
                            },
                        );
                    }}
                >
                    {rows.map((r, i) => (
                        <div
                            key={i}
                            className="flex flex-wrap items-center gap-2"
                        >
                            <select
                                aria-label={`Agent ${i + 1}`}
                                className="h-10 min-w-48 flex-1 rounded-md border bg-background px-3"
                                value={r.user}
                                disabled={r.user === primary}
                                onChange={(e) =>
                                    setRows(
                                        rows.map((x, j) =>
                                            j === i
                                                ? { ...x, user: e.target.value }
                                                : x,
                                        ),
                                    )
                                }
                            >
                                <option value="">Choose an agent…</option>
                                {(choices ?? []).map((c) => (
                                    <option key={c.ulid} value={c.ulid}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                            <span className="flex items-center gap-1">
                                <Input
                                    aria-label={`Agent ${i + 1} share (%)`}
                                    type="number"
                                    min={0}
                                    max={100}
                                    step="0.01"
                                    className="h-10 w-24"
                                    value={r.share}
                                    onChange={(e) =>
                                        setRows(
                                            rows.map((x, j) =>
                                                j === i
                                                    ? {
                                                          ...x,
                                                          share: e.target.value,
                                                      }
                                                    : x,
                                            ),
                                        )
                                    }
                                />
                                %
                            </span>
                            {r.user !== primary && (
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    aria-label="Remove this agent"
                                    onClick={() =>
                                        setRows(rows.filter((_, j) => j !== i))
                                    }
                                >
                                    <Trash2 aria-hidden="true" />
                                </Button>
                            )}
                        </div>
                    ))}
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                setRows([...rows, { user: '', share: '' }])
                            }
                        >
                            <Plus aria-hidden="true" /> Add an agent
                        </Button>
                        <span
                            className={`ml-auto ${Math.abs(total - 100) > 0.01 ? 'font-medium text-destructive' : 'text-muted-foreground'}`}
                        >
                            Shares add up to {total.toFixed(2)}% (must be 100%)
                        </span>
                    </div>
                    <p className="text-muted-foreground">
                        Each agent is paid on their own plan: their split or
                        flat fee, their cap and their fees. Revenue share is
                        paid on each agent&apos;s part.
                    </p>
                    <div className="flex gap-2">
                        <Button
                            type="submit"
                            size="sm"
                            disabled={
                                Math.abs(total - 100) > 0.01 ||
                                rows.some((r) => !r.user)
                            }
                        >
                            Save agents
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => setEditing(false)}
                        >
                            Cancel
                        </Button>
                    </div>
                </form>
            )}
        </section>
    );
}
