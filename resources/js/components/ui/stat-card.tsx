import { TrendingDownIcon, TrendingUpIcon } from 'lucide-react';
import * as React from 'react';

import { cn } from '@/lib/utils';

type StatCardProps = {
    label: string;
    value: React.ReactNode;
    /** Change versus the comparison period, as a percentage (e.g. 12.5 or -3). */
    change?: number;
    /** e.g. "vs last month" */
    comparison?: string;
    /** Whether a rise is good (revenue) or bad (costs). */
    higherIsBetter?: boolean;
    className?: string;
};

/**
 * A KPI tile. Trend direction is conveyed in text as well as colour and icon.
 */
function StatCard({
    label,
    value,
    change,
    comparison = 'vs previous period',
    higherIsBetter = true,
    className,
}: StatCardProps) {
    const hasChange = change !== undefined && Number.isFinite(change);
    const up = hasChange && change > 0;
    const good = hasChange && change !== 0 && up === higherIsBetter;
    const Icon = up ? TrendingUpIcon : TrendingDownIcon;

    return (
        <div
            data-slot="stat-card"
            className={cn(
                'grid gap-1 rounded-xl border bg-card p-4 text-card-foreground shadow-xs',
                className,
            )}
        >
            <p className="text-sm text-muted-foreground">{label}</p>
            <p className="text-2xl font-semibold tabular-nums">{value}</p>
            {hasChange && (
                <p
                    className={cn(
                        'flex items-center gap-1 text-xs',
                        change === 0
                            ? 'text-muted-foreground'
                            : good
                              ? 'text-emerald-700 dark:text-emerald-400'
                              : 'text-destructive',
                    )}
                >
                    {change !== 0 && (
                        <Icon aria-hidden="true" className="size-3.5" />
                    )}
                    <span>
                        {change === 0
                            ? 'No change'
                            : `${up ? 'Up' : 'Down'} ${Math.abs(change).toLocaleString(undefined, { maximumFractionDigits: 1 })}%`}{' '}
                        <span className="text-muted-foreground">
                            {comparison}
                        </span>
                    </span>
                </p>
            )}
        </div>
    );
}

export { StatCard };
