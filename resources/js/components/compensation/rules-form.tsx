import { Minus, Plus } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

export type Rules = Record<string, unknown>;

type Split = {
    mode?: string;
    agent_percent?: string;
    bands?: { up_to_price_cents: number | null; fee_cents: number }[];
};
type Cap = {
    amount_cents?: number;
    anchor?: string;
    anchor_month_day?: string;
};
type PostCap = { fee?: { calc?: string; amount?: number | string } };
type RevenueShare = {
    basis?: string;
    levels?: string[];
    level_caps_cents?: (number | null)[];
    unlock?: {
        open_levels?: number | null;
        thresholds?: Record<string, number>;
        qualifying?: {
            min_closings?: number;
            min_gci_cents?: number;
            window_months?: number;
        };
    };
    [key: string]: unknown;
};

const dollars = (cents: number | null | undefined) =>
    cents === null || cents === undefined ? '' : String(cents / 100);
const cents = (v: string) =>
    v.trim() === '' ? null : Math.max(0, Math.round(Number(v) * 100));

/** The referrals each level needs, from the plan's unlock rules (0 = open from the start). */
function requiredPerLevel(rs: RevenueShare, count: number): number[] {
    const open = rs.unlock?.open_levels;
    const thresholds = rs.unlock?.thresholds ?? {};

    return Array.from({ length: count }, (_, i) => {
        const level = i + 1;
        if (open === null || open === undefined || level <= open) {
            return 0;
        }
        let needed = 0;
        Object.entries(thresholds).forEach(([l, n]) => {
            if (Number(l) <= level) {
                needed = n;
            }
        });

        return needed;
    });
}

/** Back to unlock rules: levels with 0 required are open; each later level needs its number of referrals. */
function unlockFrom(required: number[], keep: RevenueShare['unlock']) {
    const open = required.findIndex((r) => r > 0);
    if (open === -1) {
        return keep?.qualifying ? { qualifying: keep.qualifying } : undefined;
    }
    const thresholds: Record<string, number> = {};
    required.forEach((r, i) => {
        if (i >= open) {
            thresholds[String(i + 1)] = Math.max(
                r,
                ...required.slice(open, i + 1),
            );
        }
    });

    return { ...keep, open_levels: open, thresholds };
}

