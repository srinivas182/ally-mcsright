import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { RulesForm, type Rules } from '@/components/compensation/rules-form';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';
import { postJson } from '@/lib/http';

type Row = {
    key: string;
    name?: string;
    deals: number;
    gci_cents: number;
    company_cents: number;
    net_company_cents: number;
};
type Props = {
    year: number;
    report: {
        plans: Row[];
        agents: Row[];
        totals: {
            deals: number;
            gci_cents: number;
            company_cents: number;
            net_company_cents: number;
        };
    };
};
type WhatIf = {
    agents: {
        user_id: number;
        name: string;
        deals: number;
        actual_company_cents: number;
        proposed_company_cents: number;
        actual_agent_cents: number;
        proposed_agent_cents: number;
    }[];
    actual_company_cents: number;
    proposed_company_cents: number;
    deals: number;
};

function Table({ rows, label }: { rows: Row[]; label: string }) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="p-2">{label}</th>
                        <th className="p-2">Deals</th>
                        <th className="p-2">GCI</th>
                        <th className="p-2">Company dollar</th>
                        <th className="p-2">
                            After brokerage-paid fees and credits
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((r) => (
                        <tr key={r.key} className="border-t">
                            <td className="p-2">{r.name ?? r.key}</td>
                            <td className="p-2">{r.deals}</td>
                            <td className="p-2">{usd(r.gci_cents)}</td>
                            <td className="p-2">{usd(r.company_cents)}</td>
                            <td className="p-2">{usd(r.net_company_cents)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** Company dollar by plan and agent, and what-if: last 12 months replayed through a proposed plan. */
export default function Profitability({ year, report }: Props) {
    const [rules, setRules] = useState<Rules>({
        split: { mode: 'percentage', agent_percent: '80' },
        cap: { amount_cents: 1_600_000, anchor: 'anniversary' },
    });
    const [result, setResult] = useState<WhatIf | null>(null);
    const [error, setError] = useState<string | null>(null);

    return (
        <>
            <Head title="Plan profitability" />
            <div className="flex flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Plan profitability"
                    description={`Closed deals in ${year}: ${report.totals.deals} · company dollar ${usd(report.totals.company_cents)}`}
                    actions={
                        <select
                            aria-label="Year"
                            className="rounded border bg-background px-2 py-1.5"
                            value={year}
                            onChange={(e) =>
                                router.get('/compensation/profitability', {
                                    year: e.target.value,
                                })
                            }
                        >
                            {[0, 1, 2].map((d) => (
                                <option
                                    key={d}
                                    value={new Date().getFullYear() - d}
                                >
                                    {new Date().getFullYear() - d}
                                </option>
                            ))}
                        </select>
                    }
                />
                <Table rows={report.plans} label="Plan" />
                <Table rows={report.agents} label="Agent" />
                <section className="grid gap-3 rounded-lg border p-4">
                    <h2 className="font-semibold">
                        What if we changed the plan?
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Replays every deal closed in the last 12 months through
                        the plan below, with caps progressing deal by deal.
                        Nothing is saved.
                    </p>
                    <RulesForm value={rules} onChange={setRules} fees={[]} />
                    <Button
                        className="justify-self-start"
                        onClick={() => {
                            setError(null);
                            postJson<WhatIf>('/compensation/what-if', { rules })
                                .then(setResult)
                                .catch((e: Error) => setError(e.message));
                        }}
                    >
                        Run what-if
                    </Button>
                    {error && (
                        <p role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    )}
                    {result && (
                        <>
                            <p className="text-sm">
                                {result.deals} deals: company dollar would be{' '}
                                <strong>
                                    {usd(result.proposed_company_cents)}
                                </strong>{' '}
                                instead of {usd(result.actual_company_cents)} (
                                {result.proposed_company_cents >=
                                result.actual_company_cents
                                    ? '+'
                                    : ''}
                                {usd(
                                    result.proposed_company_cents -
                                        result.actual_company_cents,
                                )}
                                ).
                            </p>
                            <div className="overflow-x-auto rounded-lg border">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-left">
                                        <tr>
                                            <th className="p-2">Agent</th>
                                            <th className="p-2">Deals</th>
                                            <th className="p-2">
                                                Agent take-home now → proposed
                                            </th>
                                            <th className="p-2">
                                                Company dollar now → proposed
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {result.agents.map((a) => (
                                            <tr
                                                key={a.user_id}
                                                className="border-t"
                                            >
                                                <td className="p-2">
                                                    {a.name}
                                                </td>
                                                <td className="p-2">
                                                    {a.deals}
                                                </td>
                                                <td className="p-2">
                                                    {usd(a.actual_agent_cents)}{' '}
                                                    →{' '}
                                                    {usd(
                                                        a.proposed_agent_cents,
                                                    )}
                                                </td>
                                                <td className="p-2">
                                                    {usd(
                                                        a.actual_company_cents,
                                                    )}{' '}
                                                    →{' '}
                                                    {usd(
                                                        a.proposed_company_cents,
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </>
                    )}
                </section>
            </div>
        </>
    );
}

Profitability.layout = {
    breadcrumbs: [
        { title: 'Plan profitability', href: '/compensation/profitability' },
    ],
};
