import { Head, Link, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Service = {
    label: string;
    provider: string | null;
    mode: string;
    daily: { test: number; live: number };
};

type Props = {
    tags: Service & {
        photos: number;
        listings: number;
        latest: {
            thumb: string | null;
            room: string;
            features: string[];
            alt: string | null;
            mls_caption: string | null;
        }[];
    };
    places: Service & {
        listings: number;
        checked: number;
        latest: {
            listing: string;
            places: { name: string; type: string; miles: string }[];
        }[];
    };
};

const MODE: Record<string, string> = {
    off: 'Off',
    test: 'Test: results show only here',
    live: 'Live: results show on listing pages and search',
};

function Status({ s }: { s: Service }) {
    return (
        <p className="flex flex-wrap items-center gap-2">
            <strong>{s.provider ?? 'Not connected'}</strong>
            <Badge variant={s.mode === 'live' ? 'default' : 'outline'}>
                {MODE[s.mode] ?? s.mode}
            </Badge>
            <span className="text-muted-foreground">
                Up to {s.daily.test} a day in Test,{' '}
                {s.daily.live.toLocaleString()} in Live.
            </span>
        </p>
    );
}

/** C03: HQ → MLS → Photo tags and nearby places. Check what the providers return before switching to Live. */
export default function MlsPhotosPlaces({ tags, places }: Props) {
    const anyOn = tags.mode !== 'off' || places.mode !== 'off';

    return (
        <>
            <Head title="Photo tags and nearby places" />
            <div className="mx-auto grid w-full max-w-5xl gap-8 p-4 text-base md:p-6">
                <PageHeader
                    title="Photo tags and nearby places"
                    description="What the providers found in listing photos and around listings. Turn each on in Outside services, try Test first, check the results here, then switch to Live."
                />
                <div className="flex flex-wrap gap-2">
                    <Button
                        disabled={!anyOn}
                        onClick={() =>
                            router.post(
                                '/hq/mls/photos-places/run',
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Check 10 more now
                    </Button>
                    <Button variant="outline" onClick={() => router.reload()}>
                        Refresh
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href="/hq/services">Outside services</Link>
                    </Button>
                </div>

                <section className="grid gap-3">
                    <h2 className="text-lg font-semibold">Photo tagging</h2>
                    <Status s={tags} />
                    <p>
                        {tags.photos.toLocaleString()} photos tagged;{' '}
                        {tags.listings.toLocaleString()} listings show their
                        features. Only the home is described, never people.
                    </p>
                    {tags.latest.length === 0 ? (
                        <p className="text-muted-foreground">
                            No photos tagged yet.
                        </p>
                    ) : (
                        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {tags.latest.map((p, n) => (
                                <li
                                    key={n}
                                    className="grid gap-1 rounded-lg border p-2 text-sm"
                                >
                                    {p.thumb && (
                                        <img
                                            src={p.thumb}
                                            alt={p.alt ?? p.room}
                                            className="aspect-[4/3] w-full rounded object-cover"
                                            loading="lazy"
                                        />
                                    )}
                                    <p>
                                        <strong>{p.room}</strong>
                                        {p.features.length > 0 &&
                                            `: ${p.features.join(', ')}`}
                                    </p>
                                    <p className="text-muted-foreground">
                                        Alt text: {p.alt ?? 'none'}
                                        {p.mls_caption &&
                                            ` (the MLS caption "${p.mls_caption}" is used instead)`}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="grid gap-3">
                    <h2 className="text-lg font-semibold">
                        Nearby schools and transit
                    </h2>
                    <Status s={places} />
                    <p>
                        {places.checked.toLocaleString()} listings looked up;{' '}
                        {places.listings.toLocaleString()} show what's nearby.
                        Straight-line distances; no school ratings.
                    </p>
                    {places.latest.length === 0 ? (
                        <p className="text-muted-foreground">
                            No listings looked up yet.
                        </p>
                    ) : (
                        <ul className="grid gap-3 md:grid-cols-2">
                            {places.latest.map((l) => (
                                <li
                                    key={l.listing}
                                    className="grid gap-1 rounded-lg border p-3 text-sm"
                                >
                                    <strong>{l.listing}</strong>
                                    {l.places.length === 0 ? (
                                        <span className="text-muted-foreground">
                                            Nothing found nearby.
                                        </span>
                                    ) : (
                                        <ul>
                                            {l.places.map((p) => (
                                                <li key={p.type + p.name}>
                                                    {p.name} ({p.type}):{' '}
                                                    {p.miles}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}
