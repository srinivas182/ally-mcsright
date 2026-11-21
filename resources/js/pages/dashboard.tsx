import { Head, Link } from '@inertiajs/react';

import { type BriefItem, DailyBrief } from '@/components/brief/daily-brief';
import { WelcomeHero } from '@/components/welcome-hero';
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
            <div className="flex flex-col gap-6 p-4 md:p-8">
                <WelcomeHero
                    firstName={firstName}
                    line="What needs you across the brokerage today: leads, deals, money and licences."
                >
                    {setup && (
                        <Link
                            href="/setup"
                            className="inline-flex flex-wrap items-center gap-4 rounded-xl bg-white/10 px-4 py-3 ring-1 ring-white/20 backdrop-blur transition-colors hover:bg-white/15"
                        >
                            <span className="grid gap-1.5">
                                <span className="font-medium">
                                    Finish setting up: {setup.done} of{' '}
                                    {setup.total} done
                                </span>
                                <span
                                    className="h-1.5 w-56 overflow-hidden rounded-full bg-white/15"
                                    aria-hidden="true"
                                >
                                    <span
                                        className="block h-full rounded-full bg-[#E2B65C]"
                                        style={{
                                            width: `${Math.round((setup.done / Math.max(1, setup.total)) * 100)}%`,
                                        }}
                                    />
                                </span>
                            </span>
                            <span className="rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-[#0B3FB8]">
                                Continue
                            </span>
                        </Link>
                    )}
                </WelcomeHero>
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
