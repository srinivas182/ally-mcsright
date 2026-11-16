import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Settings = {
    learn_voice: boolean;
    auto_enhance: boolean;
    extra_credits: boolean;
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
    needs_client_ok: boolean;
};

function QueueItem({ item }: { item: Item }) {
    const [caption, setCaption] = useState(item.caption);
    const [at, setAt] = useState(item.post_at ?? '');
    const [clientOk, setClientOk] = useState(false);
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
                    {item.status === 'designing'
                        ? 'Designing…'
                        : item.status === 'enhancing'
                          ? 'Enhancing the photos…'
                          : '—'}
                </div>
            )}
            <div className="grid content-start gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium">{item.moment}</span>
                    <Badge variant={waiting ? 'default' : 'outline'}>
                        {waiting
                            ? 'waiting for your OK'
                            : item.status === 'review'
                              ? 'with your broker'
                              : item.status}
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
                        {item.needs_client_ok && (
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    className="size-5"
                                    checked={clientOk}
                                    onChange={(e) =>
                                        setClientOk(e.target.checked)
                                    }
                                />
                                My client said yes to this post
                            </label>
                        )}
                        <div className="flex gap-2">
                            <Button
                                disabled={item.needs_client_ok && !clientOk}
                                onClick={() =>
                                    router.post(
                                        `/studio/autopilot/${item.ulid}/approve`,
                                        {
                                            caption:
                                                caption === item.caption
                                                    ? ''
                                                    : caption,
                                            post_at: at,
                                            client_ok: clientOk,
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
    has_voice,
    rules,
    review_required,
    usage,
    queue,
}: {
    ready: boolean;
    settings: Settings;
    modes: Record<string, string>;
    kinds: Record<string, string>;
    places: { key: string; label: string }[];
    timezone: string;
    has_voice: boolean;
    rules: { require_review: boolean; caption_disclaimer: string } | null;
    review_required: boolean;
    usage: Record<string, { used: number; extra: number; allowance: number }>;
    queue: Item[];
}) {
    const firm = useForm({
        require_review: rules?.require_review ?? false,
        caption_disclaimer: rules?.caption_disclaimer ?? '',
    });
    const form = useForm({
        mode: settings.mode,
        moments: settings.moments,
        places: settings.places ?? places.map((p) => p.key),
        times: settings.times,
        per_week: settings.per_week,
        learn_voice: settings.learn_voice,
        auto_enhance: settings.auto_enhance,
        extra_credits: settings.extra_credits,
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
                    {has_voice && (
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-5"
                                checked={form.data.learn_voice}
                                onChange={(e) =>
                                    form.setData(
                                        'learn_voice',
                                        e.target.checked,
                                    )
                                }
                            />
                            Learn my voice from my own recent Facebook posts
                            (only the words; you can switch it off any time)
                        </label>
                    )}
                    <p className="text-sm text-muted-foreground">
                        This month: {usage.post?.used ?? 0} of{' '}
                        {usage.post?.allowance ?? 0} designed posts and{' '}
                        {usage.enhance?.used ?? 0} of{' '}
                        {usage.enhance?.allowance ?? 0} photo enhancements in
                        your package.
                    </p>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            className="size-5"
                            checked={form.data.auto_enhance}
                            onChange={(e) =>
                                form.setData('auto_enhance', e.target.checked)
                            }
                        />
                        Enhance a new listing's best photos (and a twilight of
                        the cover) before it posts, from my package only
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            className="size-5"
                            checked={form.data.extra_credits}
                            onChange={(e) =>
                                form.setData('extra_credits', e.target.checked)
                            }
                        />
                        Use credits for extra posts when my package's posts are
                        used up (off: Autopilot waits for the next month)
                    </label>
                    {review_required && (
                        <p className="text-sm text-muted-foreground">
                            Your brokerage reviews posts before they go out, so
                            approved posts go to your broker first.
                        </p>
                    )}
                    <div className="flex flex-wrap gap-3">
                        <Button type="submit" disabled={form.processing}>
                            Save Autopilot
                        </Button>
                        <Button asChild variant="outline">
                            <Link href="/studio/calendar">Calendar</Link>
                        </Button>
                    </div>
                </form>

                {rules && (
                    <form
                        className="grid gap-3 rounded-lg border p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            firm.post('/studio/rules', {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <h2 className="font-medium">Brokerage rules</h2>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-5"
                                checked={firm.data.require_review}
                                onChange={(e) =>
                                    firm.setData(
                                        'require_review',
                                        e.target.checked,
                                    )
                                }
                            />
                            Review agents' Ally Studio posts before they go out
                        </label>
                        <label className="grid gap-1">
                            Line added to every caption (optional)
                            <Input
                                maxLength={300}
                                value={firm.data.caption_disclaimer}
                                onChange={(e) =>
                                    firm.setData(
                                        'caption_disclaimer',
                                        e.target.value,
                                    )
                                }
                            />
                        </label>
                        <div className="flex gap-3">
                            <Button type="submit" disabled={firm.processing}>
                                Save rules
                            </Button>
                            <Button asChild variant="outline">
                                <Link href="/studio/review">Review queue</Link>
                            </Button>
                        </div>
                    </form>
                )}

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
