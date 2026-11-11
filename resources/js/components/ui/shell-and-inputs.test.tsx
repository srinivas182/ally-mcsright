import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it } from 'vite-plus/test';

import { DateRangeInput } from '@/components/ui/date-range-input';
import { FormSection } from '@/components/ui/form-section';
import {
    formatUsPhone,
    MoneyInput,
    parseCents,
    PercentInput,
    toE164,
} from '@/components/ui/number-inputs';
import { PageHeader } from '@/components/ui/page-header';
import { Stepper } from '@/components/ui/stepper';
import { expectNoA11yViolations } from '@/test/axe';

describe('money and phone helpers', () => {
    it('parses amounts to integer cents without floating point', () => {
        expect(parseCents('1,250.5')).toBe(125050);
        expect(parseCents('$0.07')).toBe(7);
        expect(parseCents('19.999')).toBeNull();
        expect(parseCents('abc')).toBeNull();
    });

    it('formats US phones and converts to E.164', () => {
        expect(formatUsPhone('6015550100')).toBe('(601) 555-0100');
        expect(formatUsPhone('1 601 555 0100')).toBe('(601) 555-0100');
        expect(toE164('(601) 555-0100')).toBe('+16015550100');
        expect(toE164('601555')).toBeNull();
    });
});

describe('MoneyInput', () => {
    it('reports cents and formats on blur', () => {
        let cents: number | null = null;
        function Harness() {
            const [value, setValue] = useState<number | null>(null);
            return (
                <MoneyInput
                    aria-label="Amount"
                    value={value}
                    onChange={(next) => {
                        cents = next;
                        setValue(next);
                    }}
                />
            );
        }
        render(<Harness />);
        const input = screen.getByLabelText('Amount') as HTMLInputElement;

        fireEvent.change(input, { target: { value: '1250.5' } });
        fireEvent.blur(input);

        expect(cents).toBe(125050);
        expect(input.value).toBe('1,250.50');
    });
});

describe('PercentInput', () => {
    it('keeps exact percent strings and refuses more than 4 decimals', () => {
        function Harness() {
            const [value, setValue] = useState('');
            return <PercentInput aria-label="Agent split" value={value} onChange={setValue} />;
        }
        render(<Harness />);
        const input = screen.getByLabelText('Agent split') as HTMLInputElement;

        fireEvent.change(input, { target: { value: '80.5' } });
        expect(input.value).toBe('80.5');
        fireEvent.change(input, { target: { value: '80.12345' } });
        expect(input.value).toBe('80.5');
    });
});

describe('DateRangeInput', () => {
    it('warns when the end is before the start', () => {
        render(
            <DateRangeInput
                value={{ from: '2026-11-20', to: '2026-11-01' }}
                onChange={() => {}}
            />,
        );
        expect(screen.getByRole('alert').textContent).toContain('on or after');
    });
});

describe('layout building blocks', () => {
    it('page header, form section and stepper are accessible', async () => {
        const { container } = render(
            <div>
                <PageHeader title="Commission plans" description="How deals are split" />
                <FormSection title="Split" description="The agent's share">
                    <label htmlFor="x">Agent percent</label>
                    <input id="x" />
                </FormSection>
                <Stepper steps={['Basics', 'Split', 'Review']} current={1} />
            </div>,
        );

        await expectNoA11yViolations(container);
        expect(screen.getByText('Split', { selector: 'span' }).closest('li')?.getAttribute('aria-current')).toBe('step');
        expect(screen.getByText('(completed)')).toBeTruthy();
    });
});
