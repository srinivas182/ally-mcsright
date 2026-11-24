import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';

import { type BriefItem, DailyBrief } from '@/components/brief/daily-brief';
import { WelcomeHero } from '@/components/welcome-hero';

type Props = {
    firstName: string;
    assisting: string | null;
    brief: BriefItem[];
    briefEmail: boolean;
    morningText?: boolean | null;
};

/** MN3: an agent's numbers, one click from the dashboard (they also have their own Reports menu). */
const NUMBERS: Record<string, string> = {
    '/insights/me': 'How you are doing this year: deals, volume and income.',
    '/insights/plan':
        'Your yearly goal turned into weekly calls and appointments.',
    '/insights/reports': 'Ready-made reports you can download or email.',
};

function YourNumbers() {
    const { navigation } = usePage().props;
    const items = navigation
        .flatMap((g) => g.items)
        .filter((i) => i.href in NUMBERS);
    if (items.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="your-numbers" className="grid gap-3">
            <h2 id="your-numbers" className="text-lg font-semibold">
                Your numbers
            </h2>
            <div className="grid gap-3 md:grid-cols-3">
                {items.map((i) => (
                    <Link
                        key={i.href}
                        href={i.href}
                        className="flex items-center gap-3 rounded-xl border bg-card p-4 hover:border-primary"
                    >
                        <span className="flex-1">
                            <span className="block font-semibold">
                                {i.title}
                            </span>
                            <span className="block text-sm text-muted-foreground">
                                {NUMBERS[i.href]}
                            </span>
                        </span>
                        <ChevronRight
                            className="size-5 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </Link>
                ))}
            </div>
        </section>
    );
}

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
                <YourNumbers />
            </div>
        </>
    );
}

AgentHome.layout = {
    breadcrumbs: [{ title: 'Today', href: '/agent' }],
};
