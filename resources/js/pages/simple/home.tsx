import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    BriefcaseIcon,
    CheckIcon,
    MessageSquareIcon,
    CalculatorIcon,
    CircleHelpIcon,
    FilePlusIcon,
    ListChecksIcon,
    MicIcon,
    PhoneIcon,
    UserPlusIcon,
} from 'lucide-react';

import { Button } from '@/components/ui/button';

type Person = {
    ulid: string;
    name: string;
    reason: string;
    phone: string | null;
    call: boolean;
    text: boolean;
    text_wait: string | null;
};

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
    people = [],
    today,
}: {
    firstName: string;
    people?: Person[];
    today: Item[];
}) {
    const [called, setCalled] = useState<string | null>(null);
    const w = usePage().props.simple?.words ?? {};
    const tiles = [
        { href: '/contacts?add=1', label: w.add_person, icon: UserPlusIcon },
        { href: '/deals#start', label: w.start_deal, icon: FilePlusIcon },
        { href: '/today', label: w.call_today, icon: PhoneIcon },
        { href: '/deals', label: w.my_deals, icon: BriefcaseIcon },
        { href: '/pricing', label: w.price_home, icon: CalculatorIcon },
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
                {people.length > 0 && (
                    <section
                        aria-labelledby="call"
                        className="grid gap-3"
                        data-tour="people"
                    >
                        <h2 id="call" className="text-xl font-semibold">
                            {w.call_today ?? 'Who to call today'}
                        </h2>
                        <ol className="grid gap-3">
                            {people.map((p, i) => (
                                <li
                                    key={p.ulid}
                                    className="grid gap-3 rounded-2xl border-2 p-4"
                                >
                                    <Link
                                        href={`/contacts/${p.ulid}`}
                                        className="grid text-lg hover:underline"
                                    >
                                        <span className="font-semibold">
                                            {i + 1}. {p.name}
                                        </span>
                                        <span className="text-base text-muted-foreground">
                                            {p.reason}
                                        </span>
                                    </Link>
                                    {called === p.ulid ? (
                                        <div className="grid gap-2">
                                            <p className="text-lg">
                                                {w.how_went ?? 'How did it go?'}
                                            </p>
                                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                                {(
                                                    [
                                                        [
                                                            'connected',
                                                            w.talked ??
                                                                'We talked',
                                                        ],
                                                        [
                                                            'left_voicemail',
                                                            w.left_message ??
                                                                'Left a message',
                                                        ],
                                                        [
                                                            'no_answer',
                                                            w.no_answer ??
                                                                'No answer',
                                                        ],
                                                    ] as const
                                                ).map(([outcome, text]) => (
                                                    <Button
                                                        key={outcome}
                                                        size="lg"
                                                        variant="outline"
                                                        className="h-14 text-lg"
                                                        onClick={() => {
                                                            setCalled(null);
                                                            router.post(
                                                                `/contacts/${p.ulid}/activities`,
                                                                {
                                                                    type: 'call',
                                                                    direction:
                                                                        'out',
                                                                    outcome,
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                        }}
                                                    >
                                                        <CheckIcon
                                                            className="size-5"
                                                            aria-hidden
                                                        />
                                                        {text}
                                                    </Button>
                                                ))}
                                            </div>
                                        </div>
                                    ) : (
                                        <div className="grid grid-cols-2 gap-2">
                                            {p.call && p.phone ? (
                                                <Button
                                                    size="lg"
                                                    className="h-16 text-xl"
                                                    asChild
                                                >
                                                    <a
                                                        href={`tel:${p.phone}`}
                                                        onClick={() =>
                                                            setCalled(p.ulid)
                                                        }
                                                    >
                                                        <PhoneIcon
                                                            className="size-6"
                                                            aria-hidden
                                                        />
                                                        {w.call ?? 'Call'}
                                                    </a>
                                                </Button>
                                            ) : (
                                                <p className="self-center text-base text-muted-foreground">
                                                    {w.no_call ??
                                                        'No phone number we may call.'}
                                                </p>
                                            )}
                                            {p.text && p.phone ? (
                                                <Button
                                                    size="lg"
                                                    variant="outline"
                                                    className="h-16 text-xl"
                                                    asChild
                                                >
                                                    <a href={`sms:${p.phone}`}>
                                                        <MessageSquareIcon
                                                            className="size-6"
                                                            aria-hidden
                                                        />
                                                        {w.text ?? 'Text'}
                                                    </a>
                                                </Button>
                                            ) : (
                                                <p className="self-center text-base text-muted-foreground">
                                                    {p.text_wait ??
                                                        w.no_text ??
                                                        'Texts only after they say yes to texts.'}
                                                </p>
                                            )}
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ol>
                    </section>
                )}

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
