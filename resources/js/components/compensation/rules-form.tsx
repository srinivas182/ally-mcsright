import { useState } from 'react';

import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

export type Rules = Record<string, unknown>;

type Split = { mode?: string; agent_percent?: string };
type Cap = {
    amount_cents?: number;
    anchor?: string;
    anchor_month_day?: string;
};
type PostCap = { fee?: { calc?: string; amount?: number | string } };

/**
 * Plain fields for the common plan (agent %, cap, post-cap fee, catalogue fees); "Advanced (JSON)"
 * for everything else. The server validates both with plain-English messages.
 */
export function RulesForm({
    value,
    onChange,
    fees,
}: {
    value: Rules;
    onChange: (r: Rules) => void;
    fees: { code: string; name: string }[];
}) {
    const [advanced, setAdvanced] = useState(false);
    const [json, setJson] = useState(JSON.stringify(value, null, 2));
    const [jsonError, setJsonError] = useState<string | null>(null);
    const split = (value.split ?? {}) as Split;
    const cap = (value.cap ?? null) as Cap | null;
    const postCap = (value.post_cap ?? null) as PostCap | null;
    const chosen = (value.fees ?? []) as string[];
    const set = (patch: Rules) => {
        const next = { ...value, ...patch };
        Object.keys(next).forEach(
            (k) => next[k] === undefined && delete next[k],
        );
        onChange(next);
        setJson(JSON.stringify(next, null, 2));
    };

    return (
        <div className="grid gap-3 text-sm">
            <label className="flex items-center gap-2">
                <input
                    type="checkbox"
                    className="size-4"
                    checked={advanced}
                    onChange={(e) => setAdvanced(e.target.checked)}
                />
                Advanced (JSON): tiers, flat-fee bands, deal classes, lead
                sources, deductions
            </label>
            {advanced ? (
                <>
                    <Textarea
                        aria-label="Plan rules (JSON)"
                        className="min-h-64 font-mono text-xs"
                        value={json}
                        onChange={(e) => {
                            setJson(e.target.value);
                            try {
                                onChange(JSON.parse(e.target.value) as Rules);
                                setJsonError(null);
                            } catch {
                                setJsonError('That is not valid JSON yet.');
                            }
                        }}
                    />
                    {jsonError && (
                        <p className="text-destructive">{jsonError}</p>
                    )}
                </>
            ) : (
                <>
                    <label className="flex flex-wrap items-center gap-2">
                        Agent keeps
                        <Input
                            aria-label="Agent percent"
                            type="number"
                            min={0}
                            max={100}
                            className="w-24"
                            value={split.agent_percent ?? ''}
                            onChange={(e) =>
                                set({
                                    split: {
                                        mode: 'percentage',
                                        agent_percent: e.target.value,
                                    },
                                })
                            }
                        />
                        % of each deal (brokerage keeps the rest)
                    </label>
                    <label className="flex flex-wrap items-center gap-2">
                        <input
                            type="checkbox"
                            className="size-4"
                            checked={cap !== null}
                            onChange={(e) =>
                                set({
                                    cap: e.target.checked
                                        ? {
                                              amount_cents: 1_600_000,
                                              anchor: 'anniversary',
                                          }
                                        : undefined,
                                    post_cap: e.target.checked
                                        ? (postCap ?? undefined)
                                        : undefined,
                                })
                            }
                        />
                        Cap the brokerage's share at
                        <Input
                            aria-label="Cap amount ($)"
                            type="number"
                            min={1}
                            className="w-32"
                            disabled={cap === null}
                            value={cap ? (cap.amount_cents ?? 0) / 100 : ''}
                            onChange={(e) =>
                                set({
                                    cap: {
                                        ...cap,
                                        amount_cents: Math.round(
                                            Number(e.target.value) * 100,
                                        ),
                                    },
                                })
                            }
                        />
                        $ per cap year, starting on the
                        <select
                            aria-label="Cap year starts"
                            className="rounded border bg-background px-2 py-1"
                            disabled={cap === null}
                            value={cap?.anchor ?? 'anniversary'}
                            onChange={(e) =>
                                set({
                                    cap: {
                                        ...cap,
                                        anchor: e.target.value,
                                        anchor_month_day:
                                            e.target.value === 'fixed'
                                                ? '01-01'
                                                : undefined,
                                    },
                                })
                            }
                        >
                            <option value="anniversary">
                                agent's anniversary
                            </option>
                            <option value="fixed">1 January</option>
                        </select>
                    </label>
                    {cap !== null && (
                        <label className="flex flex-wrap items-center gap-2">
                            After capping, charge
                            <Input
                                aria-label="Post-cap fee ($)"
                                type="number"
                                min={0}
                                className="w-28"
                                value={
                                    postCap?.fee?.amount !== undefined
                                        ? Number(postCap.fee.amount) / 100
                                        : ''
                                }
                                onChange={(e) =>
                                    set({
                                        post_cap:
                                            e.target.value === ''
                                                ? undefined
                                                : {
                                                      fee: {
                                                          calc: 'flat',
                                                          amount: Math.round(
                                                              Number(
                                                                  e.target
                                                                      .value,
                                                              ) * 100,
                                                          ),
                                                      },
                                                  },
                                    })
                                }
                            />
                            $ per deal (leave empty for none)
                        </label>
                    )}
                    {fees.length > 0 && (
                        <fieldset className="grid gap-1">
                            <legend className="mb-1">
                                Fees from the catalogue
                            </legend>
                            {fees.map((f) => (
                                <label
                                    key={f.code}
                                    className="flex items-center gap-2"
                                >
                                    <input
                                        type="checkbox"
                                        className="size-4"
                                        checked={chosen.includes(f.code)}
                                        onChange={(e) =>
                                            set({
                                                fees: e.target.checked
                                                    ? [...chosen, f.code]
                                                    : chosen.filter(
                                                          (c) => c !== f.code,
                                                      ),
                                            })
                                        }
                                    />
                                    {f.name}
                                </label>
                            ))}
                        </fieldset>
                    )}
                </>
            )}
        </div>
    );
}
