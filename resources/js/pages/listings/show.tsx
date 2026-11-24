import { Head, Link, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Bath,
    BedDouble,
    Home,
    Ruler,
    Send,
    Video,
} from 'lucide-react';
import { useEffect, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { money } from '@/pages/listings/index';

type Listing = {
    ulid: string;
    mls_number: string | null;
    status: string;
    price: number | null;
    original_price: number | null;
    beds: number | null;
    baths_full: number | null;
    baths_half: number | null;
    sqft: number | null;
    lot_sqft: number | null;
    year_built: number | null;
    property_type: string | null;
    property_sub_type: string | null;
    address: string | null;
    city: string | null;
    state: string | null;
    postal_code: string | null;
    subdivision: string | null;
    remarks: string | null;
    days_on_market: number | null;
    virtual_tour: string | null;
    attribution: string;
    disclaimer: string | null;
    logo: string | null;
    stale: boolean;
    listed: string | null;
    photos: {
        url: string | null;
        hero: string | null;
        thumb: string | null;
        caption: string | null;
        alt?: string | null;
    }[];
    price_history: { date: string; from: number | null; to: number }[];
    features: string[];
    nearby: Nearby | null;
};

type Place = { name: string; type: string; miles: string };
type Nearby = {
    schools: Place[];
    transit: Place[];
    shops: Place[];
    credit: string;
    summary: string;
};

/** One listing with photos, facts, price history and the MLS's attribution and disclaimer. */
export default function ListingShow({ listing: l }: { listing: Listing }) {
    const [i, setI] = useState(0);
    const photo = l.photos[i];
    const facts: [string, string | number | null][] = [
        ['Beds', l.beds],
        [
            'Baths',
            l.baths_full !== null
                ? `${l.baths_full}${l.baths_half ? ` full, ${l.baths_half} half` : ''}`
                : null,
        ],
        ['Living area', l.sqft ? `${l.sqft.toLocaleString()} sq ft` : null],
        ['Lot', l.lot_sqft ? `${l.lot_sqft.toLocaleString()} sq ft` : null],
        ['Year built', l.year_built],
        [
            'Type',
            [l.property_type, l.property_sub_type]
                .filter(Boolean)
                .join(' · ') || null,
        ],
        ['Subdivision', l.subdivision],
        ['Days on market', l.days_on_market],
        ['MLS #', l.mls_number],
    ];

    const reduced =
        l.original_price !== null &&
        l.price !== null &&
        l.price < l.original_price;
    const place = [l.city, l.state, l.postal_code].filter(Boolean).join(', ');

    return (
        <>
            <Head title={l.address ?? 'Listing'} />
            <div className="mx-auto grid w-full max-w-7xl gap-5 p-4 md:p-6">
                <Link
                    href="/listings"
                    className="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft aria-hidden="true" className="size-4" /> Back to
                    MLS search
                </Link>
                {l.stale && (
                    <p
                        role="status"
                        className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100"
                    >
                        MLS data is updating; details may be out of date.
                    </p>
                )}
                {photo ? (
                    <figure className="grid gap-2">
                        <div className="relative overflow-hidden rounded-xl">
                            <img
                                src={photo.hero ?? photo.url ?? ''}
                                alt={
                                    photo.alt ??
                                    photo.caption ??
                                    l.address ??
                                    'Home photo'
                                }
                                className="aspect-[16/9] max-h-[34rem] w-full object-cover"
                            />
                            <span className="absolute right-3 bottom-3 rounded-full bg-black/60 px-3 py-1 text-xs font-medium text-white">
                                {i + 1} of {l.photos.length}
                            </span>
                        </div>
                        <div
                            className="flex gap-2 overflow-x-auto pb-1"
                            role="list"
                        >
                            {l.photos.map((p, n) => (
                                <button
                                    key={n}
                                    type="button"
                                    onClick={() => setI(n)}
                                    aria-label={`Photo ${n + 1}`}
                                    aria-current={n === i}
                                    className={cn(
                                        'shrink-0 overflow-hidden rounded-md opacity-80 hover:opacity-100',
                                        n === i &&
                                            'opacity-100 ring-2 ring-primary',
                                    )}
                                >
                                    <img
                                        src={p.thumb ?? ''}
                                        alt=""
                                        className="h-16 w-24 object-cover"
                                        loading="lazy"
                                    />
                                </button>
                            ))}
                        </div>
                    </figure>
                ) : (
                    <div className="grid aspect-[16/6] place-items-center rounded-xl bg-gradient-to-br from-slate-100 to-slate-200 text-slate-400 dark:from-slate-800 dark:to-slate-900 dark:text-slate-500">
                        <span className="grid justify-items-center gap-1">
                            <Home aria-hidden="true" className="size-12" />
                            Photos coming
                        </span>
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-[1fr_22rem]">
                    <div className="grid content-start gap-6">
                        <header className="grid gap-2">
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge
                                    className={cn(
                                        l.status === 'Active'
                                            ? 'bg-emerald-600 hover:bg-emerald-600'
                                            : 'bg-amber-500 hover:bg-amber-500',
                                        'text-white',
                                    )}
                                >
                                    {l.status}
                                </Badge>
                                {reduced && (
                                    <Badge className="bg-rose-600 text-white hover:bg-rose-600">
                                        Price cut from {money(l.original_price)}
                                    </Badge>
                                )}
                                {l.days_on_market !== null && (
                                    <span className="text-sm text-muted-foreground">
                                        {l.days_on_market} days on market
                                    </span>
                                )}
                            </div>
                            <h1 className="text-3xl font-semibold tracking-tight tabular-nums md:text-4xl">
                                {money(l.price)}
                            </h1>
                            <p className="text-lg">
                                <span className="font-medium">
                                    {l.address ?? 'Address withheld'}
                                </span>
                                {place && (
                                    <span className="text-muted-foreground">
                                        , {place}
                                    </span>
                                )}
                            </p>
                            <div className="flex flex-wrap gap-x-6 gap-y-2 text-base [&_svg]:size-5 [&_svg]:text-muted-foreground">
                                <span className="inline-flex items-center gap-2">
                                    <BedDouble aria-hidden="true" />
                                    <strong>{l.beds ?? '—'}</strong> beds
                                </span>
                                <span className="inline-flex items-center gap-2">
                                    <Bath aria-hidden="true" />
                                    <strong>
                                        {l.baths_full !== null
                                            ? l.baths_full +
                                              (l.baths_half ? 0.5 : 0)
                                            : '—'}
                                    </strong>{' '}
                                    baths
                                </span>
                                <span className="inline-flex items-center gap-2">
                                    <Ruler aria-hidden="true" />
                                    <strong>
                                        {l.sqft ? l.sqft.toLocaleString() : '—'}
                                    </strong>{' '}
                                    sq ft
                                </span>
                            </div>
                        </header>

                        {l.remarks && (
                            <section className="grid gap-2">
                                <h2 className="text-lg font-semibold">
                                    About this home
                                </h2>
                                <p className="leading-relaxed whitespace-pre-line">
                                    {l.remarks}
                                </p>
                            </section>
                        )}

                        <section className="grid gap-2">
                            <h2 className="text-lg font-semibold">
                                Home facts
                            </h2>
                            <dl className="grid grid-cols-2 gap-px overflow-hidden rounded-xl border bg-border text-sm sm:grid-cols-3">
                                {facts
                                    .filter(([, v]) => v !== null && v !== '')
                                    .map(([k, v]) => (
                                        <div
                                            key={k}
                                            className="grid gap-0.5 bg-card p-3"
                                        >
                                            <dt className="text-muted-foreground">
                                                {k}
                                            </dt>
                                            <dd className="font-medium">{v}</dd>
                                        </div>
                                    ))}
                            </dl>
                        </section>

                        {l.features.length > 0 && (
                            <section className="grid gap-2 text-sm">
                                <h2 className="text-lg font-semibold">
                                    Seen in the photos
                                </h2>
                                <ul className="flex flex-wrap gap-2">
                                    {l.features.map((f) => (
                                        <li key={f}>
                                            <Badge variant="outline">{f}</Badge>
                                        </li>
                                    ))}
                                </ul>
                                <p className="text-muted-foreground">
                                    Spotted in the listing photos by AI. Check
                                    before you promise a feature.
                                </p>
                            </section>
                        )}
                        {l.nearby && <NearbyPlaces nearby={l.nearby} />}
                        {l.price_history.length > 0 && (
                            <section className="grid gap-2 text-sm">
                                <h2 className="text-lg font-semibold">
                                    Price history
                                </h2>
                                <table className="w-full overflow-hidden rounded-xl border text-left">
                                    <thead className="bg-muted/50 text-muted-foreground">
                                        <tr>
                                            <th className="px-3 py-2 font-medium">
                                                Date
                                            </th>
                                            <th className="px-3 py-2 font-medium">
                                                Change
                                            </th>
                                            <th className="px-3 py-2 text-right font-medium">
                                                Price
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {l.price_history.map((h, n) => (
                                            <tr key={n} className="border-t">
                                                <td className="px-3 py-2">
                                                    {h.date}
                                                </td>
                                                <td className="px-3 py-2">
                                                    {h.from === null
                                                        ? 'Listed'
                                                        : h.to < h.from
                                                          ? `Down ${money(h.from - h.to)}`
                                                          : `Up ${money(h.to - h.from)}`}
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    {money(h.to)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </section>
                        )}
                        <footer className="grid gap-2 border-t pt-3 text-xs text-muted-foreground">
                            {l.logo && (
                                <img
                                    src={l.logo}
                                    alt="MLS"
                                    className="h-8 w-auto"
                                />
                            )}
                            <p>{l.attribution}</p>
                            {l.disclaimer && <p>{l.disclaimer}</p>}
                        </footer>
                    </div>

                    <aside className="grid content-start gap-4 lg:sticky lg:top-20 lg:self-start">
                        <SendToClient listing={l.ulid} />
                        {l.virtual_tour && (
                            <Button variant="outline" asChild>
                                <a
                                    href={l.virtual_tour}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <Video aria-hidden="true" /> Virtual tour
                                </a>
                            </Button>
                        )}
                        {l.mls_number && (
                            <p className="text-center text-sm text-muted-foreground">
                                MLS {l.mls_number}
                                {l.listed && ` · Listed ${l.listed}`}
                            </p>
                        )}
                    </aside>
                </div>
            </div>
        </>
    );
}

/** C03: nearby schools and transit, straight-line, never rated. */
function NearbyPlaces({ nearby }: { nearby: Nearby }) {
    return (
        <section className="grid gap-2 text-sm">
            <h2 className="text-lg font-semibold">What's nearby</h2>
            <p>{nearby.summary}</p>
            {(
                [
                    ['Schools', nearby.schools],
                    ['Transit', nearby.transit],
                    ['Groceries and coffee', nearby.shops],
                ] as const
            ).map(
                ([heading, places]) =>
                    places.length > 0 && (
                        <div key={heading} className="grid gap-1">
                            <h3 className="font-medium">{heading}</h3>
                            <ul className="divide-y rounded-md border">
                                {places.map((p) => (
                                    <li
                                        key={p.name}
                                        className="flex flex-wrap justify-between gap-2 p-2"
                                    >
                                        <span>
                                            {p.name}{' '}
                                            <span className="text-muted-foreground">
                                                ({p.type})
                                            </span>
                                        </span>
                                        <span>{p.miles}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ),
            )}
            <p className="text-muted-foreground">
                Straight-line distances. School attendance depends on the school
                district. {nearby.credit}
            </p>
        </section>
    );
}

/** Send this home to a contact: a one-to-one email through the inbox (its checks apply). */
function SendToClient({ listing }: { listing: string }) {
    const [q, setQ] = useState('');
    const [found, setFound] = useState<
        { ulid: string; name: string; email: string | null }[]
    >([]);
    const [to, setTo] = useState<{ ulid: string; name: string } | null>(null);
    const form = useForm({ listing, note: '' });
    useEffect(() => {
        if (q.trim().length < 2 || to) {
            setFound([]);
            return;
        }
        const t = setTimeout(() => {
            fetch(`/contacts/lookup?q=${encodeURIComponent(q)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then((r) => (r.ok ? r.json() : { contacts: [] }))
                .then(
                    (j: {
                        contacts: {
                            ulid: string;
                            name: string;
                            email: string | null;
                        }[];
                    }) => setFound(j.contacts),
                )
                .catch(() => setFound([]));
        }, 250);
        return () => clearTimeout(t);
    }, [q, to]);

    return (
        <section className="grid gap-3 rounded-xl border bg-card p-4 text-sm shadow-xs">
            <h2 className="flex items-center gap-2 text-base font-semibold">
                <Send aria-hidden="true" className="size-4" /> Send to a client
            </h2>
            <p className="-mt-1 text-muted-foreground">
                Email this home to one of your contacts, with a note.
            </p>
            {to ? (
                <form
                    className="grid gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/contacts/${to.ulid}/send-listing`, {
                            preserveScroll: true,
                            onSuccess: () => {
                                setTo(null);
                                setQ('');
                                form.reset('note');
                            },
                        });
                    }}
                >
                    <p>
                        To <strong>{to.name}</strong>{' '}
                        <button
                            type="button"
                            className="underline"
                            onClick={() => setTo(null)}
                        >
                            change
                        </button>
                    </p>
                    <textarea
                        aria-label="Note"
                        rows={2}
                        className="rounded border bg-background p-2"
                        placeholder="Add a note (optional)"
                        value={form.data.note}
                        onChange={(e) => form.setData('note', e.target.value)}
                    />
                    <Button
                        className="justify-self-start"
                        disabled={form.processing}
                    >
                        Send by email
                    </Button>
                    {Object.values(form.errors)[0] && (
                        <p className="text-destructive">
                            {Object.values(form.errors)[0]}
                        </p>
                    )}
                </form>
            ) : (
                <>
                    <input
                        aria-label="Find a contact"
                        placeholder="Find a contact"
                        className="h-10 rounded-md border bg-background px-3"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                    />
                    {found.length > 0 && (
                        <ul className="divide-y rounded border">
                            {found.map((c) => (
                                <li key={c.ulid}>
                                    <button
                                        type="button"
                                        className="w-full p-2 text-left"
                                        onClick={() => setTo(c)}
                                        disabled={!c.email}
                                    >
                                        {c.name}{' '}
                                        <span className="text-muted-foreground">
                                            {c.email ?? 'no email'}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </>
            )}
        </section>
    );
}
