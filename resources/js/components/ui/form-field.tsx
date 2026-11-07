import * as React from 'react';

import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

type ControlProps = {
    id: string;
    'aria-invalid'?: boolean;
    'aria-describedby'?: string;
    'aria-required'?: boolean;
};

type FormFieldProps = {
    /** Visible label text. */
    label: React.ReactNode;
    /** Help text shown under the control. */
    help?: React.ReactNode;
    /** Validation message; marks the control invalid and announces the error. */
    error?: string;
    required?: boolean;
    className?: string;
    /** Render prop receiving the accessibility props to spread on the control. */
    children: (control: ControlProps) => React.ReactNode;
};

/**
 * Label, control, help and error wired together accessibly: the label targets the control,
 * help and error are linked through aria-describedby, and errors set aria-invalid and are
 * announced to screen readers.
 */
function FormField({
    label,
    help,
    error,
    required = false,
    className,
    children,
}: FormFieldProps) {
    const id = React.useId();
    const helpId = help ? `${id}-help` : undefined;
    const errorId = error ? `${id}-error` : undefined;
    const describedBy =
        [helpId, errorId].filter(Boolean).join(' ') || undefined;

    return (
        <div data-slot="form-field" className={cn('grid gap-2', className)}>
            <Label htmlFor={id}>
                {label}
                {required && (
                    <span aria-hidden="true" className="text-destructive">
                        *
                    </span>
                )}
            </Label>
            {children({
                id,
                'aria-invalid': error ? true : undefined,
                'aria-describedby': describedBy,
                'aria-required': required || undefined,
            })}
            {help && (
                <p id={helpId} className="text-sm text-muted-foreground">
                    {help}
                </p>
            )}
            {error && (
                <p
                    id={errorId}
                    role="alert"
                    className="text-sm font-medium text-destructive"
                >
                    {error}
                </p>
            )}
        </div>
    );
}

export { FormField };
export type { FormFieldProps };
