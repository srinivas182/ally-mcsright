import { Head } from '@inertiajs/react';

import { type BriefItem, DailyBrief } from '@/components/brief/daily-brief';
import { dashboard } from '@/routes';

/** Owners' and staff's home: the brokerage's daily brief (specs/core/09). */
export default function Dashboard({
    firstName,
    brief,
    briefEmail,
}: {
    firstName: string;
    brief: BriefItem[];
    briefEmail: boolean;
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
