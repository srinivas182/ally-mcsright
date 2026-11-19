import { Head, Link } from '@inertiajs/react';

import { type BriefItem, DailyBrief } from '@/components/brief/daily-brief';
import { dashboard } from '@/routes';

/** Owners' and staff's home: the brokerage's daily brief (specs/core/09). */
export default function Dashboard({
    firstName,
    brief,
    briefEmail,
    setup = null,
}: {
    firstName: string;
    brief: BriefItem[];
    briefEmail: boolean;
    setup?: { done: number; total: number } | null;
}) {
    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header>
                    <h1 className="text-2xl font-semibold">
                        Good to see you, {firstName}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        What needs you across the brokerage today: leads, deals,
                        money and licences.
                    </p>
                </header>
                {setup && (
                    <Link
                        href="/setup"
                        className="flex items-center justify-between gap-3 rounded-xl border-2 border-primary/40 p-4 text-lg hover:border-primary"
                    >
                        <span>
                            Finish setting up: {setup.done} of {setup.total}{' '}
                            done
                        </span>
                        <span className="text-primary">Continue →</span>
                    </Link>
                )}
                <DailyBrief items={brief} email={briefEmail} />
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
