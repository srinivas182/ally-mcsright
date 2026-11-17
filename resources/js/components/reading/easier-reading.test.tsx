import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vite-plus/test';

import {
    EasierReadingPanel,
    SkipLink,
} from '@/components/reading/easier-reading';
import { expectNoA11yViolations } from '@/test/axe';

const post = vi.fn();
vi.mock('@inertiajs/react', () => ({
    router: { post: (...args: unknown[]) => post(...args) },
    usePage: () => ({ props: {} }),
}));

describe('EasierReadingPanel (AX1)', () => {
    const reading = {
        text: 'large' as const,
        contrast: false,
        motion: true,
        links: false,
    };

    it('shows each choice plainly, with the current one pressed', async () => {
        const { container } = render(<EasierReadingPanel reading={reading} />);
        expect(
            screen
                .getByRole('button', { name: 'Large' })
                .getAttribute('aria-pressed'),
        ).toBe('true');
        expect(
            screen.getByRole('button', { name: 'Less movement: on' }),
        ).toBeTruthy();
        await expectNoA11yViolations(container);
    });

    it('saves a change straight away', () => {
        render(<EasierReadingPanel reading={reading} />);
        fireEvent.click(screen.getByRole('button', { name: 'Largest' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Stronger contrast: off' }),
        );
        expect(post).toHaveBeenCalledWith(
            '/settings/reading',
            { text: 'largest' },
            { preserveScroll: true },
        );
        expect(post).toHaveBeenCalledWith(
            '/settings/reading',
            { contrast: true },
            { preserveScroll: true },
        );
    });

    it('offers a skip link to the main content', async () => {
        const { container } = render(<SkipLink />);
        expect(
            screen
                .getByRole('link', { name: 'Skip to main content' })
                .getAttribute('href'),
        ).toBe('#main-content');
        await expectNoA11yViolations(container);
    });
});
