import { Head } from '@inertiajs/react';

import { type BriefItem, DailyBrief } from '@/components/brief/daily-brief';
import { WelcomeHero } from '@/components/welcome-hero';

type Props = {
    firstName: string;
    assisting: string | null;
    brief: BriefItem[];
    briefEmail: boolean;
    morningText?: boolean | null;
};

/** Agents' (and their assistants') home: the daily brief (specs/core/09). */
export default function AgentHome({
    firstName,
    assisting,
    brief,
    briefEmail,
    morningText = null,
}: Props) {
    return (
        <>
            <Head title="Today" />
            <div className="flex flex-col gap-6 p-4 md:p-8">
                <WelcomeHero
                    firstName={firstName}
                    line={
                        assisting
                            ? `You're working for ${assisting}. Here is what needs doing today.`
                            : 'Here is what needs you today: new leads, follow-ups and deals.'
                    }
                />
                <DailyBrief
                    items={brief}
                    email={briefEmail}
                    morningText={morningText}
                />
            </div>
        </>
    );
}

AgentHome.layout = {
    breadcrumbs: [{ title: 'Today', href: '/agent' }],
};
