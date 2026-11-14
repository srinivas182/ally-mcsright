import { Head, Link } from '@inertiajs/react';

import { Button } from '@/components/ui/button';

type Receipt = {
    ulid: string;
    pack: string;
    credits: number;
    price_cents: number;
    tax_cents: number;
    total_cents: number;
    status: string;
    paid_at: string;
    expires_at: string;
    for: string;
    buyer: string;
    buyer_email: string;
    brokerage: string;
    payment_ref: string;
};

const usd = (cents: number) =>
    new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(cents / 100);

/** A printable receipt for a credit pack. */
export default function CreditReceipt({ receipt }: { receipt: Receipt }) {
    const date = (v: string) =>
        new Date(v).toLocaleDateString('en-US', { dateStyle: 'long' });
    return (
        <>
            <Head title={`Receipt ${receipt.ulid}`} />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4 md:p-6 print:p-0">
                <div className="flex justify-between gap-2 print:hidden">
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/settings/credits">Back to credits</Link>
                    </Button>
                    <Button size="sm" onClick={() => window.print()}>
                        Print or save as PDF
                    </Button>
                </div>
                <article className="grid gap-4 rounded-lg border p-6 text-sm print:border-0">
                    <header className="flex flex-wrap justify-between gap-2">
                        <div>
                            <h1 className="text-xl font-semibold">Receipt</h1>
                            <p className="text-muted-foreground">
                                RightAlly · rightally.io
                            </p>
                        </div>
                        <div className="text-right text-muted-foreground">
                            <p>No. {receipt.ulid}</p>
                            <p>Paid {date(receipt.paid_at)}</p>
                            {receipt.status === 'refunded' && (
                                <p className="font-medium text-destructive">
                                    Refunded
                                </p>
                            )}
                        </div>
                    </header>
                    <section>
                        <p className="font-medium">{receipt.brokerage}</p>
                        <p className="text-muted-foreground">
                            Bought by {receipt.buyer} ({receipt.buyer_email})
                        </p>
                    </section>
                    <table className="w-full">
                        <tbody>
                            <tr className="border-t">
                                <td className="py-2">
                                    {receipt.pack}:{' '}
                                    {receipt.credits.toLocaleString()} RightAlly
                                    credits ({receipt.for.toLowerCase()})
                                </td>
                                <td className="py-2 text-right">
                                    {usd(receipt.price_cents)}
                                </td>
                            </tr>
                            {receipt.tax_cents > 0 && (
                                <tr>
                                    <td className="py-1 text-muted-foreground">
                                        Sales tax
                                    </td>
                                    <td className="py-1 text-right">
                                        {usd(receipt.tax_cents)}
                                    </td>
                                </tr>
                            )}
                            <tr className="border-t font-semibold">
                                <td className="py-2">Total paid</td>
                                <td className="py-2 text-right">
                                    {usd(receipt.total_cents)}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p className="text-muted-foreground">
                        Credits are valid until {date(receipt.expires_at)}.
                        Payment reference: {receipt.payment_ref || '—'}.
                    </p>
                </article>
            </div>
        </>
    );
}

CreditReceipt.layout = {
    breadcrumbs: [
        { title: 'RightAlly credits', href: '/settings/credits' },
        { title: 'Receipt', href: '#' },
    ],
};
