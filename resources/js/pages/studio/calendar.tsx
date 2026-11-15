import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Event = {
    id: string;
    kind: 'studio' | 'post';
    title: string;
    date: string | null;
    time: string | null;
    status: string;
    image: string | null;
    movable: boolean;
};

const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

const shift = (month: string, by: number) => {
    const [y, m] = month.split('-').map(Number);
    const d = new Date(Date.UTC(y, m - 1 + by, 1));

    return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}`;
};

/**
 * Ally Studio → Calendar (specs/grow/13, SA2b): the month's planned and sent posts. Drag one of Studio's planned
 * posts to another day to move it (its time stays).
 */
export default function StudioCalendar({
    month,
    label,
    first_weekday,
    days,
    today,
    events,
    timezone,
}: {
    month: string;
    label: string;
    first_weekday: number;
    days: number;
    today: string;
    events: Event[];
    timezone: string;
}) {
    const [over, setOver] = useState<string | null>(null);
    const cells = [
        ...Array.from({ length: first_weekday }, () => null),
        ...Array.from(
            { length: days },
            (_, i) => `${month}-${String(i + 1).padStart(2, '0')}`,
        ),
    ];
    const drop = (date: string, id: string) =>
        router.post(
            `/studio/autopilot/${id}/move`,
            { date },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Studio calendar" />
            <div className="grid gap-4 p-4 text-base">
                <PageHeader
                    title="Calendar"
                    description={`Your planned and sent posts (${timezone}). Drag a Studio post to another day to move it.`}
                />
                <div className="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            router.get('/studio/calendar', {
                                month: shift(month, -1),
                            })
                        }
                    >
                        Previous
                    </Button>
                    <span className="min-w-36 text-center font-medium">
                        {label}
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            router.get('/studio/calendar', {
                                month: shift(month, 1),
                            })
                        }
                    >
                        Next
                    </Button>
                    <Link
                        href="/studio/autopilot"
                        className="ml-auto text-sm underline"
                    >
                        Autopilot
                    </Link>
                </div>
                <div className="grid grid-cols-7 gap-1 text-sm">
                    {weekdays.map((w) => (
                        <div
                            key={w}
                            className="p-1 text-center text-muted-foreground"
                        >
                            {w}
                        </div>
                    ))}
                    {cells.map((date, i) =>
                        date === null ? (
                            <div key={`blank-${i}`} />
                        ) : (
                            <div
                                key={date}
                                className={`grid min-h-28 content-start gap-1 rounded-md border p-1 ${date === today ? 'border-primary' : ''} ${over === date ? 'bg-muted' : ''}`}
                                onDragOver={(e) => {
                                    e.preventDefault();
                                    setOver(date);
                                }}
                                onDragLeave={() => setOver(null)}
                                onDrop={(e) => {
                                    e.preventDefault();
                                    setOver(null);
                                    const id =
                                        e.dataTransfer.getData('text/plain');
                                    if (id) {
                                        drop(date, id);
                                    }
                                }}
                            >
                                <span className="text-xs text-muted-foreground">
                                    {Number(date.slice(8))}
                                </span>
                                {events
                                    .filter((ev) => ev.date === date)
                                    .map((ev) => (
                                        <div
                                            key={ev.id}
                                            draggable={
                                                ev.movable &&
                                                ev.kind === 'studio'
                                            }
                                            onDragStart={(e) =>
                                                e.dataTransfer.setData(
                                                    'text/plain',
                                                    ev.id,
                                                )
                                            }
                                            className={`flex items-center gap-1 rounded bg-background p-1 shadow-sm ${ev.movable && ev.kind === 'studio' ? 'cursor-grab' : ''}`}
                                            title={`${ev.title} · ${ev.time ?? ''} · ${ev.status}`}
                                        >
                                            {ev.image && (
                                                <img
                                                    src={ev.image}
                                                    alt=""
                                                    className="size-8 rounded object-cover"
                                                />
                                            )}
                                            <span className="grid min-w-0">
                                                <span className="truncate text-xs">
                                                    {ev.title}
                                                </span>
                                                <span className="text-[11px] text-muted-foreground">
                                                    {ev.time}
                                                </span>
                                            </span>
                                            {ev.status === 'awaiting' && (
                                                <Badge
                                                    variant="outline"
                                                    className="ml-auto text-[10px]"
                                                >
                                                    OK?
                                                </Badge>
                                            )}
                                        </div>
                                    ))}
                            </div>
                        ),
                    )}
                </div>
            </div>
        </>
    );
}
