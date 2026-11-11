import * as React from 'react';

import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/**
 * Parse a typed amount ("1,250.5", "$1250") into integer cents without floating point.
 * Returns null when it is not a valid amount.
 */
export function parseCents(text: string): number | null {
    const clean = text.replace(/[$,\s]/g, '');
    const match = clean.match(/^(\d+)(?:\.(\d{0,2}))?$/);
    if (!match) {
        return null;
    }
    return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
}

export function formatCents(cents: number): string {
    const whole = Math.trunc(cents / 100).toLocaleString('en-US');
    const fraction = String(Math.abs(cents % 100)).padStart(2, '0');
    return `${whole}.${fraction}`;
}

type MoneyInputProps = Omit<React.ComponentProps<typeof Input>, 'value' | 'onChange' | 'type'> & {
    /** Integer cents, or null when empty. */
    value: number | null;
    onChange: (cents: number | null) => void;
};

/** Dollar amounts held as integer cents; formats on blur. */
function MoneyInput({ value, onChange, className, ...props }: MoneyInputProps) {
    const [text, setText] = React.useState(value === null ? '' : formatCents(value));

    React.useEffect(() => {
        setText(value === null ? '' : formatCents(value));
    }, [value]);

    return (
        <div className={cn('relative', className)}>
            <span aria-hidden="true" className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground">
                $
            </span>
            <Input
                {...props}
                inputMode="decimal"
                className="pl-7 tabular-nums"
                value={text}
                onChange={(event) => {
                    setText(event.target.value);
                    onChange(event.target.value.trim() === '' ? null : parseCents(event.target.value));
                }}
                onBlur={() => {
                    const cents = parseCents(text);
                    if (cents !== null) {
                        setText(formatCents(cents));
                    }
                }}
            />
        </div>
    );
}

type PercentInputProps = Omit<React.ComponentProps<typeof Input>, 'value' | 'onChange' | 'type'> & {
    /** Percent as an exact decimal string ("80", "12.5"), or "" when empty. */
    value: string;
    onChange: (value: string) => void;
};

/** Accepts 0–100 with up to 4 decimals, kept as a string (never a float). */
function PercentInput({ value, onChange, className, ...props }: PercentInputProps) {
    return (
        <div className={cn('relative', className)}>
            <Input
                {...props}
                inputMode="decimal"
                className="pr-8 tabular-nums"
                value={value}
                onChange={(event) => {
                    const next = event.target.value.replace(/[^\d.]/g, '');
                    if (next === '' || /^\d{0,3}(\.\d{0,4})?$/.test(next)) {
                        onChange(next);
                    }
                }}
            />
            <span aria-hidden="true" className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-muted-foreground">
                %
            </span>
        </div>
    );
}

/** Format US digits as (601) 555-0100 while typing. */
export function formatUsPhone(text: string): string {
    const digits = text.replace(/\D/g, '').replace(/^1(?=\d{10})/, '').slice(0, 10);
    if (digits.length <= 3) {
        return digits;
    }
    if (digits.length <= 6) {
        return `(${digits.slice(0, 3)}) ${digits.slice(3)}`;
    }
    return `(${digits.slice(0, 3)}) ${digits.slice(3, 6)}-${digits.slice(6)}`;
}

/** E.164 for storage: "+16015550100", or null when incomplete. */
export function toE164(text: string): string | null {
    const digits = text.replace(/\D/g, '').replace(/^1(?=\d{10})/, '');
    return digits.length === 10 ? `+1${digits}` : null;
}

type PhoneInputProps = Omit<React.ComponentProps<typeof Input>, 'value' | 'onChange' | 'type'> & {
    value: string;
    onChange: (display: string, e164: string | null) => void;
};

function PhoneInput({ value, onChange, ...props }: PhoneInputProps) {
    return (
        <Input
            {...props}
            type="tel"
            inputMode="tel"
            autoComplete="tel"
            value={value}
            onChange={(event) => {
                const display = formatUsPhone(event.target.value);
                onChange(display, toE164(display));
            }}
        />
    );
}

export { MoneyInput, PercentInput, PhoneInput };
