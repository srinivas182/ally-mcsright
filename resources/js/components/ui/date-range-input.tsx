import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type DateRange = { from: string; to: string };

type DateRangeInputProps = {
    value: DateRange;
    onChange: (value: DateRange) => void;
    idPrefix?: string;
    error?: string;
};

/** From/to dates (native pickers on every device); "to" can never be before "from". */
function DateRangeInput({ value, onChange, idPrefix = 'range', error }: DateRangeInputProps) {
    const invalid = value.from !== '' && value.to !== '' && value.to < value.from;

    return (
        <fieldset className="grid gap-2">
            <div className="flex flex-wrap items-end gap-3">
                <div className="grid gap-1">
                    <Label htmlFor={`${idPrefix}-from`}>From</Label>
                    <Input
                        id={`${idPrefix}-from`}
                        type="date"
                        value={value.from}
                        max={value.to || undefined}
                        onChange={(e) => onChange({ ...value, from: e.target.value })}
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor={`${idPrefix}-to`}>To</Label>
                    <Input
                        id={`${idPrefix}-to`}
                        type="date"
                        value={value.to}
                        min={value.from || undefined}
                        aria-invalid={invalid || undefined}
                        onChange={(e) => onChange({ ...value, to: e.target.value })}
                    />
                </div>
            </div>
            {(invalid || error) && (
                <p role="alert" className="text-sm text-destructive">
                    {error ?? 'The end date must be on or after the start date.'}
                </p>
            )}
        </fieldset>
    );
}

export { DateRangeInput };
export type { DateRange };
