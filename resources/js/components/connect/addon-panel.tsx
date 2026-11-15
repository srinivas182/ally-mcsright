import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';

export type AppPrice = {
    free: boolean;
    cents: number | null;
    included: boolean;
    active: boolean;
    payer: 'brokerage' | 'you' | null;
    period_end: string | null;
    cancel_at: string | null;
    can_buy: boolean;
    pay_with: 'credits' | 'card';
    card: { brand: string; last4: string } | null;
    error: string | null;
};

const usd = (cents: number) =>
    (cents / 100).toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
    });
const day = (iso: string) =>
    new Date(iso.replace(' ', 'T')).toLocaleDateString('en-US', {
        month: 'long',
        day: 'numeric',
    });

/**
 * A paid app's monthly add-on (specs/connect/14): add it before connecting, see when it renews, or cancel at the
 * end of the paid month. Charged in RightAlly credits.
 */
export function AddonPanel({
    name,
    slug,
    price,
}: {
    name: string;
    slug: string;
    price: AppPrice;
}) {
    const [payWith, setPayWith] = useState<'credits' | 'card'>(price.pay_with);
    if (price.free || price.cents === null || price.payer === null) {
        return null;
    }
    if (price.included) {
        return (
            <section className="rounded-lg border p-4">
                {name} is included in your RightAlly package.
            </section>
        );
    }

    return (
        <section className="grid gap-3 rounded-lg border p-4">
            <h2 className="font-medium">
                {name} add-on: {usd(price.cents)} a month
            </h2>
            {price.active ? (
                <p>
                    Added
                    {price.payer === 'brokerage'
                        ? ' for your brokerage'
                        : ''}.{' '}
                    {price.cancel_at
                        ? `It stops on ${day(price.cancel_at)}, then the connection pauses.`
                        : price.period_end
                          ? `Renews on ${day(price.period_end)} from your ${price.pay_with === 'card' && price.card ? `card ending ${price.card.last4}` : 'RightAlly credits'}.`
                          : ''}
                </p>
            ) : (
                <p>
                    Add it to connect {name}. It's charged monthly from{' '}
                    {price.payer === 'brokerage' ? "your brokerage's" : 'your'}{' '}
                    RightAlly credits, and you can cancel any time.
                </p>
            )}
            {price.error && !price.active && (
                <p className="text-destructive">{price.error}</p>
            )}
            {price.can_buy && (
                <fieldset className="grid gap-2">
                    <legend className="mb-1">Pay with</legend>
                    <label className="flex items-center gap-3">
                        <input
                            type="radio"
                            className="size-5"
                            checked={payWith === 'credits'}
                            onChange={() => {
                                setPayWith('credits');
                                if (price.active) {
                                    router.post(
                                        `/apps/${slug}/addon/pay-with`,
                                        { pay_with: 'credits' },
                                        { preserveScroll: true },
                                    );
                                }
                            }}
                        />
                        RightAlly credits
                    </label>
                    <label className="flex items-center gap-3">
                        <input
                            type="radio"
                            className="size-5"
                            disabled={price.card === null}
                            checked={payWith === 'card'}
                            onChange={() => {
                                setPayWith('card');
                                if (price.active) {
                                    router.post(
                                        `/apps/${slug}/addon/pay-with`,
                                        { pay_with: 'card' },
                                        { preserveScroll: true },
                                    );
                                }
                            }}
                        />
                        {price.card
                            ? `My card (${price.card.brand} ending ${price.card.last4})`
                            : 'My card: save one under Billing → RightAlly credits first'}
                    </label>
                    {price.active && (
                        <span className="text-sm text-muted-foreground">
                            A change applies from your next month.
                        </span>
                    )}
                </fieldset>
            )}
            {price.can_buy ? (
                <div className="flex flex-wrap gap-2">
                    {(!price.active || price.cancel_at) && (
                        <Button
                            onClick={() =>
                                router.post(
                                    `/apps/${slug}/addon`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {price.active
                                ? 'Keep it'
                                : `Add for ${usd(price.cents)} a month`}
                        </Button>
                    )}
                    {price.active && !price.cancel_at && (
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.delete(`/apps/${slug}/addon`, {
                                    preserveScroll: true,
                                })
                            }
                        >
                            Cancel at the end of the month
                        </Button>
                    )}
                </div>
            ) : (
                !price.active && (
                    <p className="text-muted-foreground">
                        Your brokerage's owner can add it.
                    </p>
                )
            )}
        </section>
    );
}
