import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vite-plus/test';

import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';

describe('FormField', () => {
    it('links the label to the control', () => {
        render(
            <FormField label="Email">
                {(control) => <Input type="email" {...control} />}
            </FormField>,
        );

        const input = screen.getByLabelText('Email');
        expect(input.tagName).toBe('INPUT');
    });

    it('describes the control with help and error and marks it invalid', () => {
        render(
            <FormField
                label="Licence number"
                help="As shown on your state licence"
                error="Licence number is required"
                required
            >
                {(control) => <Input {...control} />}
            </FormField>,
        );

        const input = screen.getByRole('textbox');
        const describedBy = input.getAttribute('aria-describedby') ?? '';
        const ids = describedBy.split(' ');

        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect(input.getAttribute('aria-required')).toBe('true');
        expect(ids).toHaveLength(2);
        expect(document.getElementById(ids[0])?.textContent).toBe(
            'As shown on your state licence',
        );
        expect(screen.getByRole('alert').textContent).toBe(
            'Licence number is required',
        );
    });

    it('is not marked invalid without an error', () => {
        render(
            <FormField label="Name">
                {(control) => <Input {...control} />}
            </FormField>,
        );

        expect(screen.getByRole('textbox').hasAttribute('aria-invalid')).toBe(
            false,
        );
    });
});
