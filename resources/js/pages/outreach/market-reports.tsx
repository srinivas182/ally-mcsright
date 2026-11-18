import { Head, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Row = {
    place: string;
    name: string;
    people: number;
    stopped: number;
    sent: number;
    next_on: string | null;
};

/** MK3 (D09): monthly market report emails by area, from the agent. */
export default function MarketReports({
    places,
    audiences,
    reports,
}: {
    places: { slug: string; name: string }[];
    audiences: { key: string; name: string }[];
    reports: Row[];
}) {
    const form = useForm({
        place: places[0]?.slug ?? '',
        audience: audiences[0]?.key ?? '',
    });

    return (
        <>
            <Head title="Market reports" />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 text-base md:p-6">
                <PageHeader
                    title="Market reports"
                    description="A monthly email with your area's real MLS numbers and new listings, from you. People can stop it in one click, and anyone who unsubscribed from email never gets it."
                />
                {places.length === 0 ? (
                    <p className="text-muted-foreground">
                        Market reports need MLS listings. Once your brokerage
                        has an MLS connected, areas appear here.
                    </p>
                ) : (
                    <form
                        className="grid gap-3 rounded-lg border p-4 sm:grid-cols-3 sm:items-end"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/outreach/market-reports', {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <label className="grid gap-1">
                            Area
                            <select
                                className="h-11 rounded-md border bg-background px-3"
                                value={form.data.place}
                                onChange={(e) =>
                                    form.setData('place', e.target.value)
                                }
                            >
                                {places.map((p) => (
                                    <option key={p.slug} value={p.slug}>
                                        {p.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1">
                            Who gets it
                            <select
                                className="h-11 rounded-md border bg-background px-3"
                                value={form.data.audience}
                                onChange={(e) =>
                                    form.setData('audience', e.target.value)
                                }
                            >
                                {audiences.map((a) => (
                                    <option key={a.key} value={a.key}>
                                        {a.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <Button disabled={form.processing}>
                            Start the monthly report
                        </Button>
                        {Object.values(form.errors)[0] && (
                            <p
                                role="alert"
                                className="text-destructive sm:col-span-3"
                            >
                                {Object.values(form.errors)[0]}
                            </p>
                        )}
                    </form>
                )}
                <section className="grid gap-2">
                    <h2 className="text-lg font-semibold">Running now</h2>
                    {reports.length === 0 ? (
                        <p className="text-muted-foreground">None yet.</p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {reports.map((r) => (
                                <li
                                    key={r.place}
                                    className="flex flex-wrap justify-between gap-2 p-3"
                                >
                                    <span className="font-medium">
                                        {r.name}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {r.people - r.stopped} getting it
                                        {r.stopped > 0 &&
                                            `, ${r.stopped} stopped`}{' '}
                                        · {r.sent} sent
                                        {r.next_on && ` · next ${r.next_on}`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}
