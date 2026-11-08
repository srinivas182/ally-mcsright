export type Money = { cents: number; currency: string };

/** Format integer cents for display, e.g. { cents: 123456 } → "$1,234.56". */
export function formatMoney(money: Money): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: money.currency,
    }).format(money.cents / 100);
}
