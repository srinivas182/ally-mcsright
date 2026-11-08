import { Head, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Period = {
    id: number;
    starts_on: string;
    ends_on: string;
    status: 'open' | 'closed';
    closed_at: string | null;
    can_close: boolean;
};

type Props = { periods: Period[]; can: { close: boolean } };

export default function PeriodsIndex({ periods, can }: Props) {
    return (
        <>
            <Head title="Accounting periods" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header>
                    <h1 className="text-2xl font-semibold">
                        Accounting periods
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Closed months accept no new entries. Corrections are
                        posted in the current open month.
                    </p>
                </header>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3 font-medium">Month</th>
                                <th className="p-3 font-medium">Status</th>
                                <th className="p-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {periods.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={3}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        Periods appear when the first entry is
                                        posted.
                                    </td>
                                </tr>
                            )}
                            {periods.map((period) => (
                                <tr key={period.id} className="border-t">
                                    <td className="p-3">
                                        {period.starts_on} to {period.ends_on}
                                    </td>
                                    <td className="p-3">
                                        <Badge
                                            variant={
                                                period.status === 'open'
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                            className="capitalize"
                                        >
                                            {period.status}
                                        </Badge>
                                    </td>
                                    <td className="p-3 text-right">
                                        {can.close && period.can_close && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        `/finance/periods/${period.id}/close`,
                                                    )
                                                }
                                            >
                                                Close month
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}

PeriodsIndex.layout = {
    breadcrumbs: [{ title: 'Accounting periods', href: '/finance/periods' }],
};
