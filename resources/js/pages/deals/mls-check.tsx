import { Head, Link } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { usd } from '@/lib/cents';

type Sale = {
    street: string;
    city: string | null;
    mls_number: string;
    close_price_cents: number;
    close_date: string | null;
    agent: string;
    side: string;
    mls: string;
};

/**
 * ML2: homes the brokerage's agents sold in the MLS (listing or buyer side) in the last year that have no deal in
 * RightAlly: deals that may not have been reported to the brokerage.
 */
export default function MlsCheck({
    sales,
    days,
}: {
    sales: Sale[];
    days: number;
}) {
    return (
        <>
            <Head title="Sales not in RightAlly" />
            <div className="mx-auto grid w-full max-w-6xl gap-5 p-4 md:p-6">
                <PageHeader
                    title="Sales in the MLS that aren't in RightAlly"
                    description={`Homes your agents sold in the last ${Math.round(days / 30)} months, as the listing agent or the buyer's agent, with no deal here. Each may be a deal that was not handed in to the brokerage.`}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/deals/listings">
                                Back to My listings
                            </Link>
                        </Button>
                    }
                />
                {sales.length === 0 ? (
                    <div className="flex items-center gap-3 rounded-xl border bg-card p-5 text-base">
                        <CheckCircle2
                            aria-hidden="true"
                            className="size-6 text-emerald-600"
                        />
                        Nothing missing. Every sale we can see in the MLS has a
                        deal in RightAlly. (Only agents with a saved MLS ID are
                        checked.)
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-xl border bg-card">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-muted/50 text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-3 font-medium">
                                        Home
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Agent
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Side
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        Sold on
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Sold for
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {sales.map((s) => (
                                    <tr
                                        key={`${s.mls_number}-${s.side}-${s.agent}`}
                                        className="border-t"
                                    >
                                        <td className="px-4 py-3">
                                            <span className="block font-medium">
                                                {s.street}
                                                {s.city ? `, ${s.city}` : ''}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {s.mls} · MLS {s.mls_number}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">{s.agent}</td>
                                        <td className="px-4 py-3">
                                            <Badge variant="outline">
                                                {s.side}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3">
                                            {s.close_date ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            {usd(s.close_price_cents)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

MlsCheck.layout = {
    breadcrumbs: [
        { title: 'Sales not in RightAlly', href: '/deals/mls-check' },
    ],
};
