import { Head, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Row = {
    contact: string;
    address: string;
    last_mid: number | null;
    sent: number;
    next_on: string;
    opened: string | null;
    stopped: boolean;
};

/** MK2: homeowners getting the monthly home-value email and dashboard, and past clients who are not yet. */
export default function Homeowners({
    reports,
    waiting,
}: {
    reports: Row[];
    waiting: number;
}) {
    return (
        <>
            <Head title="Homeowners" />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Homeowners"
                    description="Past clients and homeowners get a monthly email with what their home is likely worth and a private dashboard. When they ask for a full analysis, you get a task."
                />
                {waiting > 0 && (
                    <section className="flex flex-wrap items-center gap-3 rounded-lg border p-4">
                        <p className="text-base">
                            {waiting} past{' '}
                            {waiting === 1 ? 'client has' : 'clients have'} an
                            address and email but don&rsquo;t get it yet.
                        </p>
                        <Button
                            onClick={() =>
                                router.post(
                                    '/outreach/homeowners',
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Start it for them
                        </Button>
                    </section>
                )}
                <ul className="grid gap-2">
                    {reports.length === 0 && (
                        <li className="text-muted-foreground">
                            Nobody yet. Past clients appear here once started,
                            and homeowners who ask your website what their home
                            is worth are added automatically.
                        </li>
                    )}
                    {reports.map((r, i) => (
                        <li
                            key={i}
                            className="flex flex-wrap items-center gap-2 rounded-lg border p-3"
                        >
                            <span className="font-medium">{r.contact}</span>
                            <span className="text-muted-foreground">
                                {r.address}
                            </span>
                            {r.last_mid !== null && (
                                <span>
                                    about ${r.last_mid.toLocaleString('en-US')}
                                </span>
                            )}
                            <span className="ml-auto flex flex-wrap gap-2">
                                {r.stopped ? (
                                    <Badge variant="outline">Stopped</Badge>
                                ) : (
                                    <Badge variant="secondary">
                                        Next {r.next_on}
                                    </Badge>
                                )}
                                {r.opened && (
                                    <Badge>Opened dashboard {r.opened}</Badge>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}
