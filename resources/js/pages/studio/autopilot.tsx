import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Settings = {
    mode: string;
    places: string[] | null;
    times: string[];
    per_week: number;
    moments: string[];
};

type Item = {
    ulid: string;
    moment: string;
    status: string;
    image: string | null;
    caption: string;
    post_at: string | null;
    reason: string | null;
};

function QueueItem({ item }: { item: Item }) {
    const [caption, setCaption] = useState(item.caption);
    const [at, setAt] = useState(item.post_at ?? '');
    const waiting = item.status === 'awaiting';

    return (
        <li className="grid gap-3 rounded-xl border p-3 sm:grid-cols-[180px_1fr]">
            {item.image ? (
                <img
                    src={item.image}
                    alt={`${item.moment} design`}
                    className="aspect-[4/5] w-full rounded-lg object-cover"
                />
            ) : (
                <div className="grid aspect-[4/5] place-items-center rounded-lg bg-muted text-sm">
                    {item.status === 'designing' ? 'Designing…' : '—'}
                </div>
            )}
            <div className="grid content-start gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium">{item.moment}</span>
                    <Badge variant={waiting ? 'default' : 'outline'}>
                        {waiting ? 'waiting for your OK' : item.status}
                    </Badge>
                </div>
                {item.reason && (
                    <p className="text-sm text-destructive">{item.reason}</p>
                )}
                {waiting ? (
                    <>
                        <textarea
                            className="min-h-32 rounded-md border bg-background p-3 text-sm"
                            value={caption}
                            onChange={(e) => setCaption(e.target.value)}
                        />
                        <label className="grid gap-1 text-sm">
                            When
                            <Input
                                type="datetime-local"
                                value={at}
                                onChange={(e) => setAt(e.target.value)}
                            />
                        </label>
                        <div className="flex gap-2">
                            <Button
                                onClick={() =>
                                    router.post(
                                        `/studio/autopilot/${item.ulid}/approve`,
                                        {
                                            caption:
                                                caption === item.caption
                                                    ? ''
                                                    : caption,
                                            post_at: at,
                                        },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Approve
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        `/studio/autopilot/${item.ulid}/skip`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Skip
                            </Button>
                        </div>
                    </>
                ) : (
                    <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                        {item.post_at && `${item.post_at.replace('T', ' ')} · `}
                        {item.caption}
                    </p>
                )}
            </div>
        </li>
    );
}

/**
 * Ally Studio → Autopilot (specs/grow/13, SA2): what Studio may post about, where and when, and the posts waiting
 * for an OK.
 */
export default function StudioAutopilot({
    ready,
    settings,
    modes,
    kinds,
    places,
    timezone,
    queue,
}: {
    ready: boolean;
    settings: Settings;
    modes: Record<string, string>;
    kinds: Record<string, string>;
    places: { key: string; label: string }[];
    timezone: string;
    queue: Item[];
}) {
    const form = useForm({
        mode: settings.mode,
        moments: settings.moments,
        places: settings.places ?? places.map((p) => p.key),
        times: settings.times,
        per_week: settings.per_week,
    });
    const toggle = (list: string[], key: string, on: boolean) =>
        on ? [...list, key] : list.filter((k) => k !== key);

    if (!ready) {
        return (
            <div className="p-4">
                <Head title="Autopilot" />
                <EmptyState
                    title="Autopilot comes with Ally Studio"
                    description="Choose a Studio package and Studio plans, designs and writes your posts."
                />
                <div className="mt-4">
                    <Button asChild>
                        <Link href="/studio">See the packages</Link>
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <>
            <Head title="Autopilot" />
            <div className="grid max-w-4xl gap-6 p-4 text-base">
                <PageHeader
                    title="Autopilot"
                    description="Studio notices your moments, designs the post, writes the caption and picks a good time."
                />

                <form
                    className="grid gap-4 rounded-lg border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/studio/autopilot', {
                            preserveScroll: true,
                        });
                    }}
                >
                    <fieldset className="flex flex-wrap gap-4">
                        <legend className="mb-1 font-medium">Mode</legend>
                        {Object.entries(modes).map(([k, l]) => (
                            <label key={k} className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    className="size-5"
                                    checked={form.data.mode === k}
                                    onChange={() => form.setData('mode', k)}
                                />
                                {l}
                            </label>
                        ))}
                    </fieldset>
                    <fieldset className="grid gap-2">
                        <legend className="mb-1 font-medium">Post about</legend>
                        {Object.entries(kinds).map(([k, l]) => (
                            <label key={k} className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    className="size-5"
                                    checked={form.data.moments.includes(k)}
                                    onChange={(e) =>
                                        form.setData(
                                            'moments',
                                            toggle(
                                                form.data.moments,
                                                k,
                                                e.target.checked,
                                            ),
                                        )
                                    }
                                />
                                {l}
                            </label>
                        ))}
                    </fieldset>
                    <fieldset className="grid gap-2">
                        <legend className="mb-1 font-medium">Where</legend>
                        {places.length === 0 && (
                            <p className="text-sm">
                                <Link href="/social" className="underline">
                                    Connect where you post
                                </Link>{' '}
                                first.
                            </p>
                        )}
                        {places.map((p) => (
                            <label
                                key={p.key}
                                className="flex items-center gap-2"
                            >
                                <input
                                    type="checkbox"
                                    className="size-5"
                                    checked={form.data.places.includes(p.key)}
                                    onChange={(e) =>
                                        form.setData(
                                            'places',
                                            toggle(
                                                form.data.places,
                                                p.key,
                                                e.target.checked,
                                            ),
                                        )
                                    }
                                />
                                {p.label}
                            </label>
                        ))}
                    </fieldset>
                    <div className="grid gap-3 sm:grid-cols-3">
                        {[0, 1].map((i) => (
                            <label key={i} className="grid gap-1">
                                {i === 0 ? 'Morning time' : 'Evening time'} (
                                {timezone})
                                <Input
                                    type="time"
                                    value={form.data.times[i] ?? ''}
                                    onChange={(e) =>
                                        form.setData(
                                            'times',
                                            [0, 1].map((j) =>
                                                j === i
                                                    ? e.target.value
                                                    : (form.data.times[j] ??
                                                      ''),
                                            ),
                                        )
                                    }
                                />
                            </label>
                        ))}
                        <label className="grid gap-1">
                            Posts a week (at most)
                            <Input
                                type="number"
                                min={1}
                                max={14}
                                value={form.data.per_week}
                                onChange={(e) =>
                                    form.setData(
                                        'per_week',
                                        Number(e.target.value),
                                    )
                                }
                            />
                        </label>
                    </div>
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            Save Autopilot
                        </Button>
                    </div>
                </form>

                <section className="grid gap-3">
                    <h2 className="font-medium">Planned posts</h2>
                    {queue.length === 0 ? (
                        <p className="text-muted-foreground">
                            Nothing planned yet. Studio checks for new moments
                            every hour.
                        </p>
                    ) : (
                        <ul className="grid gap-3">
                            {queue.map((q) => (
                                <QueueItem key={q.ulid} item={q} />
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}
