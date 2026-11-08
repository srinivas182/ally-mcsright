import { describe, expect, it } from 'vite-plus/test';

import { formatMoney } from '@/lib/money';

describe('formatMoney', () => {
    it('formats cents as currency without floating-point drift', () => {
        expect(formatMoney({ cents: 123456, currency: 'USD' })).toBe(
            '$1,234.56',
        );
        expect(formatMoney({ cents: -5, currency: 'USD' })).toBe('-$0.05');
        expect(formatMoney({ cents: 10, currency: 'USD' })).toBe('$0.10');
    });
});
