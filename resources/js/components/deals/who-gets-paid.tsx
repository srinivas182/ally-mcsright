import { ChevronDown } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { usd } from '@/lib/cents';

export type WhoGetsPaidData = {
    status: 'final' | 'estimate';
    version: number;
    gci_cents: number | null;
    outside_referral_cents: number | null;
    agents: (
        | {
              name: string;
              plan: string | null;
              share_cents: number;
              fees: { name: string; cents: number }[];
              team_split_cents: number;
              credit_cents: number;
              net_cents: number;
              company_cents: number;
              capped: boolean;
              hidden: false;
          }
        | { name: string; plan: string | null; hidden: true }
    )[];
    company_cents: number | null;
    revenue_share_cents: number;
    brokerage_keeps_cents: number | null;
    levels: {
        level: number;
        percent: string;
        who: string;
        kind: string;
        amount_cents: number;
        note: string | null;
        from: string;
    }[];
};

function Row({
    label,
    value,
    strong = false,
    minus = false,
}: {
    label: string;
    value: number;
    strong?: boolean;
    minus?: boolean;
}) {
    return (
        <div
            className={`flex justify-between gap-4 py-1 ${strong ? 'font-semibold' : ''}`}
        >
            <span>{label}</span>
            <span className="tabular-nums">
                {minus && value > 0 ? '− ' : ''}
                {usd(value)}
            </span>
        </div>
    );
}

/**
 * CM2: "Who gets paid" on a transaction: the commission, each agent's share and fees, what they take home, the
 * brokerage's company dollar and the revenue share level by level. Closed deals show what was booked; open deals
 * show an estimate.
 */
