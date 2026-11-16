import { Link } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { usd } from '@/lib/cents';

export type DrillDeal = {
    ulid: string;
    street: string;
    city: string | null;
    agent: string;
    status: string;
    date: string | null;
    price_cents: number;
    gci_cents: number | null;
};

/** The deals behind a number (specs/insights/01, K1). */
export function DealDrill({
    title,
    deals,
    onClose,
}: {
    title: string;
    deals: DrillDeal[] | null;
    onClose: () => void;
}) {
    return (
        <section
            className="grid gap-2 rounded-lg border p-4 text-sm"
            aria-live="polite"
        >
            <div className="flex items-center justify-between">
                <p className="font-medium">{title}</p>
                <Button size="sm" variant="ghost" onClick={onClose}>
                    Close
                </Button>
            </div>
            {deals === null ? (
                <p className="text-muted-foreground">Loading…</p>
            ) : deals.length === 0 ? (
                <p className="text-muted-foreground">No deals.</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-left">
                        <thead className="text-muted-foreground">
                            <tr>
                                <th className="py-1 font-normal">Deal</th>
                                <th className="py-1 font-normal">Agent</th>
                                <th className="py-1 font-normal">Date</th>
                                <th className="py-1 text-right font-normal">
                                    Price
                                </th>
                                <th className="py-1 text-right font-normal">
                                    GCI
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {deals.map((d) => (
                                <tr key={d.ulid} className="border-t">
                                    <td className="py-1">
                                        <Link
                                            href={`/deals/${d.ulid}`}
                                            className="underline-offset-2 hover:underline"
                                        >
                                            {d.street}
                                            {d.city ? `, ${d.city}` : ''}
                                        </Link>
                                    </td>
                                    <td>{d.agent}</td>
                                    <td>{d.date ?? '—'}</td>
                                    <td className="text-right">
                                        {usd(d.price_cents)}
                                    </td>
                                    <td className="text-right">
                                        {d.gci_cents === null
                                            ? 'Hidden'
                                            : usd(d.gci_cents)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
