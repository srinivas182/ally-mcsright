import { ChevronLeftIcon, ChevronRightIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type PaginationProps = {
    page: number;
    lastPage: number;
    onPageChange: (page: number) => void;
    total?: number;
    className?: string;
};

/**
 * Previous / next pagination with a live "Page x of y" summary for screen readers.
 */
function Pagination({
    page,
    lastPage,
    onPageChange,
    total,
    className,
}: PaginationProps) {
    if (lastPage <= 1) {
        return null;
    }

    return (
        <nav
            aria-label="Pagination"
            data-slot="pagination"
            className={cn('flex items-center justify-between gap-4', className)}
        >
            <p className="text-sm text-muted-foreground" aria-live="polite">
                Page {page} of {lastPage}
                {total !== undefined ? ` · ${total} results` : ''}
            </p>
            <div className="flex gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    disabled={page <= 1}
                    onClick={() => onPageChange(page - 1)}
                >
                    <ChevronLeftIcon aria-hidden="true" /> Previous
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    disabled={page >= lastPage}
                    onClick={() => onPageChange(page + 1)}
                >
                    Next <ChevronRightIcon aria-hidden="true" />
                </Button>
            </div>
        </nav>
    );
}

export { Pagination };
