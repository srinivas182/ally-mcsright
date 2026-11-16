import { Head, Link, router, usePoll } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';

type Reel = {
    ulid: string;
    status: 'rendering' | 'ready' | 'failed';
    error: string | null;
    url: string | null;
    poster: string | null;
    seconds: number;
    used: boolean;
    moment: string;
    title: string;
    music: string | null;
};

/**
 * Studio → Reels (specs/grow/13, SA3): Reels and Shorts made from listing designs, in the same style: the cover,
 * the photos and the details card with slow camera moves and licensed music. Watch, download or post.
 */
export default function StudioReels({
    ready,
    usage,
    reels,
}: {
    ready: boolean;
    usage: { used: number; extra: number; allowance: number };
    moods: Record<string, string>;
    tracks: { ulid: string; label: string; mood: string }[];
    reels: Reel[];
}) {
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
                                        className="aspect-[9/16] w-full rounded-lg bg-black object-cover"
                                    />
                                ) : (
                                    <div className="grid aspect-[9/16] place-items-center rounded-lg bg-muted p-4 text-center text-sm">
                                        {r.status === 'failed'
                                            ? (r.error ?? 'This Reel failed.')
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