export function WhoGetsPaid({ data }: { data: WhoGetsPaidData }) {
    const [open, setOpen] = useState(true);
    const sources = [...new Set(data.levels.map((l) => l.from))];

    return (
        <section
            aria-labelledby="who-gets-paid"
            className="grid gap-3 rounded-lg border p-4 text-sm"
        >
            <button
                type="button"
                className="flex items-center gap-2 text-left"
                aria-expanded={open}
                onClick={() => setOpen(!open)}
            >
                <h2 id="who-gets-paid" className="text-base font-semibold">
                    Who gets paid
                </h2>
                <Badge
                    variant={data.status === 'final' ? 'secondary' : 'outline'}
                >
                    {data.status === 'final' ? 'Booked at closing' : 'Estimate'}
                </Badge>
                <ChevronDown
                    aria-hidden="true"
                    className={`ml-auto size-4 transition-transform ${open ? 'rotate-180' : ''}`}
                />
            </button>
            {open && (
                <>
                    {data.status === 'estimate' && (
                        <p className="text-muted-foreground">
                            An estimate from today&apos;s plan, caps and
                            network. The final split is fixed when the deal
                            closes.
                        </p>
                    )}
                    {data.gci_cents !== null && (
                        <div className="rounded-md bg-muted/40 px-3 py-2">
                            <Row
                                label="Commission (GCI)"
                                value={data.gci_cents}
                                strong
                            />
                            {(data.outside_referral_cents ?? 0) > 0 && (
                                <Row
                                    label="Referral fee to another brokerage"
                                    value={data.outside_referral_cents ?? 0}
                                    minus
                                />
                            )}
                        </div>
                    )}
                    <div className="grid gap-3 md:grid-cols-2">
                        {data.agents.map((a) => (
                            <div
                                key={a.name}
                                className="rounded-md border px-3 py-2"
                            >
                                <p className="font-semibold">
                                    {a.name}
                                    {a.plan && (
                                        <span className="font-normal text-muted-foreground">
                                            {' '}
                                            · {a.plan}
                                        </span>
                                    )}
                                    {!a.hidden && a.capped && (
                                        <Badge
                                            variant="secondary"
                                            className="ml-2"
                                        >
                                            Capped
                                        </Badge>
                                    )}
                                </p>
                                {a.hidden ? (
                                    <p className="text-muted-foreground">
                                        Amounts hidden
                                    </p>
                                ) : (
                                    <>
                                        <Row
                                            label="Their part of the commission"
                                            value={a.share_cents}
                                        />
                                        <Row
                                            label="Brokerage share (company dollar)"
                                            value={a.company_cents}
                                            minus
                                        />
                                        {a.team_split_cents > 0 && (
                                            <Row
                                                label="Team leader's split"
                                                value={a.team_split_cents}
                                                minus
                                            />
                                        )}
                                        {a.fees.map((f) => (
                                            <Row
                                                key={f.name}
                                                label={f.name}
                                                value={f.cents}
                                                minus
                                            />
                                        ))}
                                        {a.credit_cents > 0 && (
                                            <Row
                                                label="Credit used"
                                                value={a.credit_cents}
                                            />
                                        )}
                                        <div className="mt-1 border-t pt-1">
                                            <Row
                                                label="Takes home"
                                                value={a.net_cents}
                                                strong
                                            />
                                        </div>
                                    </>
                                )}
                            </div>
                        ))}
                    </div>
                    {data.company_cents !== null && (
                        <div className="rounded-md border px-3 py-2">
                            <p className="font-semibold">Brokerage</p>
                            <Row
                                label="Company dollar"
                                value={data.company_cents}
                            />
                            <Row
                                label="Revenue share paid out"
                                value={data.revenue_share_cents}
                                minus
                            />
                            <div className="mt-1 border-t pt-1">
                                <Row
                                    label="Brokerage keeps"
                                    value={data.brokerage_keeps_cents ?? 0}
                                    strong
                                />
                            </div>
                        </div>
                    )}
                    {data.levels.length > 0 ? (
                        <div className="grid gap-2">
                            <p className="font-semibold">
                                Revenue share, level by level
                            </p>
                            {sources.map((from) => (
                                <div
                                    key={from}
                                    className="overflow-x-auto rounded-md border"
                                >
                                    <table className="w-full text-left">
                                        <caption className="px-3 pt-2 text-left text-muted-foreground">
                                            From {from}&apos;s deal
                                        </caption>
                                        <thead className="text-muted-foreground">
                                            <tr>
                                                <th className="px-3 py-2 font-medium">
                                                    Level
                                                </th>
                                                <th className="px-3 py-2 font-medium">
                                                    Who
                                                </th>
                                                <th className="px-3 py-2 font-medium">
                                                    Share
                                                </th>
                                                <th className="px-3 py-2 text-right font-medium">
                                                    Amount
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {data.levels
                                                .filter((l) => l.from === from)
                                                .map((l, i) => (
                                                    <tr
                                                        key={`${l.level}-${i}`}
                                                        className="border-t"
                                                    >
                                                        <td className="px-3 py-2">
                                                            {l.level}
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            <span
                                                                className={
                                                                    l.kind ===
                                                                    'missed'
                                                                        ? 'text-muted-foreground'
                                                                        : ''
                                                                }
                                                            >
                                                                {l.who}
                                                            </span>
                                                            {l.note && (
                                                                <span className="block text-xs text-muted-foreground">
                                                                    {l.note}
                                                                </span>
                                                            )}
                                                        </td>
                                                        <td className="px-3 py-2">
                                                            {l.percent}%
                                                        </td>
                                                        <td className="px-3 py-2 text-right tabular-nums">
                                                            {usd(
                                                                l.amount_cents,
                                                            )}
                                                        </td>
                                                    </tr>
                                                ))}
                                        </tbody>
                                    </table>
                                </div>
                            ))}
                        </div>
                    ) : data.revenue_share_cents > 0 ? (
                        <Row
                            label="Revenue share paid to the upline"
                            value={data.revenue_share_cents}
                        />
                    ) : null}
                </>
            )}
        </section>
    );
}
