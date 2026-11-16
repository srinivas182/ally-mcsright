import { Head, Link, router, usePoll } from '@inertiajs/react';
import { useState } from 'react';

import { postJson } from '@/lib/http';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';

type Reel = {
    ulid: string;
    listing: string | null;
    kind: 'reel' | 'tour';
    chapters: { at: number; title: string }[];
    status: 'rendering' | 'ready' | 'failed';
    error: string | null;
    url: string | null;
    poster: string | null;
    seconds: number;
    used: boolean;
    moment: string;
    title: string;
    music: string | null;
    narration: string | null;
    voice: string | null;
    narration_error: string | null;
    max_words: number;
};

type Voice = { key: string; label: string };

/** SA5b: a voice-over for one video, drafted by AI to fit, editable, in a stock voice or the agent's own. */
function Narrate({ reel, voices }: { reel: Reel; voices: Voice[] }) {
    const [open, setOpen] = useState(false);
    const [script, setScript] = useState(reel.narration ?? '');
    const [voice, setVoice] = useState(reel.voice ?? voices[0]?.key ?? '');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const words = script.trim() === '' ? 0 : script.trim().split(/\s+/).length;

    const draft = () => {
        setBusy(true);
        setError(null);
        postJson<{ script: string }>(`/studio/reels/${reel.ulid}/script`, {})
            .then((d) => setScript(d.script))
            .catch(() =>
                setError("The AI couldn't write it just now. Write your own."),
            )
            .finally(() => setBusy(false));
    };

    if (!open) {
        return (
            <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
                {reel.narration ? 'Change the voice-over' : 'Add a voice-over'}
            </Button>
        );
    }

    return (
        <div className="grid w-full gap-2 rounded-lg border p-2 text-sm">
            <label className="grid gap-1">
                Voice
                <select
                    className="h-10 rounded-md border bg-background px-2"
                    value={voice}
                    onChange={(e) => setVoice(e.target.value)}
                >
                    {voices.map((v) => (
                        <option key={v.key} value={v.key}>
                            {v.label}
                        </option>
                    ))}
                </select>
            </label>
            <label className="grid gap-1">
                What it says ({words} of about {reel.max_words} words)
                <textarea
                    className="min-h-28 rounded-md border bg-background p-2"
                    value={script}
                    onChange={(e) => setScript(e.target.value)}
                />
            </label>
            <p className="text-xs text-muted-foreground">
                Describe the home, not who should live there (US Fair Housing).
            </p>
            {error && <p className="text-destructive">{error}</p>}
            <div className="flex flex-wrap gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    disabled={busy}
                    onClick={draft}
                >
                    {busy ? 'Writing…' : 'Write it with AI'}
                </Button>
                <Button
                    size="sm"
                    disabled={script.trim() === '' || voice === ''}
                    onClick={() =>
                        router.post(
                            `/studio/reels/${reel.ulid}/narrate`,
                            { narration: script, voice },
                            {
                                onError: (e) =>
                                    setError(
                                        Object.values(e).join(' ') || null,
                                    ),
                            },
                        )
                    }
                >
                    Add the voice-over
                </Button>
            </div>
        </div>
    );
}

/**
 * Studio → Reels (specs/grow/13, SA3): Reels and Shorts made from listing designs, in the same style: the cover,
 * the photos and the details card with slow camera moves and licensed music. Watch, download or post.
 */
