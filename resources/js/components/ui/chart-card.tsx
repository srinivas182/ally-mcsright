import * as React from 'react';
import { ResponsiveContainer } from 'recharts';

import { cn } from '@/lib/utils';

type ChartCardProps = {
    title: string;
    description?: string;
    /** A Recharts chart (LineChart, BarChart…). */
    children: React.ReactElement;
    /** The same data as a table for screen readers and printing. */
    table: { headers: string[]; rows: (string | number)[][] };
    height?: number;
    className?: string;
};

/** Charts always come with an accessible data table. */
function ChartCard({ title, description, children, table, height = 260, className }: ChartCardProps) {
    return (
        <figure data-slot="chart-card" className={cn('grid gap-3 rounded-xl border bg-card p-4', className)}>
            <figcaption className="grid gap-1">
                <span className="font-semibold">{title}</span>
                {description && <span className="text-sm text-muted-foreground">{description}</span>}
            </figcaption>
            <div aria-hidden="true" style={{ height }}>
                <ResponsiveContainer width="100%" height="100%">
                    {children}
                </ResponsiveContainer>
            </div>
            <table className="sr-only">
                <caption>{title}</caption>
                <thead>
                    <tr>
                        {table.headers.map((h) => (
                            <th key={h} scope="col">
                                {h}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {table.rows.map((row, i) => (
                        <tr key={i}>
                            {row.map((cell, j) => (
                                <td key={j}>{cell}</td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </figure>
    );
}

export { ChartCard };
