import { Head, Link, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Place = {
    key: string;
    channel: 'facebook' | 'instagram' | 'linkedin' | 'google';
    name: string;
    brokerage: boolean;
};

type Post = {
    ulid: string;
    text: string;
    status: string;
    post_at: string;
    cancellable: boolean;
    targets: {
        channel: string;
        name: string;
        status: string;
        url: string | null;
        error: string | null;
    }[];
};

const networks: Record<string, string> = {
    facebook: 'Facebook',
    instagram: 'Instagram',
    linkedin: 'LinkedIn',
    google: 'Google',
};

const select = 'h-12 rounded-md border bg-background px-3';

/**
 * Grow → Social posts (specs/connect/19, C7d-1): write once, choose where (Facebook Pages, Instagram, LinkedIn,
 * Google Business Profile), post now or schedule, and see each place's result.
 */
export default function Social({
    apps,
    places,
    listings,
    start,
    timezone,
    posts,
}: {
    apps: { key: string; name: string; ready: boolean; connected: boolean }[];
    places: Place[];
    listings: { ulid: string; label: string }[];
    start: {
        listing: string;
        text: string;
        link: string;
        image: string | null;
        images?: string[];
        video?: string | null;
        studio_design?: string;
        studio_video?: string;
    } | null;
    timezone: string;
    posts: Post[];
}) {
    const form = useForm({
        listing: start?.listing ?? '',
        text: start?.text ?? '',
        link: start?.link ?? '',
        image_url: start?.image ?? '',
        images: start?.images ?? ([] as string[]),
        video_url: start?.video ?? '',
        studio_design: start?.studio_design ?? '',
        studio_video: start?.studio_video ?? '',
        places: places.map((p) => p.key),
        post_at: '',
    });
    const error = (k: keyof typeof form.data) =>
        form.errors[k] && (
            <span className="text-destructive">{form.errors[k]}</span>
        );
    const toConnect = apps.filter((a) => a.ready && !a.connected);

    return (
        <>
            <Head title="Social posts" />
            <div className="grid max-w-3xl gap-6 p-4 text-base">
                <PageHeader
                    title="Social posts"
                    description="Write once and post to your Facebook Pages, Instagram, LinkedIn and Google Business Profile, now or later."
                />

                {places.length === 0 ? (
                    <EmptyState
                        title="Connect where you post"
                        description={
                            toConnect.length > 0
                                ? 'Connect Facebook Pages and Instagram, LinkedIn or Google Business Profile to start.'
                                : "Social posting isn't switched on yet. RightAlly support switches it on for your brokerage."
                        }
                    />
                ) : (
                    <form
                        className="grid gap-3 rounded-lg border p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/social', {
                                preserveScroll: true,
                                onSuccess: () => form.reset('text', 'post_at'),
                            });
                        }}
                    >
                        {listings.length > 0 && (
                            <label className="grid gap-1">
                                Start from a listing (optional)
                                <select
                                    className={select}
                                    value={form.data.listing}
                                    onChange={(e) =>
                                        e.target.value === ''
                                            ? form.setData('listing', '')
                                            : router.get(
                                                  '/social',
                                                  { listing: e.target.value },
                                                  { preserveScroll: true },
                                              )
                                    }
                                >
                                    <option value="">No listing</option>
                                    {listings.map((l) => (
                                        <option key={l.ulid} value={l.ulid}>
                                            {l.label}
                                        </option>
                                    ))}
                                </select>
                                {error('listing')}
                            </label>
                        )}
                        <label className="grid gap-1">
                            Post
                            <textarea
                                className="min-h-32 rounded-md border bg-background p-3"
                                maxLength={2800}
                                value={form.data.text}
                                onChange={(e) =>
                                    form.setData('text', e.target.value)
                                }
                            />
                            <span className="text-sm text-muted-foreground">
                                Your name and brokerage are added at the end.
                            </span>
                            {error('text')}
                        </label>
                        <label className="grid gap-1">
                            Link (optional)
                            <Input
                                type="url"
                                maxLength={500}
                                value={form.data.link}
                                onChange={(e) =>
                                    form.setData('link', e.target.value)
                                }
                            />
                            {error('link')}
                        </label>
                        <label className="grid gap-1">
                            Photo address (optional; Instagram needs a .jpg)
                            <Input
                                type="url"
                                maxLength={500}
                                value={form.data.image_url}
                                onChange={(e) =>
                                    form.setData('image_url', e.target.value)
                                }
                            />
                            {error('image_url')}
                        </label>
                        {form.data.video_url !== '' && (
                            <div className="grid gap-2">
                                <div className="flex items-center justify-between text-sm">
                                    <span>
                                        Reel: Instagram and Facebook get the
                                        video; LinkedIn and Google get the cover
                                    </span>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            form.setData('video_url', '')
                                        }
                                    >
                                        Post the cover only
                                    </Button>
                                </div>
                                <video
                                    src={form.data.video_url}
                                    controls
                                    playsInline
                                    className="max-h-80 w-fit rounded border"
                                />
                                {error('video_url')}
                            </div>
                        )}
                        {form.data.images.length >= 2 && (
                            <div className="grid gap-2">
                                <div className="flex items-center justify-between text-sm">
                                    <span>
                                        Carousel: {form.data.images.length}{' '}
                                        images, in this order
                                    </span>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            form.setData('images', [])
                                        }
                                    >
                                        Post the cover only
                                    </Button>
                                </div>
                                <div className="flex gap-1 overflow-x-auto pb-1">
                                    {form.data.images.map((src, i) => (
                                        <img
                                            key={src}
                                            src={src}
                                            alt={`Carousel image ${i + 1}`}
                                            loading="lazy"
                                            className="aspect-[4/5] w-16 flex-none rounded object-cover"
                                        />
                                    ))}
                                </div>
                                {error('images')}
                            </div>
                        )}
                        {form.data.images.length < 2 &&
                            form.data.image_url.startsWith('https://') && (
                                <img
                                    src={form.data.image_url}
                                    alt="The photo for this post"
                                    className="max-h-48 w-fit rounded border"
                                />
                            )}
                        <fieldset className="grid gap-2">
                            <legend className="mb-1">Where</legend>
                            {places.map((p) => (
                                <label
                                    key={p.key}
                                    className="flex items-center gap-2"
                                >
                                    <input
                                        type="checkbox"
                                        className="size-5"
                                        checked={form.data.places.includes(
                                            p.key,
                                        )}
                                        onChange={(e) =>
                                            form.setData(
                                                'places',
                                                e.target.checked
                                                    ? [
                                                          ...form.data.places,
                                                          p.key,
                                                      ]
                                                    : form.data.places.filter(
                                                          (k) => k !== p.key,
                                                      ),
                                            )
                                        }
                                    />
                                    <span>
                                        {networks[p.channel]}: {p.name}
                                        {p.brokerage && (
                                            <Badge
                                                variant="outline"
                                                className="ml-2"
                                            >
                                                brokerage
                                            </Badge>
                                        )}
                                    </span>
                                </label>
                            ))}
                            {error('places')}
                        </fieldset>
                        <label className="grid gap-1">
                            When (leave empty to post now; {timezone})
                            <Input
                                type="datetime-local"
                                value={form.data.post_at}
                                onChange={(e) =>
                                    form.setData('post_at', e.target.value)
                                }
                            />
                            {error('post_at')}
                        </label>
                        <p className="text-sm text-muted-foreground">
                            Describe the home and the area, not who should live
                            there: posts are checked for Fair Housing.
                        </p>
                        <div>
                            <Button type="submit" disabled={form.processing}>
                                {form.data.post_at === ''
                                    ? 'Post now'
                                    : 'Schedule'}
                            </Button>
                        </div>
                    </form>
                )}

                {toConnect.length > 0 && (
                    <p className="text-sm">
                        Also connect:{' '}
                        {toConnect.map((a, i) => (
                            <span key={a.key}>
                                {i > 0 && ' · '}
                                <Link
                                    href={`/apps/${a.key}`}
                                    className="underline"
                                >
                                    {a.name}
                                </Link>
                            </span>
                        ))}
                    </p>
                )}

                {posts.length > 0 && (
                    <section className="grid gap-2">
                        <h2 className="font-medium">Your posts</h2>
                        <ul className="grid gap-2">
                            {posts.map((p) => (
                                <li
                                    key={p.ulid}
                                    className="grid gap-2 rounded-lg border p-3 text-sm"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span>
                                            {p.post_at}{' '}
                                            <Badge variant="outline">
                                                {p.status}
                                            </Badge>
                                        </span>
                                        {p.cancellable && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        `/social/${p.ulid}/cancel`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Cancel
                                            </Button>
                                        )}
                                    </div>
                                    <p className="whitespace-pre-wrap">
                                        {p.text}
                                    </p>
                                    <ul className="grid gap-1 text-muted-foreground">
                                        {p.targets.map((t) => (
                                            <li key={t.channel + t.name}>
                                                {networks[t.channel] ??
                                                    t.channel}
                                                : {t.name} ·{' '}
                                                {t.url ? (
                                                    <a
                                                        href={t.url}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="underline"
                                                    >
                                                        {t.status}
                                                    </a>
                                                ) : (
                                                    t.status
                                                )}
                                                {t.error && (
                                                    <span className="text-destructive">
                                                        {' '}
                                                        {t.error}
                                                    </span>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}
