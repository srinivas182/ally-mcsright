import * as React from 'react';

import { cn } from '@/lib/utils';

type FormSectionProps = {
    title: string;
    description?: React.ReactNode;
    children: React.ReactNode;
    className?: string;
};

/** A titled group of fields: explanation on the left, fields on the right (stacked on phones). */
function FormSection({ title, description, children, className }: FormSectionProps) {
    const id = React.useId();

    return (
        <section
            aria-labelledby={id}
            data-slot="form-section"
            className={cn('grid gap-6 border-b pb-8 last:border-b-0 md:grid-cols-3', className)}
        >
            <div className="grid content-start gap-1">
                <h2 id={id} className="text-base font-semibold">
                    {title}
                </h2>
                {description && (
                    <p className="text-sm text-muted-foreground">{description}</p>
                )}
            </div>
            <div className="grid gap-4 md:col-span-2">{children}</div>
        </section>
    );
}

export { FormSection };