export default function StudioReels({
    ready,
    usage,
    tour_usage,
    listings,
    reels,
    voices,
    voice_usage,
    voice_on,
}: {
    ready: boolean;
    usage: { used: number; extra: number; allowance: number };
    tour_usage: { used: number; extra: number; allowance: number };
    listings: { ulid: string; label: string }[];
    moods: Record<string, string>;
    tracks: { ulid: string; label: string; mood: string }[];
    reels: Reel[];
    voices: Voice[];
    voice_usage: { used: number; extra: number; allowance: number };
    voice_on: boolean;
}) {
    const [listing, setListing] = useState(listings[0]?.ulid ?? '');
    usePoll(
        8_000,
        { only: ['reels', 'usage'] },
        { autoStart: reels.some((r) => r.status === 'rendering') },
    );

    if (!ready) {
        return (
            <div className="p-4">
                <Head title="Reels" />
                <EmptyState
                    title="Reels come with Ally Studio"
                    description="Choose a Studio package to turn your listing designs into Reels and Shorts."
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
            <Head title="Reels" />
            <div className="grid max-w-5xl gap-6 p-4 text-base">
                <PageHeader
                    title="Reels and Shorts"
                    description="Your listing designs as 9:16 videos in the same style: the cover, each photo and the details, with slow camera moves and licensed music."
                />
                <div className="flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                    <span>
                        {usage.used} of {usage.allowance} Reels used this month.
                        Making one is free; it counts when you download or post
                        it.
                    </span>
                    <Button asChild size="sm" variant="outline">
                        <Link href="/studio/create">
                            Make one from a listing design
                        </Link>
                    </Button>
                    {voice_on && (
                        <span>
                            {voice_usage.used} of {voice_usage.allowance}{' '}
                            voice-overs used.{' '}
                            <Link href="/studio/voice" className="underline">
                                My own voice
                            </Link>
                        </span>
                    )}
                </div>
                <div className="flex flex-wrap items-center gap-3 rounded-xl border p-3">
                    <span className="font-medium">YouTube listing tour</span>
                    <span className="text-sm text-muted-foreground">
                        Landscape, about a minute, a chapter per room.{' '}
                        {tour_usage.used} of {tour_usage.allowance} this month.
                    </span>
                    <select
                        className="h-10 rounded-md border bg-background px-3"
                        aria-label="Listing for the tour"
                        value={listing}
                        onChange={(e) => setListing(e.target.value)}
                    >
                        {listings.map((l) => (
                            <option key={l.ulid} value={l.ulid}>
                                {l.label}
                            </option>
                        ))}
                    </select>
                    <Button
                        size="sm"
                        disabled={listing === ''}
                        onClick={() =>
                            router.post('/studio/tours', { listing })
                        }
                    >
                        Make a tour
                    </Button>
                </div>
                {reels.length === 0 ? (
                    <EmptyState
                        title="No Reels yet"
                        description="Create a listing design, then choose Make a Reel."
                    />
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {reels.map((r) => (
                            <li
                                key={r.ulid}
                                className="grid content-start gap-2 rounded-xl border p-2"
                            >
                                {r.status === 'ready' && r.url ? (
                                    <video
                                        src={r.url}
                                        poster={r.poster ?? undefined}
                                        controls
                                        playsInline
                                        preload="metadata"
                                        className={`${r.kind === 'tour' ? 'aspect-video' : 'aspect-[9/16]'} w-full rounded-lg bg-black object-cover`}
                                    />
                                ) : (
                                    <div
                                        className={`grid ${r.kind === 'tour' ? 'aspect-video' : 'aspect-[9/16]'} place-items-center rounded-lg bg-muted p-4 text-center text-sm`}
                                    >
                                        {r.status === 'failed'
                                            ? (r.error ?? 'This Reel failed.')
                                            : r.kind === 'tour'
                                              ? 'Making your tour…'
                                              : 'Making your Reel…'}
                                    </div>
                                )}
                                <div className="flex items-center justify-between gap-2 text-sm">
                                    <span className="truncate">
                                        {r.moment}: {r.title}
                                    </span>
                                    {r.used && (
                                        <Badge variant="outline">used</Badge>
                                    )}
                                </div>
                                {r.music && (
                                    <span className="text-xs text-muted-foreground">
                                        Music: {r.music}
                                        {r.narration
                                            ? ` · Voice-over: ${voices.find((v) => v.key === r.voice)?.label ?? 'AI voice'}`
                                            : ''}
                                    </span>
                                )}
                                {r.narration_error && (
                                    <span className="text-xs text-destructive">
                                        {r.narration_error}
                                    </span>
                                )}
                                {r.status === 'ready' && (
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    `/studio/reels/${r.ulid}/post`,
                                                )
                                            }
                                        >
                                            Post it
                                        </Button>
                                        {r.listing && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                asChild
                                            >
                                                <Link
                                                    href={`/ads/new?listing=${r.listing}&reel=${r.ulid}`}
                                                >
                                                    Advertise
                                                </Link>
                                            </Button>
                                        )}
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            asChild
                                        >
                                            <a
                                                href={`/studio/reels/${r.ulid}/download`}
                                            >
                                                Download MP4
                                            </a>
                                        </Button>
                                        {voice_on && voices.length > 0 && (
                                            <Narrate reel={r} voices={voices} />
                                        )}
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
