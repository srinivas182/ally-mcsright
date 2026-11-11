import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type Address = {
    line1: string;
    line2: string;
    city: string;
    state: string;
    zip: string;
};

type AddressFieldsProps = {
    value: Address;
    onChange: (value: Address) => void;
    idPrefix?: string;
    errors?: Partial<Record<keyof Address, string>>;
};

/** US address with browser autofill; address lookup plugs in later (MLS / USPS). */
function AddressFields({ value, onChange, idPrefix = 'address', errors = {} }: AddressFieldsProps) {
    const field = (key: keyof Address, label: string, autoComplete: string, extra: object = {}) => (
        <div className="grid gap-1">
            <Label htmlFor={`${idPrefix}-${key}`}>{label}</Label>
            <Input
                id={`${idPrefix}-${key}`}
                autoComplete={autoComplete}
                value={value[key]}
                aria-invalid={errors[key] ? true : undefined}
                onChange={(e) => onChange({ ...value, [key]: e.target.value })}
                {...extra}
            />
            {errors[key] && <p className="text-sm text-destructive">{errors[key]}</p>}
        </div>
    );

    return (
        <fieldset className="grid gap-3">
            {field('line1', 'Street address', 'address-line1')}
            {field('line2', 'Apartment, suite (optional)', 'address-line2')}
            <div className="grid gap-3 sm:grid-cols-[2fr_1fr_1fr]">
                {field('city', 'City', 'address-level2')}
                {field('state', 'State', 'address-level1', { maxLength: 2, className: 'uppercase' })}
                {field('zip', 'ZIP code', 'postal-code', { inputMode: 'numeric', maxLength: 10 })}
            </div>
        </fieldset>
    );
}

export { AddressFields };
