import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vite-plus/test';

import { FieldCanvas, type Field } from '@/components/forms/field-canvas';

// jsdom has no PointerEvent; browsers do. A mouse event with a pointer id is enough for these tests.
if (!('PointerEvent' in window)) {
    class TestPointerEvent extends MouseEvent {
        pointerId: number;

        constructor(type: string, init: PointerEventInit = {}) {
            super(type, init);
            this.pointerId = init.pointerId ?? 1;
        }
    }
    Object.defineProperty(window, 'PointerEvent', { value: TestPointerEvent });
}

function Harness({
    initial = [],
    onUnavailable = () => {},
}: {
    initial?: Field[];
    onUnavailable?: () => void;
}) {
    const [fields, setFields] = useState<Field[]>(initial);

    return (
        <>
            <FieldCanvas
                ulid="T1"
                pages={2}
                pageSizes={[
                    [612, 792],
                    [612, 792],
                ]}
                fields={fields}
                setFields={setFields}
                bindings={['deal.address']}
                types={[
                    'text',
                    'date',
                    'checkbox',
                    'signature',
                    'initials',
                    'date_signed',
                ]}
                onImageUnavailable={onUnavailable}
            />
            <output data-testid="fields">{JSON.stringify(fields)}</output>
        </>
    );
}

const state = (): Field[] =>
    JSON.parse(screen.getByTestId('fields').textContent ?? '[]');
const canvas = () => screen.getByLabelText('Page 1 of the form');

describe('FieldCanvas', () => {
    it('places a standard field where you click and selects it', () => {
        render(<Harness />);
        fireEvent.pointerDown(canvas(), {
            clientX: 100,
            clientY: 200,
            pointerId: 1,
        });
        fireEvent.pointerUp(canvas(), { pointerId: 1 });

        expect(state()).toEqual([
            expect.objectContaining({
                key: 'field_1',
                type: 'text',
                page: 1,
                x: 100,
                y: 200,
                w: 160,
                h: 14,
            }),
        ]);
        expect(
            (screen.getByLabelText('Field label') as HTMLInputElement).value,
        ).toBe('field 1');
    });

    it('draws a field by dragging', () => {
        render(<Harness />);
        fireEvent.pointerDown(canvas(), {
            clientX: 50,
            clientY: 60,
            pointerId: 1,
        });
        fireEvent.pointerMove(canvas(), {
            clientX: 250,
            clientY: 90,
            pointerId: 1,
        });
        fireEvent.pointerUp(canvas(), { pointerId: 1 });

        expect(state()[0]).toMatchObject({ x: 50, y: 60, w: 200, h: 30 });
    });

    it('nudges with the arrow keys and never leaves the page', () => {
        render(
            <Harness
                initial={[
                    {
                        key: 'a',
                        label: 'A',
                        type: 'text',
                        page: 1,
                        x: 3,
                        y: 100,
                        w: 100,
                        h: 14,
                        role: null,
                        bind: null,
                        required: false,
                    },
                ]}
            />,
        );
        fireEvent.pointerDown(
            screen.getByRole('button', { name: 'A (text)' }),
            { clientX: 10, clientY: 105, pointerId: 1 },
        );
        fireEvent.pointerUp(canvas(), { pointerId: 1 });

        fireEvent.keyDown(canvas(), { key: 'ArrowDown' });
        fireEvent.keyDown(canvas(), { key: 'ArrowLeft', shiftKey: true });

        expect(state()[0]).toMatchObject({ x: 0, y: 101 });
    });

    it('turns a field into a signature for a signer, duplicates it, and deletes it', () => {
        render(
            <Harness
                initial={[
                    {
                        key: 'sig',
                        label: 'Seller signs',
                        type: 'text',
                        page: 1,
                        x: 72,
                        y: 650,
                        w: 200,
                        h: 20,
                        role: null,
                        bind: null,
                        required: false,
                    },
                ]}
            />,
        );
        fireEvent.pointerDown(
            screen.getByRole('button', { name: 'Seller signs (text)' }),
            { clientX: 80, clientY: 655, pointerId: 1 },
        );
        fireEvent.pointerUp(canvas(), { pointerId: 1 });
        fireEvent.change(screen.getByLabelText('Field type'), {
            target: { value: 'signature' },
        });
        fireEvent.change(screen.getByLabelText('Signer role'), {
            target: { value: 'seller1' },
        });

        fireEvent.click(screen.getByRole('button', { name: 'Duplicate' }));
        expect(state()).toHaveLength(2);
        expect(state()[1]).toMatchObject({
            type: 'signature',
            role: 'seller1',
            y: 674,
        });
        expect(new Set(state().map((f) => f.key)).size).toBe(2);

        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
        expect(state()).toHaveLength(1);
    });

    it('falls back to the table when the page image cannot load', () => {
        const onUnavailable = vi.fn();
        const { container } = render(<Harness onUnavailable={onUnavailable} />);
        fireEvent.error(container.querySelector('img') as HTMLImageElement);

        expect(onUnavailable).toHaveBeenCalled();
    });
});
