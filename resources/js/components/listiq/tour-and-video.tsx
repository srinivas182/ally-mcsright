import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type TourAndVideo = {
    tour: { url: string; provider: string; embed: string | null } | null;
    matterport: { id: string; name: string }[] | null;
    video: {
        status: string | null;
        url: string | null;
        poster: string | null;
        error: string | null;
        captions: string | null;
        photos: number;
        needs: number;
    };
};

/**
 * MD1 (specs/listings/03): the listing's 3D or 360-degree tour (a pasted link, or a Matterport space from the
 * connected account) and a vertical listing video made from the photos.
 */
export function TourAndVideoPanel({
    base,
    m,
    errors,
}: {
    base: string;
    m: TourAndVideo;
    errors: Record<string, string>;
}) {
    const [url, setUrl] = useState(m.tour?.url ?? '');
    const opts = { preserveScroll: true };

    return (
        <div className="grid gap-4">
            <div className="grid gap-2">
                <h3 className="font-medium">3D or 360-degree tour</h3>
                <p className="text-sm text-muted-foreground">
                    Paste the link from Matterport, iGUIDE, CubiCasa, Kuula,
                    Zillow 3D Home, YouTube or Vimeo. It plays on the listing
                    page and goes in your MLS package.
                </p>
                <form
                    className="flex flex-wrap gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post(`${base}/tour`, { url }, opts);
                    }}
                >
                    <Input
                        aria-label="Tour link"
                        className="min-w-64 grow"
                        placeholder="https://my.matterport.com/show/?m=…"
                        value={url}
                        onChange={(e) => setUrl(e.target.value)}
                    />
                    <Button>Save tour</Button>
                    {m.tour && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setUrl('');
                                router.post(`${base}/tour`, { url: '' }, opts);
                            }}
                        >
                            Remove
                        </Button>
                    )}
                </form>
                {m.matterport !== null && m.matterport.length > 0 && (
                    <label className="grid max-w-md gap-1 text-sm">
                        Or pick one of your Matterport spaces
                        <select
                            className="h-10 rounded-md border bg-background px-3"
                            defaultValue=""
                            onChange={(e) =>
                                e.target.value !== '' &&
                                router.post(
                                    `${base}/tour`,
                                    { matterport: e.target.value },
                                    opts,
                                )
                            }
                        >
                            <option value="">Choose a space…</option>
                            {m.matterport.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </select>
                    </label>
                )}
                {errors.tour && (
                    <p className="text-sm text-red-700">{errors.tour}</p>
                )}
                {m.tour?.embed && (
                    <div className="relative aspect-video max-w-xl overflow-hidden rounded-md border">
                        <iframe
                            src={m.tour.embed}
                            title={`${m.tour.provider} tour`}
                            className="absolute inset-0 size-full"
                            loading="lazy"
                            allow="fullscreen; xr-spatial-tracking"
                            allowFullScreen
                        />
                    </div>
                )}
                {m.tour && !m.tour.embed && (
                    <a
                        className="text-sm underline"
                        href={m.tour.url}
                        target="_blank"
                        rel="noreferrer"
                    >
                        Open the {m.tour.provider} tour
                    </a>
                )}
            </div>

            <div className="grid gap-2">
                <h3 className="font-medium">Listing video</h3>
                <p className="text-sm text-muted-foreground">
                    A vertical video for Reels, Shorts and TikTok from your
                    photos in order, with music. "Coming soon" until it's on the
                    MLS, then "Just listed". Staged photos keep their label.
                </p>
                {m.video.status === 'making' ? (
                    <p>Making your video. Refresh in a minute or two.</p>
                ) : (
                    <Button
                        className="justify-self-start"
                        disabled={m.video.photos < m.video.needs}
                        onClick={() => router.post(`${base}/video`, {}, opts)}
                    >
                        {m.video.status === 'ready'
                            ? 'Make it again'
                            : 'Make my listing video'}
                    </Button>
                )}
                {m.video.photos < m.video.needs && (
                    <p className="text-sm text-muted-foreground">
                        Add at least {m.video.needs} photos first.
                    </p>
                )}
                {m.video.status === 'failed' && m.video.error && (
                    <p className="text-sm text-red-700">{m.video.error}</p>
                )}
                {errors.video && (
                    <p className="text-sm text-red-700">{errors.video}</p>
                )}
                {m.video.status === 'ready' && m.video.url && (
                    <div className="grid gap-1">
                        <video
                            controls
                            playsInline
                            preload="none"
                            poster={m.video.poster ?? undefined}
                            className="w-full max-w-xs rounded-md border"
                            aria-label="Listing video"
                        >
                            <source src={m.video.url} type="video/mp4" />
                            <track
                                kind="captions"
                                src={m.video.captions ?? undefined}
                                srcLang="en"
                                label="English"
                                default
                            />
                        </video>
                        <a
                            className="text-sm underline"
                            href={m.video.url}
                            download
                        >
                            Download the video
                        </a>
                    </div>
                )}
            </div>
        </div>
    );
}
