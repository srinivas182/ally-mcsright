import * as React from 'react';

import { cn } from '@/lib/utils';

type EmptyStateProps = {
    title: string;
    description?: React.ReactNode;
    icon?: React.ReactNode;
    action?: React.ReactNode;
    className?: string;
};

/**
 * Friendly placeholder for empty lists: what is missing and the next step.
 */
function EmptyState({
    title,
    description,
    icon,
    action,
    className,
}: EmptyStateProps) {
    return (
        <div
            data-slot="empty-state"
            className={cn(
                'flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-8 text-center',
                className,
            )}
        >
            {icon && (
                <div
                    aria-hidden="true"
                    className="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground [&_svg]:size-6"
                >
                    {icon}
                </div>
            )}
            <h3 className="text-base font-semibold">{title}</h3>
            {description && (
                <p className="max-w-sm text-sm text-muted-foreground">
                    {description}
                </p>
            )}
            {action && <div className="mt-2">{action}</div>}
        </div>
    );
}

export { EmptyState };
