import { Head, Link, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/money';

type JournalRow = {
    ulid: string;
    type: string;
    date: string;
    description: string | null;
    total_cents: number;
    is_reversed: boolean;
    is_reversal: boolean;
    is_manual: boolean;
};

type Props = {
    journals: {
        data: JournalRow[];
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
};

export default function JournalsIndex({ journals }: Props) {
    return (
        <>
            <Head title="Journals" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold">Journals</h1>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href="/finance/periods">Periods</Link>
                        </Button>
                        <Button asChild>
                            <Link href="/finance/adjustments/create">
                                Manual adjustment
                            </Link>
                        </Button>
                    </div>
                </header>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3 font-medium">Date</th>
                                <th className="p-3 font-medium">Journal</th>
                                <th className="p-3 font-medium">Type</th>
                                <th className="p-3 text-right font-medium">
                                    Total
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {journals.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        No journals yet.
                                    </td>
                                </tr>
                            )}
                            {journals.data.map((journal) => (
                                <tr
                                    key={journal.ulid}
                                    className="border-t hover:bg-muted/30"
                                >
                                    <td className="p-3">{journal.date}</td>
                                    <td className="p-3">
                                        <Link
                                            href={`/finance/journals/${journal.ulid}`}
                                            className="text-primary hover:underline"
                                        >
                                            {journal.description ??
                                                journal.ulid}
                                        </Link>{' '}
                                        {journal.is_manual && (
                                            <Badge variant="outline">
                                                Manual
                                            </Badge>
                                        )}{' '}
                                        {journal.is_reversed && (
                                            <Badge variant="secondary">
                                                Reversed
                                            </Badge>
                                        )}
                                        {journal.is_reversal && (
                                            <Badge variant="secondary">
                                                Reversal
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="p-3 font-mono text-xs">
                                        {journal.type}
                                    </td>
                                    <td className="p-3 text-right tabular-nums">
                                        {formatMoney({
                                            cents: journal.total_cents,
                                            currency: 'USD',
                                        })}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {journals.last_page > 1 && (
                    <nav
                        aria-label="Pagination"
                        className="flex items-center justify-between"
                    >
                        <span className="text-sm text-muted-foreground">
                            Page {journals.current_page} of {journals.last_page}
                        </span>
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                disabled={!journals.prev_page_url}
                                onClick={() =>
                                    journals.prev_page_url &&
                                    router.get(journals.prev_page_url)
                                }
                            >
                                Previous
                            </Button>
                            <Button
                                variant="outline"
                                disabled={!journals.next_page_url}
                                onClick={() =>
                                    journals.next_page_url &&
                                    router.get(journals.next_page_url)
                                }
                            >
                                Next
                            </Button>
                        </div>
                    </nav>
                )}
            </div>
        </>
    );
}

JournalsIndex.layout = {
    breadcrumbs: [{ title: 'Journals', href: '/finance/journals' }],
};
