import * as ProgressPrimitive from '@radix-ui/react-progress';
import * as React from 'react';

import { cn } from '@/lib/utils';

function Progress({
    className,
    value = 0,
    ...props
}: React.ComponentProps<typeof ProgressPrimitive.Root>) {
    const clamped = Math.min(100, Math.max(0, value ?? 0));

    return (
        <ProgressPrimitive.Root
            data-slot="progress"
            value={clamped}
            className={cn(
                'relative h-2 w-full overflow-hidden rounded-full bg-primary/20',
                className,
            )}
            {...props}
        >
            <ProgressPrimitive.Indicator
                data-slot="progress-indicator"
                className="h-full w-full flex-1 bg-primary transition-transform"
                style={{ transform: `translateX(-${100 - clamped}%)` }}
            />
        </ProgressPrimitive.Root>
    );
}

export { Progress };
