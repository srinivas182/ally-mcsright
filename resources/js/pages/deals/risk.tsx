import { Head, Link } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { useState } from 'react';

type Risk = {
    level: string;
    category: string;
    title: string;
    deal: string | null;
    street: string | null;
    owner: string;
    due: string | null;
    link: string;
};
type Props = {
    risks: Risk[];
    trend: { week: string; open: number; opened: number; resolved: number }[];
    counts: { high: number; medium: number };
};

/** The broker risk dashboard: every open risk, its level, owner and due date; trend; export for insurers. */
export default function RiskDashboard({ risks, trend, counts }: Props) {
    const [category, setCategory] = useState('');
    const categories = [...new Set(risks.map((r) => r.category))].sort();
    const shown = category
        ? risks.filter((r) => r.category === category)
        : risks;
    const peak = Math.max(1, ...trend.map((t) => t.open));

    return (
        <>
            <Head title="Risk" />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Risk"
                    description="Every open risk in the brokerage: compliance blockers, missed and at-risk dates, licence and E&O lapses, wire-fraud flags and late reviews."
                    actions={
                        <Button variant="outline" asChild>
                            <a href="/deals/risk.csv">
                                Export for insurers (CSV)
                            </a>
                        </Button>
                    }
                />
                <div className="flex flex-wrap gap-3 text-sm">
                    <Badge variant="destructive">{counts.high} high</Badge>
                    <Badge variant="outline">{counts.medium} medium</Badge>
                </div>

                <section className="grid gap-2 text-sm">
                    <h2 className="font-semibold">Open risks, last 8 weeks</h2>
                    <div
                        className="flex h-24 items-end gap-2"
                        role="img"
                        aria-label={`Open risks by week: ${trend.map((t) => t.open).join(', ')}`}
                    >
                        {trend.map((t) => (
                            <div
                                key={t.week}
                                className="flex flex-1 flex-col items-center gap-1"
                            >
                                <div
                                    className="w-full rounded-t bg-primary/70"
                                    style={{
                                        height: `${(t.open / peak) * 72 + 2}px`,
                                    }}
                                    title={`${t.week}: ${t.open} open, ${t.opened} new, ${t.resolved} fixed`}
                                />
                                <span className="text-[10px] text-muted-foreground">
                                    {t.week.slice(5)}
                                </span>
                            </div>
                        ))}
                    </div>
                </section>

                <section className="grid gap-2 text-sm">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="font-semibold">Register</h2>
                        <select
                            aria-label="Category"
                            className="ml-auto rounded border bg-background px-2 py-1.5"
                            value={category}
                            onChange={(e) => setCategory(e.target.value)}
                        >
                            <option value="">All categories</option>
                            {categories.map((c) => (
                                <option key={c} value={c}>
                                    {c}
                                </option>
                            ))}
                        </select>
                    </div>
                    {shown.length === 0 ? (
                        <p className="text-muted-foreground">No open risks.</p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {shown.map((r, i) => (
                                <li
                                    key={i}
                                    className="grid gap-1 p-2 md:grid-cols-[6rem_8rem_1fr_9rem_6rem] md:items-center"
                                >
                                    <Badge
                                        variant={
                                            r.level === 'high'
                                                ? 'destructive'
                                                : 'outline'
                                        }
                                        className="w-fit capitalize"
                                    >
                                        {r.level}
                                    </Badge>
                                    <span className="text-xs text-muted-foreground">
                                        {r.category}
                                    </span>
                                    <span>
                                        {r.title}{' '}
                                        <Link
                                            className="underline"
                                            href={r.link}
                                        >
                                            {r.street ?? 'Open'}
                                        </Link>
                                    </span>
                                    <span className="text-xs">{r.owner}</span>
                                    <span className="text-xs text-muted-foreground">
                                        {r.due ?? ''}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}
