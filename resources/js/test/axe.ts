import axe from 'axe-core';
import { expect } from 'vite-plus/test';

/**
 * Fails the test when axe finds accessibility violations. Colour contrast is excluded here
 * because jsdom does not compute layout or colours; contrast is guaranteed by the theme
 * builder's AA checks (ThemeBuilderTest) instead.
 */
export async function expectNoA11yViolations(
    container: Element,
): Promise<void> {
    const results = await axe.run(container, {
        rules: {
            'color-contrast': { enabled: false },
            region: { enabled: false },
        },
    });
    const summary = results.violations.map(
        (v) => `${v.id}: ${v.help} (${v.nodes.length})`,
    );
    expect(summary).toEqual([]);
}
