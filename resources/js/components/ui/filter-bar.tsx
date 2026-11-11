import * as React from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type FilterBarProps = {
    children: React.ReactNode;
    onClear?: () => void;
    active?: boolean;
    className?: string;
};

/** A row of filters that wraps on small screens, with "Clear" when any filter is set. */
function FilterBar({ children, onClear, active = false, className }: FilterBarProps) {
    return (
        <div
            role="search"
            data-slot="filter-bar"
            className={cn('flex flex-wrap items-center gap-3', className)}
        >
            {children}
            {active && onClear && (
                <Button type="button" variant="ghost" size="sm" onClick={onClear}>
                    Clear filters
                </Button>
            )}
        </div>
    );
}

export { FilterBar };
