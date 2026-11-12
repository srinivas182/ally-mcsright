import { formatMoney } from '@/lib/money';

/** US dollars from integer cents, e.g. 1600000 → "$16,000.00". */
export const usd = (cents: number | null | undefined): string =>
    cents === null || cents === undefined
        ? '—'
        : formatMoney({ cents, currency: 'USD' });
