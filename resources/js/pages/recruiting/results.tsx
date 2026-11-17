import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Row = {
    source: string;
    label: string;
    added: number;
    met: number;
    joined: number;
    campaign_helped: number;
    gci_cents: number;
    spend_cents: number;
    cost_per_hire_cents: number | null;
};

const usd = (cents: number) =>
    (cents / 100).toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 0,
    });

function Spend({ year, row }: { year: number; row: Row }) {
    const [amount, setAmount] = useState(
        String(Math.round(row.spend_cents / 100)),
    );

    return (
        <form
            className="flex items-center gap-1"
            onSubmit={(e) => {
                e.preventDefault();
                router.post(
                    '/recruiting/results/spend',
                    { year, source: row.source, amount },
                    { preserveScroll: true },
                );
            }}
        >
            <Input
                aria-label={`Spend on ${row.label}`}
                className="w-28"
                inputMode="numeric"
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
            />
            <Button variant="outline" size="sm">
                Save
            </Button>
        </form>
    );
}

/** Recruiting results (RC3): by source — recruits, meetings, hires, what hires have closed since, and spend. */
export default function Results({
    year,
    rows,
    canSeeMoney,
}: {
    year: number;
    rows: Row[];
    canSeeMoney: boolean;
}) {
    const total = rows.reduce(
        (t, r) => ({
            added: t.added + r.added,
            joined: t.joined + r.joined,
            gci: t.gci + r.gci_cents,
            spend: t.spend + r.spend_cents,
        }),
        {
            added: 0,
            joined: 0,
            gci: 0,
            spend: 0,
        },
    );

    return (
        <>
            <Head title="Recruiting results" />
            <div className="grid gap-5 p-4 text-base">
                <PageHeader
                    title={`Recruiting results, ${year}`}
                    description="Where your recruits came from this year, who joined, and what they've closed since joining."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/recruiting">
                                    Back to recruiting
                                </Link>
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.get('/recruiting/results', {
                                        year: year - 1,
                                    })
                                }
                            >
                                {year - 1}
                            </Button>
                        </div>
                    }
                />
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr>
                                <th className="p-3 font-normal">Source</th>
                                <th className="p-3 font-normal">Recruits</th>
                                <th className="p-3 font-normal">Met</th>
                                <th className="p-3 font-normal">Joined</th>
                                <th className="p-3 font-normal">
                                    Helped by a campaign
                                </th>
                                {canSeeMoney && (
                                    <th className="p-3 font-normal">
                                        Commission closed since joining
                                    </th>
                                )}
                                {canSeeMoney && (
                                    <th className="p-3 font-normal">
                                        Spend this year
                                    </th>
                                )}
                                {canSeeMoney && (
                                    <th className="p-3 font-normal">
                                        Cost per hire
                                    </th>
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((r) => (
                                <tr key={r.source} className="border-t">
                                    <td className="p-3 font-medium">
                                        {r.label}
                                    </td>
                                    <td className="p-3">{r.added}</td>
                                    <td className="p-3">{r.met}</td>
                                    <td className="p-3">{r.joined}</td>
                                    <td className="p-3">{r.campaign_helped}</td>
                                    {canSeeMoney && (
                                        <td className="p-3">
                                            {usd(r.gci_cents)}
                                        </td>
                                    )}
                                    {canSeeMoney && (
                                        <td className="p-3">
                                            <Spend year={year} row={r} />
                                        </td>
                                    )}
                                    {canSeeMoney && (
                                        <td className="p-3">
                                            {r.cost_per_hire_cents === null
                                                ? '—'
                                                : usd(r.cost_per_hire_cents)}
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <p className="text-sm text-muted-foreground">
                    {total.added} recruits, {total.joined} joined
                    {canSeeMoney &&
                        `; ${usd(total.gci)} closed by them since joining against ${usd(total.spend)} spent`}
                    . Commission is from the deals your new agents have closed
                    here.
                </p>
            </div>
        </>
    );
}
