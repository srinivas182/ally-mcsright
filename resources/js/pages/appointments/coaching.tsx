import { Head, Link } from '@inertiajs/react';

import { PageHeader } from '@/components/ui/page-header';

type Trends = {
    count: number;
    talk_share: number | null;
    objections: { objection: string; count: number }[];
    try_next: string[];
};

/** VO2 (specs/listings/05): the agent's own coaching over their last ten recorded appointments. Only they see it. */
export default function Coaching({ trends }: { trends: Trends }) {
    return (
        <>
            <Head title="My coaching" />
            <div className="mx-auto grid w-full max-w-3xl gap-6 p-4 text-base md:p-6">
                <PageHeader
                    title="My coaching"
                    description="From your last ten recorded appointments. Only you see this."
                />
                {trends.count === 0 ? (
                    <p className="text-muted-foreground">
                        Nothing yet. Record an appointment (with everyone's
                        consent) from{' '}
                        <Link href="/appointments" className="underline">
                            Appointments
                        </Link>{' '}
                        and your coaching appears here.
                    </p>
                ) : (
                    <>
                        <section className="grid gap-1 rounded-lg border p-4">
                            <h2 className="text-lg font-semibold">
                                Talking and listening
                            </h2>
                            <p>
                                {trends.talk_share !== null
                                    ? `On average you talked ${trends.talk_share}% of the time across ${trends.count} appointments.`
                                    : `${trends.count} appointments recorded.`}
                            </p>
                        </section>
                        {trends.objections.length > 0 && (
                            <section className="grid gap-1 rounded-lg border p-4">
                                <h2 className="text-lg font-semibold">
                                    Concerns that keep coming up
                                </h2>
                                <ul className="list-disc pl-6">
                                    {trends.objections.map((o) => (
                                        <li key={o.objection}>
                                            {o.objection}: {o.count}{' '}
                                            {o.count === 1 ? 'time' : 'times'}
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}
                        {trends.try_next.length > 0 && (
                            <section className="grid gap-1 rounded-lg border p-4">
                                <h2 className="text-lg font-semibold">
                                    To work on
                                </h2>
                                <ul className="list-disc pl-6">
                                    {trends.try_next.map((t) => (
                                        <li key={t}>{t}</li>
                                    ))}
                                </ul>
                            </section>
                        )}
                    </>
                )}
            </div>
        </>
    );
}
