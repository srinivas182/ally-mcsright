import * as React from 'react';

import { cn } from '@/lib/utils';

type PageHeaderProps = {
    title: string;
    description?: React.ReactNode;
    actions?: React.ReactNode;
    className?: string;
};

/** Title, short description and primary actions; actions wrap under the title on phones. */
function PageHeader({ title, description, actions, className }: PageHeaderProps) {
    return (
        <header
            data-slot="page-header"
            className={cn('flex flex-wrap items-start justify-between gap-4', className)}
        >
            <div className="grid min-w-0 gap-1">
                <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
                {description && (
                    <p className="text-sm text-muted-foreground">{description}</p>
                )}
            </div>
            {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
        </header>
    );
}

export { PageHeader };
