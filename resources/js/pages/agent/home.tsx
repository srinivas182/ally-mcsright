import { Head } from '@inertiajs/react';

import { type BriefItem, DailyBrief } from '@/components/brief/daily-brief';

type Props = {
    firstName: string;
    assisting: string | null;
    brief: BriefItem[];
    briefEmail: boolean;
};

/** Agents' (and their assistants') home: the daily brief (specs/core/09). */
export default function AgentHome({
    firstName,
    assisting,
    brief,
    briefEmail,
}: Props) {
    return (
        <>
            <Head title="Today" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <header>
                    <h1 className="text-2xl font-semibold">
                        Good to see you, {firstName}
                    </h1>
                    {assisting && (
                        <p className="text-sm text-muted-foreground">
                            You're working for {assisting}.
                        </p>
                    )}
                </header>
                <DailyBrief items={brief} email={briefEmail} />
            </div>
        </>
    );
}

AgentHome.layout = {
    breadcrumbs: [{ title: 'Today', href: '/agent' }],
};
