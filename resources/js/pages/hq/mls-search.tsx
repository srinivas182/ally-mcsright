import { Head, Link, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Sample = {
    at: string;
    overlap: number | null;
    database_total: number;
    server_total: number | null;
    database_ms: number;
    server_ms: number | null;
    error: string | null;
};

type Report = {
    provider: string | null;
    mode: string;
    indexed: number | null;
    should_be: number;
    rebuilt_at: string | null;
    compared: number;
    errors: number;
    overlap: number | null;
    database_ms: number | null;
    server_ms: number | null;
    samples: Sample[];
};

const MODE: Record<string, string> = {
    off: 'Off: the database answers',
    test: 'Test: the database answers; the server is compared',
    live: 'Live: the server answers (the database steps in if it fails)',
};

/** SR1: HQ → MLS → Fast search. */
export default function MlsSearch({ report }: { report: Report }) {
    return (
        <>
            <Head title="Fast listing search" />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 text-base md:p-6">
                <PageHeader
                    title="Fast listing search"
                    description="A search server for typo-tolerant, map and distance search. Connect it in Outside services; try it in Test mode first."
                />
                <section className="grid gap-2 rounded-lg border p-4">
                    <p className="flex flex-wrap items-center gap-2">
                        <strong>
                            {report.provider ?? 'No server connected'}
                        </strong>
                        <Badge variant="outline">
                            {MODE[report.mode] ?? report.mode}
                        </Badge>
                    </p>
                    <p>
                        Listings in the server:{' '}
                        <strong>
                            {report.indexed === null
                                ? 'unknown'
                                : report.indexed.toLocaleString()}
                        </strong>{' '}
                        of {report.should_be.toLocaleString()} that should be
                        there.
                        {report.rebuilt_at &&
                            ` Last rebuilt ${new Date(report.rebuilt_at).toLocaleString()}.`}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            disabled={report.provider === null}
                            onClick={() =>
                                router.post(
                                    '/hq/mls/search/rebuild',
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Rebuild the index
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href="/hq/services">Outside services</Link>
                        </Button>
                    </div>
                </section>
                <section className="grid gap-2 rounded-lg border p-4">
                    <h2 className="text-lg font-semibold">
                        Test mode comparison
                    </h2>
                    {report.compared === 0 ? (
                        <p className="text-muted-foreground">
                            No comparisons yet. In Test mode, each first page of
                            results is compared in the background.
                        </p>
                    ) : (
                        <>
                            <p>
                                {report.compared} searches compared.{' '}
                                {report.overlap !== null &&
                                    `On average ${report.overlap}% of the first results matched. `}
                                {report.server_ms !== null &&
                                    `Server ${report.server_ms} ms vs database ${report.database_ms} ms. `}
                                {report.errors > 0 &&
                                    `${report.errors} server errors.`}
                            </p>
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left">
                                        <th className="p-1">When</th>
                                        <th className="p-1">Match</th>
                                        <th className="p-1">
                                            Found (db / server)
                                        </th>
                                        <th className="p-1">
                                            Time (db / server)
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {report.samples.map((s) => (
                                        <tr key={s.at} className="border-t">
                                            <td className="p-1">
                                                {new Date(
                                                    s.at,
                                                ).toLocaleString()}
                                            </td>
                                            <td className="p-1">
                                                {s.error ??
                                                    (s.overlap !== null
                                                        ? `${s.overlap}%`
                                                        : '—')}
                                            </td>
                                            <td className="p-1">
                                                {s.database_total} /{' '}
                                                {s.server_total ?? '—'}
                                            </td>
                                            <td className="p-1">
                                                {s.database_ms} ms /{' '}
                                                {s.server_ms ?? '—'} ms
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </>
                    )}
                </section>
            </div>
        </>
    );
}
