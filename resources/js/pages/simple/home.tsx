import { Head, Link, usePage } from '@inertiajs/react';
import {
    BriefcaseIcon,
    CircleHelpIcon,
    FilePlusIcon,
    ListChecksIcon,
    MicIcon,
    PhoneIcon,
    UserPlusIcon,
} from 'lucide-react';

import { Button } from '@/components/ui/button';

type Item = {
    key: string;
    title: string;
    why: string;
    action: string;
    url: string;
    urgency: number;
};

/** The simple home (SM1): "What do you want to do?" with big buttons, and what needs you today. */
export default function SimpleHome({
    firstName,
    today,
}: {
    firstName: string;
    today: Item[];
}) {
    const w = usePage().props.simple?.words ?? {};
    const tiles = [
        { href: '/contacts?add=1', label: w.add_person, icon: UserPlusIcon },
        { href: '/deals#start', label: w.start_deal, icon: FilePlusIcon },
        { href: '/today', label: w.call_today, icon: PhoneIcon },
        { href: '/deals', label: w.my_deals, icon: BriefcaseIcon },
        { href: '/tasks', label: w['/tasks'], icon: ListChecksIcon },
        { href: '/policies', label: w.ask, icon: CircleHelpIcon },
    ];

    return (
        <>
            <Head title={w.home ?? 'Home'} />
            <div className="mx-auto flex max-w-3xl flex-col gap-6 p-4 md:p-8">
                <h1 className="text-3xl font-semibold">
                    {(w.greeting ?? 'Hello, :name').replace(':name', firstName)}
                </h1>
                <Button
                    size="lg"
                    data-tour="talk"
                    className="h-20 justify-start gap-4 rounded-2xl px-6 text-xl"
                    onClick={() =>
                        window.dispatchEvent(new Event('assistant:open'))
                    }
                >
                    <MicIcon className="size-8" aria-hidden />
                    <span className="grid text-left">
                        {w.talk}
                        <span className="text-sm font-normal opacity-80">
                            {w.talk_hint}
                        </span>
                    </span>
                </Button>
                <section aria-labelledby="what" className="grid gap-3">
                    <h2 id="what" className="text-xl font-semibold">
                        {w.what}
                    </h2>
                    <div
                        className="grid grid-cols-2 gap-3 md:grid-cols-3"
                        data-tour="buttons"
                    >
                        {tiles.map((t) => (
                            <Link
                                key={t.href}
                                href={t.href}
                                className="flex min-h-28 flex-col items-center justify-center gap-2 rounded-2xl border-2 p-4 text-center text-lg font-medium hover:border-primary hover:bg-primary/5 focus-visible:border-primary"
                            >
                                <t.icon
                                    className="size-8 text-primary"
                                    aria-hidden
                                />
                                {t.label}
                            </Link>
                        ))}
                    </div>
                </section>
                <section
                    aria-labelledby="today"
                    className="grid gap-3"
                    data-tour="today"
                >
                    <h2 id="today" className="text-xl font-semibold">
                        {w.needs_you}
                    </h2>
                    {today.length === 0 ? (
                        <p className="text-lg text-muted-foreground">
                            {w.nothing}
                        </p>
                    ) : (
                        <ul className="grid gap-2">
                            {today.map((i) => (
                                <li key={i.key}>
                                    <Link
                                        href={i.url}
                                        className="flex items-center gap-3 rounded-xl border p-4 text-lg hover:border-primary"
                                    >
                                        <span
                                            className={`size-3 shrink-0 rounded-full ${i.urgency >= 3 ? 'bg-destructive' : i.urgency === 2 ? 'bg-amber-500' : 'bg-muted-foreground'}`}
                                            aria-hidden
                                        />
                                        <span className="grid">
                                            {i.title}
                                            {i.why && (
                                                <span className="text-base text-muted-foreground">
                                                    {i.why}
                                                </span>
                                            )}
                                        </span>
                                        <span className="ml-auto text-base text-primary">
                                            {i.action} →
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}