/**
 * CM3: the plan builder. A plan is either a revenue share plan (the agent keeps a percentage) or a flat fee plan
 * (the brokerage takes a set fee per deal); both can have a cap, a fee after capping and catalogue fees, and
 * revenue share can be paid level by level to the people who brought the agent in (with a yearly maximum and the
 * referrals needed for each level). "Advanced (JSON)" keeps every other rule.
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
    const flat = split.mode === 'flat_fee';
    const cap = (value.cap ?? null) as Cap | null;
    const postCap = (value.post_cap ?? null) as PostCap | null;
    const chosen = (value.fees ?? []) as string[];
    const rs = (value.revenue_share ?? null) as RevenueShare | null;
    const levels = rs?.levels ?? [];
    const levelCaps = rs?.level_caps_cents ?? [];
    const required = rs ? requiredPerLevel(rs, levels.length) : [];
    const set = (patch: Rules) => {
        const next = { ...value, ...patch };
        Object.keys(next).forEach(
            (k) => next[k] === undefined && delete next[k],
        );
        onChange(next);
        setJson(JSON.stringify(next, null, 2));
    };
    const setRs = (patch: Partial<RevenueShare>) =>
        set({ revenue_share: { ...rs, ...patch } });
    const setLevels = (
        nextLevels: string[],
        nextCaps: (number | null)[],
        nextRequired: number[],
    ) =>
        setRs({
            levels: nextLevels,
            level_caps_cents: nextCaps.some((c) => c !== null)
                ? nextCaps
                : undefined,
            unlock: unlockFrom(nextRequired, rs?.unlock),
        });
    const total = levels.reduce((s, l) => s + (Number(l) || 0), 0);
    const target =
        rs?.basis === 'gci' && !flat
            ? 100 - (Number(split.agent_percent) || 0)
            : 100;

    return (
        <div className="grid gap-4 text-sm">
            <label className="flex items-center gap-2">
                <input
                    type="checkbox"
                    className="size-4"
                    checked={advanced}
                    onChange={(e) => setAdvanced(e.target.checked)}
                />
                Advanced (JSON): tiers, price bands, deal classes, lead sources,
                deductions
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
                    <fieldset className="grid gap-2">
                        <legend className="mb-1 font-semibold">
                            Plan type
                        </legend>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {[
                                [
                                    'percentage',
                                    'Revenue share plan',
                                    'The agent keeps a percentage of each commission; the brokerage keeps the rest.',
                                ],
                                [
                                    'flat_fee',
                                    'Flat fee plan',
                                    'The brokerage takes a set fee per deal; the agent keeps everything else.',
                                ],
                            ].map(([mode, title, text]) => (
                                <label
                                    key={mode}
                                    className={`flex cursor-pointer gap-3 rounded-lg border p-3 ${
                                        (mode === 'flat_fee') === flat
                                            ? 'border-primary bg-primary/5'
                                            : ''
                                    }`}
                                >
                                    <input
                                        type="radio"
                                        name="plan-type"
                                        className="mt-1 size-4"
                                        checked={(mode === 'flat_fee') === flat}
                                        onChange={() =>
                                            set({
                                                split:
                                                    mode === 'flat_fee'
                                                        ? {
                                                              mode: 'flat_fee',
                                                              bands: [
                                                                  {
                                                                      up_to_price_cents:
                                                                          null,
                                                                      fee_cents: 50_000,
                                                                  },
                                                              ],
                                                          }
                                                        : {
                                                              mode: 'percentage',
                                                              agent_percent:
                                                                  '80',
                                                          },
                                            })
                                        }
                                    />
                                    <span>
                                        <span className="block font-semibold">
                                            {title}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {text}
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </div>
                    </fieldset>

                    {flat ? (
                        <label className="flex flex-wrap items-center gap-2">
                            The brokerage takes
                            <span className="flex items-center gap-1">
                                $
                                <Input
                                    aria-label="Flat fee per deal ($)"
                                    type="number"
                                    min={0}
                                    className="w-28"
                                    value={dollars(
                                        split.bands?.[0]?.fee_cents ?? 0,
                                    )}
                                    onChange={(e) =>
                                        set({
                                            split: {
                                                mode: 'flat_fee',
                                                bands: [
                                                    {
                                                        up_to_price_cents: null,
                                                        fee_cents:
                                                            cents(
                                                                e.target.value,
                                                            ) ?? 0,
                                                    },
                                                ],
                                            },
                                        })
                                    }
                                />
                            </span>
                            per deal; the agent keeps the rest of their
                            commission.
                        </label>
                    ) : (
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
                            % of each commission; the brokerage keeps{' '}
                            {Math.max(
                                0,
                                100 - (Number(split.agent_percent) || 0),
                            )}
                            %.
                        </label>
                    )}

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
                        Cap what the brokerage takes at $
                        <Input
                            aria-label="Cap amount ($)"
                            type="number"
                            min={1}
                            className="w-32"
                            disabled={cap === null}
                            value={cap ? dollars(cap.amount_cents ?? 0) : ''}
                            onChange={(e) =>
                                set({
                                    cap: {
                                        ...cap,
                                        amount_cents:
                                            cents(e.target.value) ?? 0,
                                    },
                                })
                            }
                        />
                        per cap year, starting on the
                        <select
                            aria-label="Cap year starts"
                            className="h-9 rounded-md border bg-background px-2"
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
                                agent&apos;s anniversary
                            </option>
                            <option value="fixed">1 January</option>
                        </select>
                    </label>
                    {cap !== null && (
                        <label className="flex flex-wrap items-center gap-2 pl-6">
                            After capping, the agent pays $
                            <Input
                                aria-label="Post-cap fee ($)"
                                type="number"
                                min={0}
                                className="w-28"
                                value={
                                    postCap?.fee?.amount !== undefined
                                        ? dollars(Number(postCap.fee.amount))
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
                                                          amount:
                                                              cents(
                                                                  e.target
                                                                      .value,
                                                              ) ?? 0,
                                                      },
                                                  },
                                    })
                                }
                            />
                            per deal (leave empty for none)
                        </label>
                    )}

                    {fees.length > 0 && (
                        <fieldset className="grid gap-1">
                            <legend className="mb-1 font-semibold">
                                Fees from the fee list
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

                    <fieldset className="grid gap-3 rounded-lg border p-3">
                        <legend className="px-1 font-semibold">
                            Revenue share
                        </legend>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={rs !== null}
                                onChange={(e) =>
                                    set({
                                        revenue_share: e.target.checked
                                            ? {
                                                  basis: 'net_profit',
                                                  levels: ['50', '30', '20'],
                                              }
                                            : undefined,
                                    })
                                }
                            />
                            Share part of the brokerage&apos;s earnings with the
                            agents who brought this agent in
                        </label>
                        {rs !== null && (
                            <>
                                <label className="flex flex-wrap items-center gap-2">
                                    Share it from
                                    <select
                                        aria-label="Revenue share basis"
                                        className="h-9 rounded-md border bg-background px-2"
                                        value={rs.basis ?? 'net_profit'}
                                        onChange={(e) =>
                                            setRs({ basis: e.target.value })
                                        }
                                    >
                                        <option value="net_profit">
                                            Net profit (levels add up to 100%)
                                        </option>
                                        <option value="company_dollar">
                                            Company dollar (levels add up to
                                            100%)
                                        </option>
                                        {!flat && (
                                            <option value="gci">
                                                Gross commission (levels add up
                                                to the company %)
                                            </option>
                                        )}
                                    </select>
                                </label>
                                <div className="overflow-x-auto rounded-md border">
                                    <table className="w-full text-left">
                                        <thead className="bg-muted/50 text-muted-foreground">
                                            <tr>
                                                <th className="px-3 py-2 font-medium">
                                                    Level
                                                </th>
                                                <th className="px-3 py-2 font-medium">
                                                    % share
                                                </th>
                                                <th className="px-3 py-2 font-medium">
                                                    Yearly maximum per person
                                                </th>
                                                <th className="px-3 py-2 font-medium">
                                                    Referrals needed
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {levels.map((pct, i) => (
                                                <tr
                                                    key={i}
                                                    className="border-t"
                                                >
                                                    <td className="px-3 py-2 font-medium">
                                                        Level {i + 1}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <Input
                                                            aria-label={`Level ${i + 1} percent`}
                                                            type="number"
                                                            min={0}
                                                            max={100}
                                                            className="w-24"
                                                            value={pct}
                                                            onChange={(e) =>
                                                                setLevels(
                                                                    levels.map(
                                                                        (
                                                                            l,
                                                                            j,
                                                                        ) =>
                                                                            j ===
                                                                            i
                                                                                ? e
                                                                                      .target
                                                                                      .value
                                                                                : l,
                                                                    ),
                                                                    levels.map(
                                                                        (
                                                                            _,
                                                                            j,
                                                                        ) =>
                                                                            levelCaps[
                                                                                j
                                                                            ] ??
                                                                            null,
                                                                    ),
                                                                    required,
                                                                )
                                                            }
                                                        />
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <span className="flex items-center gap-1">
                                                            $
                                                            <Input
                                                                aria-label={`Level ${i + 1} yearly maximum ($)`}
                                                                type="number"
                                                                min={0}
                                                                placeholder="No limit"
                                                                className="w-32"
                                                                value={dollars(
                                                                    levelCaps[
                                                                        i
                                                                    ],
                                                                )}
                                                                onChange={(e) =>
                                                                    setLevels(
                                                                        levels,
                                                                        levels.map(
                                                                            (
                                                                                _,
                                                                                j,
                                                                            ) =>
                                                                                j ===
                                                                                i
                                                                                    ? cents(
                                                                                          e
                                                                                              .target
                                                                                              .value,
                                                                                      )
                                                                                    : (levelCaps[
                                                                                          j
                                                                                      ] ??
                                                                                      null),
                                                                        ),
                                                                        required,
                                                                    )
                                                                }
                                                            />
                                                        </span>
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <Input
                                                            aria-label={`Level ${i + 1} referrals needed`}
                                                            type="number"
                                                            min={0}
                                                            className="w-24"
                                                            value={required[i]}
                                                            onChange={(e) =>
                                                                setLevels(
                                                                    levels,
                                                                    levels.map(
                                                                        (
                                                                            _,
                                                                            j,
                                                                        ) =>
                                                                            levelCaps[
                                                                                j
                                                                            ] ??
                                                                            null,
                                                                    ),
                                                                    required.map(
                                                                        (
                                                                            r,
                                                                            j,
                                                                        ) =>
                                                                            j ===
                                                                            i
                                                                                ? Math.max(
                                                                                      0,
                                                                                      Math.round(
                                                                                          Number(
                                                                                              e
                                                                                                  .target
                                                                                                  .value,
                                                                                          ) ||
                                                                                              0,
                                                                                      ),
                                                                                  )
                                                                                : r,
                                                                    ),
                                                                )
                                                            }
                                                        />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    {levels.length < 15 && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setLevels(
                                                    [...levels, '0'],
                                                    [
                                                        ...levels.map(
                                                            (_, j) =>
                                                                levelCaps[j] ??
                                                                null,
                                                        ),
                                                        null,
                                                    ],
                                                    [
                                                        ...required,
                                                        required[
                                                            required.length - 1
                                                        ] ?? 0,
                                                    ],
                                                )
                                            }
                                        >
                                            <Plus aria-hidden="true" /> Add a
                                            level
                                        </Button>
                                    )}
                                    {levels.length > 1 && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                setLevels(
                                                    levels.slice(0, -1),
                                                    levels
                                                        .slice(0, -1)
                                                        .map(
                                                            (_, j) =>
                                                                levelCaps[j] ??
                                                                null,
                                                        ),
                                                    required.slice(0, -1),
                                                )
                                            }
                                        >
                                            <Minus aria-hidden="true" /> Remove
                                            the last level
                                        </Button>
                                    )}
                                    <span
                                        className={`ml-auto ${
                                            Math.abs(total - target) > 0.01
                                                ? 'font-medium text-destructive'
                                                : 'text-muted-foreground'
                                        }`}
                                    >
                                        Levels add up to {total.toFixed(2)}% (
                                        must be {target}%)
                                    </span>
                                </div>
                                {required.some((r) => r > 0) && (
                                    <p className="flex flex-wrap items-center gap-2 text-muted-foreground">
                                        A referral counts when they closed
                                        <Input
                                            aria-label="Deals a referral must close"
                                            type="number"
                                            min={0}
                                            className="h-8 w-16"
                                            value={
                                                rs.unlock?.qualifying
                                                    ?.min_closings ?? 2
                                            }
                                            onChange={(e) =>
                                                setRs({
                                                    unlock: {
                                                        ...rs.unlock,
                                                        qualifying: {
                                                            ...rs.unlock
                                                                ?.qualifying,
                                                            min_closings:
                                                                Math.max(
                                                                    0,
                                                                    Math.round(
                                                                        Number(
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        ) || 0,
                                                                    ),
                                                                ),
                                                        },
                                                    },
                                                })
                                            }
                                        />
                                        deals in the last
                                        <Input
                                            aria-label="Months counted"
                                            type="number"
                                            min={1}
                                            className="h-8 w-16"
                                            value={
                                                rs.unlock?.qualifying
                                                    ?.window_months ?? 6
                                            }
                                            onChange={(e) =>
                                                setRs({
                                                    unlock: {
                                                        ...rs.unlock,
                                                        qualifying: {
                                                            ...rs.unlock
                                                                ?.qualifying,
                                                            window_months:
                                                                Math.max(
                                                                    1,
                                                                    Math.round(
                                                                        Number(
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        ) || 6,
                                                                    ),
                                                                ),
                                                        },
                                                    },
                                                })
                                            }
                                        />
                                        months. Ranks can open levels too.
                                    </p>
                                )}
                            </>
                        )}
                    </fieldset>
                </>
            )}
        </div>
    );
}
