import { Head, Link, router, usePoll } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';

type Version = { status: string; url: string | null } | null;

type Photo = {
    id: number;
    original: string | null;
    enhanced: Version;
    twilight: Version;
};

const select = 'h-12 rounded-md border bg-background px-3';

/**
 * Studio → Enhance photos (specs/grow/13, SA5a): pick a listing's photos, enhance them with AI (light, colour,
 * sky, lawn) and add a virtual twilight of the cover. The MLS originals never change; designs made with enhanced
 * photos say "Photos virtually enhanced".
 */
export default function StudioPhotos({
    ready,
    on,
    listings,
    listing,
    photos,
    usage,
    credits_each,
    max,
}: {
    ready: boolean;
    on: boolean;
    listings: { ulid: string; label: string }[];
    listing: string | null;
    photos: Photo[];
    usage: { used: number; extra: number; allowance: number };
    credits_each: number;
    max: number;
}) {
    const [chosen, setChosen] = useState<number[]>([]);
    const [twilight, setTwilight] = useState(true);
    const [busy, setBusy] = useState(false);
    const working = photos.some((p) =>
        [p.enhanced?.status, p.twilight?.status].some(
            (s) => s === 'queued' || s === 'processing',
        ),
    );
    usePoll(15_000, { only: ['photos', 'usage'] }, { autoStart: working });
    const left = Math.max(0, usage.allowance - usage.used);

    if (!ready) {
        return (
            <div className="p-4">
                <Head title="Enhance photos" />
                <EmptyState
                    title="Photo enhancement comes with Ally Studio"
                    description="Choose a Studio package to enhance your listing photos."
                />
                <div className="mt-4">
                    <Button asChild>
                        <Link href="/studio">See the packages</Link>
                    </Button>
                </div>
            </div>
        );
    }

    const pick = (id: number, yes: boolean) =>
        setChosen((c) =>
            yes ? [...c, id].slice(0, max) : c.filter((x) => x !== id),
        );
    const label = (v: Version) =>
        v === null
            ? null
            : v.status === 'ready'
              ? null
              : v.status === 'failed'
                ? 'didn’t work: try again'
                : 'enhancing…';

    return (
        <>
            <Head title="Enhance photos" />
            <div className="grid max-w-5xl gap-6 p-4 text-base">
                <PageHeader
                    title="Enhance photos"
                    description="AI brightens and balances your listing photos, straightens lines, brings out a blue sky and a green lawn, and can turn the cover into a twilight shot. Your MLS photos never change."
                />
                {!on && (
                    <p className="rounded-lg border p-3 text-sm">
                        Photo enhancement isn&apos;t switched on yet. RightAlly
                        is setting it up.
                    </p>
                )}
                <div className="flex flex-wrap items-center gap-3">
                    <select
                        className={select}
                        aria-label="Listing"
                        value={listing ?? ''}
                        onChange={(e) => {
                            setChosen([]);
                            router.get(
                                '/studio/photos',
                                { listing: e.target.value },
                                { preserveState: false },
                            );
                        }}
                    >
                        {listings.map((l) => (
                            <option key={l.ulid} value={l.ulid}>
                                {l.label}
                            </option>
                        ))}
                    </select>
                    <span className="text-sm text-muted-foreground">
                        {left} of {usage.allowance} enhancements left this
                        month; then {credits_each} credits each. A twilight
                        counts as one.
                    </span>
                </div>
                {listings.length === 0 ? (
                    <EmptyState
                        title="No listings yet"
                        description="Your listings from the MLS appear here."
                    />
                ) : (
                    <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {photos.map((p) => {
                            const after = p.twilight?.url ?? p.enhanced?.url;
                            const note = label(p.twilight) ?? label(p.enhanced);

                            return (
                                <li
                                    key={p.id}
                                    className="grid gap-2 rounded-xl border p-2"
                                >
                                    <div className="grid grid-cols-2 gap-1">
                                        {p.original && (
                                            <img
                                                src={p.original}
                                                alt="MLS photo"
                                                loading="lazy"
                                                className="aspect-[4/3] w-full rounded object-cover"
                                            />
                                        )}
                                        {after ? (
                                            <img
                                                src={after}
                                                alt="Enhanced photo"
                                                loading="lazy"
                                                className="aspect-[4/3] w-full rounded object-cover"
                                            />
                                        ) : (
                                            <div className="grid aspect-[4/3] place-items-center rounded bg-muted p-2 text-center text-xs">
                                                {note ?? 'Not enhanced yet'}
                                            </div>
                                        )}
                                    </div>
                                    <div className="flex items-center justify-between gap-2 text-sm">
                                        <label className="flex items-center gap-2">
                                            <input
                                                type="checkbox"
                                                className="size-5"
                                                disabled={
                                                    !on ||
                                                    p.enhanced?.status ===
                                                        'ready'
                                                }
                                                checked={chosen.includes(p.id)}
                                                onChange={(e) =>
                                                    pick(p.id, e.target.checked)
                                                }
                                            />
                                            Enhance
                                        </label>
                                        {p.twilight?.status === 'ready' ? (
                                            <Badge variant="outline">
                                                twilight
                                            </Badge>
                                        ) : p.enhanced?.status === 'ready' ? (
                                            <Badge variant="outline">
                                                enhanced
                                            </Badge>
                                        ) : null}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
                <div className="flex flex-wrap items-center gap-4">
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            className="size-5"
                            checked={twilight}
                            onChange={(e) => setTwilight(e.target.checked)}
                        />
                        Also make a twilight of the first chosen photo
                    </label>
                    <Button
                        size="lg"
                        disabled={!on || busy || chosen.length === 0}
                        onClick={() =>
                            router.post(
                                '/studio/photos',
                                { listing, photos: chosen, twilight },
                                {
                                    onStart: () => setBusy(true),
                                    onFinish: () => {
                                        setBusy(false);
                                        setChosen([]);
                                    },
                                },
                            )
                        }
                    >
                        Enhance {chosen.length || ''}{' '}
                        {chosen.length === 1 ? 'photo' : 'photos'}
                    </Button>
                </div>
                <p className="text-sm text-muted-foreground">
                    Designs and posts made with enhanced photos say “Photos
                    virtually enhanced”, as MLS and advertising rules expect.
                </p>
            </div>
        </>
    );
}
