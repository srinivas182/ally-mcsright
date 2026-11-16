import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type DesignRow = {
    ulid: string;
    status: 'rendering' | 'ready' | 'failed';
    collection: string;
    variant: string;
    moment: string;
    checks: string[];
    used: boolean;
    images: Record<'square' | 'portrait' | 'story', string | null>;
    carousel: string[];
    reelable: boolean;
    listing: string | null;
};

const select = 'h-12 rounded-md border bg-background px-3';
const listingMoments = [
    'just_listed',
    'open_house',
    'price_improved',
    'coming_soon',
    'under_contract',
    'just_sold',
];

/**
 * Ally Studio → Create (specs/grow/13, SA1b): choose a moment, get three designs in your brand (checked by the art
 * director), then download one or post it.
 */
export default function StudioCreate({
    ready,
    moments,
    styles,
    style,
    listings,
    batch,
    recent,
}: {
    ready: boolean;
    moments: Record<string, string>;
    styles: { key: string; name: string }[];
    style: string;
    listings: { ulid: string; label: string }[];
    batch: DesignRow[];
    recent: DesignRow[];
}) {
    const form = useForm({
        moment: 'just_listed',
        style,
        listing: listings[0]?.ulid ?? '',
        headline: '',
        sub: '',
        body: '',
        by: '',
        when: '',
        stats: [
            { value: '', label: '' },
            { value: '', label: '' },
            { value: '', label: '' },
        ],
    });
    const errors = form.errors as Record<string, string>;
    const forListing = listingMoments.includes(form.data.moment);
    const rendering = batch.some((d) => d.status === 'rendering');

    useEffect(() => {
        if (!rendering) {
            return;
        }
        const t = setInterval(
            () => router.reload({ only: ['batch', 'recent'] }),
            2500,
        );

        return () => clearInterval(t);
    }, [rendering]);

    if (!ready) {
        return (
            <div className="p-4">
                <Head title="Create with Ally Studio" />
                <EmptyState
                    title="Ally Studio is an add-on"
                    description="Choose a Studio package to make designed posts in your brand."
                />
                <div className="mt-4">
                    <Button asChild>
                        <Link href="/studio">See the packages</Link>
                    </Button>
                </div>
            </div>
        );
    }

    const card = (d: DesignRow) => (
        <div
            key={d.ulid}
            className="grid content-start gap-2 rounded-xl border p-2"
        >
            {d.status === 'ready' && d.images.portrait ? (
                <img
                    src={d.images.portrait}
                    alt={`${d.moment} design, ${d.collection}`}
                    className="aspect-[4/5] w-full rounded-lg object-cover"
                />
            ) : (
                <div className="grid aspect-[4/5] place-items-center rounded-lg bg-muted p-4 text-center text-sm">
                    {d.status === 'failed'
                        ? (d.checks[0] ?? "This design didn't pass the checks.")
                        : 'Designing…'}
                </div>
            )}
            {d.status === 'ready' && d.carousel.length > 1 && (
                <div
                    className="flex snap-x gap-1 overflow-x-auto pb-1"
                    aria-label="Carousel slides"
                >
                    {d.carousel.map((src, i) => (
                        <img
                            key={src}
                            src={src}
                            alt={`Slide ${i + 1}`}
                            loading="lazy"
                            className="aspect-[4/5] w-14 flex-none snap-start rounded object-cover"
                        />
                    ))}
                </div>
            )}
            <div className="flex items-center justify-between text-sm">
                <span>{d.collection}</span>
                {d.used && <Badge variant="outline">used</Badge>}
            </div>
            {d.status === 'ready' && (
                <div className="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        onClick={() =>
                            router.post(`/studio/designs/${d.ulid}/post`)
                        }
                    >
                        Post it
                    </Button>
                    {d.listing && (
                        <Button size="sm" variant="outline" asChild>
                            <Link
                                href={`/ads/new?listing=${d.listing}&design=${d.ulid}`}
                            >
                                Advertise
                            </Link>
                        </Button>
                    )}
                    {d.reelable && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(`/studio/designs/${d.ulid}/reel`)
                            }
                        >
                            Make a Reel
                        </Button>
                    )}
                    {d.carousel.length > 1 && (
                        <Button size="sm" variant="outline" asChild>
                            <a
                                href={`/studio/designs/${d.ulid}/download?size=carousel`}
                            >
                                Carousel ({d.carousel.length})
                            </a>
                        </Button>
                    )}
                    {(['portrait', 'square', 'story'] as const).map((s) => (
                        <Button key={s} size="sm" variant="outline" asChild>
                            <a
                                href={`/studio/designs/${d.ulid}/download?size=${s}`}
                            >
                                {s === 'portrait'
                                    ? '4:5'
                                    : s === 'square'
                                      ? '1:1'
                                      : '9:16'}
                            </a>
                        </Button>
                    ))}
                </div>
            )}
        </div>
    );

    return (
        <>
            <Head title="Create with Ally Studio" />
            <div className="grid max-w-5xl gap-6 p-4 text-base">
                <PageHeader
                    title="Create"
                    description="Three designs in your brand: for a listing, each is a full carousel (cover, photos, details) plus square and story sizes. Making them is free; one counts when you download or post it."
                />

                <form
                    className="grid gap-3 rounded-lg border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/studio/designs', { preserveScroll: true });
                    }}
                >
                    <div className="grid gap-3 sm:grid-cols-3">
                        <label className="grid gap-1">
                            Style
                            <select
                                className={select}
                                value={form.data.style}
                                onChange={(e) =>
                                    form.setData('style', e.target.value)
                                }
                            >
                                {styles.map((s) => (
                                    <option key={s.key} value={s.key}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1">
                            What is it about?
                            <select
                                className={select}
                                value={form.data.moment}
                                onChange={(e) =>
                                    form.setData('moment', e.target.value)
                                }
                            >
                                {Object.entries(moments).map(([k, l]) => (
                                    <option key={k} value={k}>
                                        {l}
                                    </option>
                                ))}
                            </select>
                            {errors.moment && (
                                <span className="text-destructive">
                                    {errors.moment}
                                </span>
                            )}
                        </label>
                        {forListing && (
                            <label className="grid gap-1">
                                Listing
                                <select
                                    className={select}
                                    value={form.data.listing}
                                    onChange={(e) =>
                                        form.setData('listing', e.target.value)
                                    }
                                >
                                    {listings.map((l) => (
                                        <option key={l.ulid} value={l.ulid}>
                                            {l.label}
                                        </option>
                                    ))}
                                </select>
                                {errors.listing && (
                                    <span className="text-destructive">
                                        {errors.listing}
                                    </span>
                                )}
                            </label>
                        )}
                    </div>
                    {form.data.moment === 'open_house' && (
                        <label className="grid gap-1">
                            When
                            <Input
                                placeholder="Sunday, June 7 · 1–3 PM"
                                value={form.data.when}
                                onChange={(e) =>
                                    form.setData('when', e.target.value)
                                }
                            />
                            {errors.when && (
                                <span className="text-destructive">
                                    {errors.when}
                                </span>
                            )}
                        </label>
                    )}
                    <label className="grid gap-1">
                        Headline
                        {forListing ? ' (optional: the address is used)' : ''}
                        <Input
                            maxLength={60}
                            value={form.data.headline}
                            onChange={(e) =>
                                form.setData('headline', e.target.value)
                            }
                        />
                        {errors.headline && (
                            <span className="text-destructive">
                                {errors.headline}
                            </span>
                        )}
                    </label>
                    {!forListing && (
                        <label className="grid gap-1">
                            {form.data.moment === 'testimonial'
                                ? 'What the client said'
                                : 'A few words (optional)'}
                            <textarea
                                className="min-h-24 rounded-md border bg-background p-3"
                                maxLength={320}
                                value={form.data.body}
                                onChange={(e) =>
                                    form.setData('body', e.target.value)
                                }
                            />
                        </label>
                    )}
                    {form.data.moment === 'testimonial' && (
                        <label className="grid gap-1">
                            Client's first name (with their permission)
                            <Input
                                maxLength={40}
                                value={form.data.by}
                                onChange={(e) =>
                                    form.setData('by', e.target.value)
                                }
                            />
                        </label>
                    )}
                    {form.data.moment === 'market_update' && (
                        <fieldset className="grid gap-2 sm:grid-cols-3">
                            <legend className="mb-1">Numbers (optional)</legend>
                            {form.data.stats.map((s, i) => (
                                <div key={i} className="grid gap-1">
                                    <Input
                                        placeholder={['42', '$485K', '18'][i]}
                                        value={s.value}
                                        onChange={(e) =>
                                            form.setData(
                                                'stats',
                                                form.data.stats.map((x, j) =>
                                                    j === i
                                                        ? {
                                                              ...x,
                                                              value: e.target
                                                                  .value,
                                                          }
                                                        : x,
                                                ),
                                            )
                                        }
                                    />
                                    <Input
                                        placeholder={
                                            [
                                                'New listings',
                                                'Median price',
                                                'Days on market',
                                            ][i]
                                        }
                                        value={s.label}
                                        onChange={(e) =>
                                            form.setData(
                                                'stats',
                                                form.data.stats.map((x, j) =>
                                                    j === i
                                                        ? {
                                                              ...x,
                                                              label: e.target
                                                                  .value,
                                                          }
                                                        : x,
                                                ),
                                            )
                                        }
                                    />
                                </div>
                            ))}
                        </fieldset>
                    )}
                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={form.processing}>
                            Make three designs
                        </Button>
                        <Link
                            href="/studio/styles"
                            className="text-sm underline"
                        >
                            See every style
                        </Link>
                        <Link
                            href="/studio/brand"
                            className="text-sm underline"
                        >
                            Brand kit
                        </Link>
                    </div>
                </form>

                {batch.length > 0 && (
                    <section className="grid gap-3">
                        <h2 className="font-medium">Your designs</h2>
                        <div className="grid gap-4 sm:grid-cols-3">
                            {batch.map(card)}
                        </div>
                    </section>
                )}

                {recent.length > 0 && batch.length === 0 && (
                    <section className="grid gap-3">
                        <h2 className="font-medium">Recent designs</h2>
                        <div className="grid gap-4 sm:grid-cols-3">
                            {recent.map(card)}
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}
