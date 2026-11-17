import { Head, Link } from '@inertiajs/react';

import { PageHeader } from '@/components/ui/page-header';

type Props = {
    booking: { active: boolean; url: string | null; bookings: number };
    videos: number;
    website: boolean;
    texting: string | null;
};

/** MK1: the Outreach hub, four big tiles in plain words. */
export default function Outreach({ booking, videos, website, texting }: Props) {
    const tiles = [
        {
            href: '/outreach/calls',
            title: 'Call queue',
            text: 'Work through a list one call at a time. Do Not Call and late hours are skipped for you.',
        },
        {
            href: '/outreach/booking',
            title: 'My booking link',
            text: booking.active
                ? `On. ${booking.bookings} booked so far.`
                : 'Let people pick a time with you, like Calendly, on your own website.',
        },
        {
            href: '/outreach/videos',
            title: 'Video messages',
            text:
                videos > 0
                    ? `${videos} video${videos === 1 ? '' : 's'}. See who watched.`
                    : 'Record a short video and send the link. You see when it is watched.',
        },
        ...(texting !== null
            ? [
                  {
                      href: '/settings/texting',
                      title: 'Texting registration',
                      text: `Carrier registration (A2P 10DLC): ${texting}.`,
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title="Outreach" />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Outreach"
                    description="Reach people the way they like: a call, a time they book themselves, or a short video."
                />
                {!website && (
                    <p className="rounded-lg border p-3 text-sm text-muted-foreground">
                        Booking links and video pages live on your brokerage
                        website. They work as soon as the website is live.
                    </p>
                )}
                <div className="grid gap-4 sm:grid-cols-2">
                    {tiles.map((t) => (
                        <Link
                            key={t.href}
                            href={t.href}
                            className="grid gap-1 rounded-lg border p-5 hover:bg-muted/50"
                        >
                            <span className="text-lg font-semibold">
                                {t.title}
                            </span>
                            <span className="text-muted-foreground">
                                {t.text}
                            </span>
                        </Link>
                    ))}
                </div>
            </div>
        </>
    );
}
