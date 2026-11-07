import { cleanup } from '@testing-library/react';
import { afterEach } from 'vite-plus/test';

// Unmount rendered components between tests.
afterEach(() => {
    cleanup();
});
