import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usd } from '@/lib/cents';

export type RevenueShareReportData = {
    from: string;
    to: string;
    levels: number;
    people: {
        name: string;
        total_cents: number;
        by_level: Record<string, number>;
        deals: number;
        missed_cents: number;
    }[];
    by_level: Record<string, number>;
    total_cents: number;
    missed_cents: number;
    deals: number;
};

/**
 * CM2: the revenue share report: for a period, what each person received at each level, from how many deals, and
 * what they missed, with a spreadsheet download.
 */
export function RevenueShareReport({
    report,
}: {
    report: RevenueShareReportData;
}) {
    const [from, setFrom] = useState(report.from);
    const [to, setTo] = useState(report.to);
    const levels = Array.from(
        { length: Math.max(1, report.levels) },
        (_, i) => i + 1,
    );
    const query = `from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;

    return (
        <section
            aria-labelledby="rs-report"
            className="grid gap-3 rounded-lg border p-4 text-sm"
        >
            <div className="flex flex-wrap items-end justify-between gap-3">
                <h2 id="rs-report" className="text-base font-semibold">
                    Revenue share report
                </h2>
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(
                            `/compensation/revenue-share?${query}`,
                            {},
                            { preserveScroll: true },
                        );
                    }}
                >
                    <label className="grid gap-1">
                        From
                        <Input
                            type="date"
                            value={from}
                            onChange={(e) => setFrom(e.target.value)}
                        />
                    </label>
                    <label className="grid gap-1">
                        To
                        <Input
                            type="date"
                            value={to}
                            onChange={(e) => setTo(e.target.value)}
                        />
                    </label>
                    <Button type="submit" variant="outline">
                        Show
                    </Button>
                    <Button asChild variant="ghost">
                        <a
                            href={`/compensation/revenue-share/report.csv?${query}`}
                        >
                            Download spreadsheet
                        </a>
                    </Button>
                </form>
            </div>
            <p className="text-muted-foreground">
                {usd(report.total_cents)} paid from {report.deals}{' '}
                {report.deals === 1 ? 'deal' : 'deals'};{' '}
                {usd(report.missed_cents)} not paid (locked levels, no sponsor,
                limits).
            </p>
            {report.people.length === 0 ? (
                <p className="rounded-md border border-dashed p-4 text-center text-muted-foreground">
                    No revenue share in this period.
                </p>
            ) : (
                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-left">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr>
                                <th className="px-3 py-2 font-medium">
                                    Person
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Received
                                </th>
                                {levels.map((l) => (
                                    <th
                                        key={l}
                                        className="px-3 py-2 text-right font-medium"
                                    >
                                        Level {l}
                                    </th>
                                ))}
                                <th className="px-3 py-2 text-right font-medium">
                                    Deals
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Missed
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {report.people.map((p) => (
                                <tr key={p.name} className="border-t">
                                    <td className="px-3 py-2 font-medium">
                                        {p.name}
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {usd(p.total_cents)}
                                    </td>
                                    {levels.map((l) => (
                                        <td
                                            key={l}
                                            className="px-3 py-2 text-right tabular-nums"
                                        >
                                            {p.by_level[l]
                                                ? usd(p.by_level[l])
                                                : '—'}
                                        </td>
                                    ))}
                                    <td className="px-3 py-2 text-right">
                                        {p.deals}
                                    </td>
                                    <td className="px-3 py-2 text-right text-muted-foreground tabular-nums">
                                        {p.missed_cents
                                            ? usd(p.missed_cents)
                                            : '—'}
                                    </td>
                                </tr>
                            ))}
                            <tr className="border-t bg-muted/30 font-semibold">
                                <td className="px-3 py-2">Total</td>
                                <td className="px-3 py-2 text-right tabular-nums">
                                    {usd(report.total_cents)}
                                </td>
                                {levels.map((l) => (
                                    <td
                                        key={l}
                                        className="px-3 py-2 text-right tabular-nums"
                                    >
                                        {usd(report.by_level[l] ?? 0)}
                                    </td>
                                ))}
                                <td className="px-3 py-2 text-right">
                                    {report.deals}
                                </td>
                                <td className="px-3 py-2 text-right tabular-nums">
                                    {usd(report.missed_cents)}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
