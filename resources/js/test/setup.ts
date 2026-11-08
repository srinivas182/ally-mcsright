import { cleanup } from '@testing-library/react';
import { afterEach } from 'vite-plus/test';

// Unmount rendered components between tests.
afterEach(() => {
    cleanup();
});

// jsdom lacks these browser APIs used by Radix and cmdk.
class ResizeObserverStub {
    observe() {}
    unobserve() {}
    disconnect() {}
}

if (!('ResizeObserver' in globalThis)) {
    Object.defineProperty(globalThis, 'ResizeObserver', {
        value: ResizeObserverStub,
        writable: true,
    });
}

const noop = (): void => {};
const elementStubs: Record<string, unknown> = {
    scrollIntoView: noop,
    hasPointerCapture: () => false,
    releasePointerCapture: noop,
};

for (const [name, value] of Object.entries(elementStubs)) {
    if (!(name in Element.prototype)) {
        Object.defineProperty(Element.prototype, name, {
            value,
            writable: true,
        });
    }
}
