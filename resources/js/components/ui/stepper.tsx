import { CheckIcon } from 'lucide-react';

import { cn } from '@/lib/utils';

type StepperProps = {
    steps: string[];
    /** Zero-based index of the current step. */
    current: number;
    className?: string;
};

/** Wizard progress: done, current and upcoming steps, announced to screen readers. */
function Stepper({ steps, current, className }: StepperProps) {
    return (
        <ol data-slot="stepper" className={cn('flex flex-wrap gap-2 text-sm', className)}>
            {steps.map((step, index) => {
                const state = index < current ? 'done' : index === current ? 'current' : 'upcoming';

                return (
                    <li
                        key={step}
                        aria-current={state === 'current' ? 'step' : undefined}
                        className="flex items-center gap-2"
                    >
                        <span
                            className={cn(
                                'flex size-6 items-center justify-center rounded-full border text-xs',
                                state === 'done' && 'border-primary bg-primary text-primary-foreground',
                                state === 'current' && 'border-primary text-primary',
                                state === 'upcoming' && 'text-muted-foreground',
                            )}
                        >
                            {state === 'done' ? <CheckIcon aria-hidden="true" className="size-3.5" /> : index + 1}
                        </span>
                        <span className={cn(state === 'upcoming' && 'text-muted-foreground')}>
                            {step}
                            {state === 'done' && <span className="sr-only"> (completed)</span>}
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}

export { Stepper };
