import { Head, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usd } from '@/lib/cents';

type Props = {
    token: string;
    cda: {
        version: number;
        status: string;
        acknowledged_at: string | null;
        property: string;
        mls: string | null;
        buyers: string[];
        sellers: string[];
        price_cents: number;
        closing_date: string | null;
        gci_cents: number;
        brokerage: string;
        wire_warning: string;
        payees: {
            name: string;
            role: string;
            amount_cents: number;
            account?: string | null;
        }[];
    };
};

/** What the title officer sees: the CDA, and an acknowledgement. */
export default function PublicCda({ token, cda }: Props) {
    const form = useForm({ name: '' });

    return (
        <>
            <Head title="Commission disbursement authorisation" />
            <main className="mx-auto grid max-w-2xl gap-4 p-6 text-sm">
                <h1 className="text-xl font-semibold">
                    Commission disbursement authorisation
                </h1>
                <p>
                    From <strong>{cda.brokerage}</strong> · version{' '}
                    {cda.version}
                </p>
                <section className="grid gap-1 rounded-lg border p-4">
                    <span>
                        <strong>Property:</strong> {cda.property}
                        {cda.mls ? ` (MLS ${cda.mls})` : ''}
                    </span>
                    <span>
                        <strong>Sellers:</strong>{' '}
                        {cda.sellers.join(', ') || '—'} ·{' '}
                        <strong>Buyers:</strong> {cda.buyers.join(', ') || '—'}
                    </span>
                    <span>
                        <strong>Price:</strong> {usd(cda.price_cents)} ·{' '}
                        <strong>Closing:</strong>{' '}
                        {cda.closing_date
                            ? new Date(cda.closing_date).toLocaleDateString()
                            : '—'}
                    </span>
                </section>
                <section className="grid gap-1 rounded-lg border p-4">
                    <span className="font-medium">
                        Commission {usd(cda.gci_cents)}, paid as follows:
                    </span>
                    <ul className="list-disc pl-5">
                        {cda.payees.map((p, i) => (
                            <li key={i}>
                                {usd(p.amount_cents)} to {p.name} — {p.role}
                                {p.account ? ` (${p.account})` : ''}
                            </li>
                        ))}
                    </ul>
                </section>
                <p
                    role="note"
                    className="rounded-md border border-amber-300 bg-amber-50 p-3 text-amber-900"
                >
                    {cda.wire_warning}
                </p>
                {cda.status === 'acknowledged' ? (
                    <p className="font-medium">
                        Receipt acknowledged{' '}
                        {cda.acknowledged_at
                            ? new Date(cda.acknowledged_at).toLocaleString()
                            : ''}
                        .
                    </p>
                ) : (
                    <form
                        className="flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`/cda/${token}/acknowledge`);
                        }}
                    >
                        <Input
                            aria-label="Your full name"
                            placeholder="Your full name"
                            className="max-w-xs"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                        <Button
                            type="submit"
                            disabled={
                                form.processing || form.data.name.length < 3
                            }
                        >
                            Acknowledge receipt
                        </Button>
                    </form>
                )}
            </main>
        </>
    );
}
