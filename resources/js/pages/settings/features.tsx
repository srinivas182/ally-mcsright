import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Person = { ulid: string; name: string };

type Area = {
    key: string;
    label: string;
    about: string;
    seats_possible: boolean;
    on: boolean;
    hq_allows: boolean;
    brokerage_off: boolean;
    seats: number | null;
    holders: Person[];
};

type Props = { areas: Area[]; people: Person[] };

/**
 * FL1 (specs/core/16): the owner switches areas on or off for the whole brokerage (an area switched off disappears
 * from everyone's menu), and gives seats in areas sold by the seat.
 */
export default function Features({ areas, people }: Props) {
    const opts = { preserveScroll: true };

    return (
        <>
            <Head title="Features and seats" />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Features and seats"
                    description="Switch off what your brokerage does not use; it disappears from everyone's menu. Some features are sold by the seat: choose who gets one."
                />
                <ul className="grid gap-3">
                    {areas.map((a) => (
                        <li
                            key={a.key}
                            className="grid gap-3 rounded-lg border p-4 text-base"
                        >
                            <div className="flex flex-wrap items-center gap-3">
                                <div className="grow">
                                    <h2 className="font-semibold">{a.label}</h2>
                                    <p className="text-muted-foreground">
                                        {a.about}
                                    </p>
                                </div>
                                {a.hq_allows ? (
                                    <label className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            className="size-5"
                                            checked={!a.brokerage_off}
                                            onChange={(e) =>
                                                router.post(
                                                    '/settings/features',
                                                    {
                                                        area: a.key,
                                                        on: e.target.checked,
                                                    },
                                                    opts,
                                                )
                                            }
                                        />
                                        {a.on ? 'On' : 'Off'}
                                    </label>
                                ) : (
                                    <Badge variant="outline">
                                        Not in your package
                                    </Badge>
                                )}
                            </div>
                            {a.on && a.seats !== null && (
                                <Seats area={a} people={people} />
                            )}
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

function Seats({ area, people }: { area: Area; people: Person[] }) {
    const [chosen, setChosen] = useState('');
    const opts = { preserveScroll: true };
    const holderIds = new Set(area.holders.map((h) => h.ulid));
    const left = (area.seats ?? 0) - area.holders.length;

    return (
        <div className="grid gap-2 border-t pt-3">
            <p>
                <strong>
                    {area.holders.length} of {area.seats}
                </strong>{' '}
                seats used. Owners always have access.
            </p>
            {area.holders.length > 0 && (
                <ul className="flex flex-wrap gap-2">
                    {area.holders.map((h) => (
                        <li
                            key={h.ulid}
                            className="flex items-center gap-1 rounded-full border px-3 py-1"
                        >
                            {h.name}
                            <Button
                                variant="ghost"
                                size="sm"
                                aria-label={`Take ${h.name}'s seat`}
                                onClick={() =>
                                    router.post(
                                        '/settings/features/seats',
                                        {
                                            area: area.key,
                                            user: h.ulid,
                                            give: false,
                                        },
                                        opts,
                                    )
                                }
                            >
                                Remove
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            {left > 0 && (
                <div className="flex flex-wrap items-center gap-2">
                    <select
                        aria-label={`Give a seat for ${area.label}`}
                        className="h-10 rounded-md border bg-background px-3"
                        value={chosen}
                        onChange={(e) => setChosen(e.target.value)}
                    >
                        <option value="">Choose a person…</option>
                        {people
                            .filter((p) => !holderIds.has(p.ulid))
                            .map((p) => (
                                <option key={p.ulid} value={p.ulid}>
                                    {p.name}
                                </option>
                            ))}
                    </select>
                    <Button
                        disabled={chosen === ''}
                        onClick={() =>
                            router.post(
                                '/settings/features/seats',
                                { area: area.key, user: chosen, give: true },
                                { ...opts, onSuccess: () => setChosen('') },
                            )
                        }
                    >
                        Give a seat
                    </Button>
                </div>
            )}
        </div>
    );
}
