import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
    }[];
    price_history: { date: string; from: number | null; to: number }[];
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

    return (
        <>
            <Head title={l.address ?? 'Listing'} />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 md:p-6">
                <Link href="/listings" className="text-sm underline">
                    ← Back to listings
                </Link>
                {l.stale && (
                    <p className="rounded border p-2 text-sm">
                        MLS data is updating; details may be out of date.
                    </p>
                )}
                {photo ? (
                    <figure className="grid gap-2">
                        <img
                            src={photo.hero ?? photo.url ?? ''}
                            alt={photo.caption ?? l.address ?? 'Home photo'}
                            className="max-h-[32rem] w-full rounded-lg object-cover"
                        />
                        <div className="flex gap-2 overflow-x-auto" role="list">
                            {l.photos.map((p, n) => (
                                <button
                                    key={n}
                                    type="button"
                                    onClick={() => setI(n)}
                                    aria-label={`Photo ${n + 1}`}
                                    className={`shrink-0 rounded ${n === i ? 'ring-2 ring-primary' : ''}`}
                                >
                                    <img
                                        src={p.thumb ?? ''}
                                        alt=""
                                        className="h-16 w-24 rounded object-cover"
                                        loading="lazy"
                                    />
                                </button>
                            ))}
                        </div>
                    </figure>
                ) : (
                    <div className="grid h-64 place-items-center rounded-lg bg-muted text-muted-foreground">
                        Photos coming
                    </div>
                )}
                <div className="flex flex-wrap items-baseline gap-3">
                    <h1 className="text-2xl font-semibold">{money(l.price)}</h1>
                    <Badge variant="outline">{l.status}</Badge>
                    {l.original_price !== null &&
                        l.price !== null &&
                        l.price < l.original_price && (
                            <Badge variant="outline">
                                Reduced from {money(l.original_price)}
                            </Badge>
                        )}
                </div>
                <p className="text-lg">
                    {[l.address, l.city, l.state, l.postal_code]
                        .filter(Boolean)
                        .join(', ')}
                </p>
                <dl className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-3">
                    {facts
                        .filter(([, v]) => v !== null && v !== '')
                        .map(([k, v]) => (
                            <div key={k} className="rounded border p-2">
                                <dt className="text-xs text-muted-foreground">
                                    {k}
                                </dt>
                                <dd className="font-medium">{v}</dd>
                            </div>
                        ))}
                </dl>
                {l.remarks && (
                    <p className="whitespace-pre-line">{l.remarks}</p>
                )}
                {l.virtual_tour && (
                    <Button
                        variant="outline"
                        asChild
                        className="justify-self-start"
                    >
                        <a
                            href={l.virtual_tour}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Virtual tour
                        </a>
                    </Button>
                )}
                <SendToClient listing={l.ulid} />
                {l.price_history.length > 0 && (
                    <section className="grid gap-1 text-sm">
                        <h2 className="font-semibold">Price history</h2>
                        <ul>
                            {l.price_history.map((h, n) => (
                                <li key={n}>
                                    {h.date}:{' '}
                                    {h.from !== null
                                        ? `${money(h.from)} → `
                                        : ''}
                                    {money(h.to)}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                <footer className="grid gap-2 border-t pt-3 text-xs text-muted-foreground">
                    {l.logo && (
                        <img src={l.logo} alt="MLS" className="h-8 w-auto" />
                    )}
                    <p>{l.attribution}</p>
                    {l.disclaimer && <p>{l.disclaimer}</p>}
                </footer>
            </div>
        </>
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
        <section className="grid gap-2 rounded-lg border p-3 text-sm">
            <h2 className="font-semibold">Send to a client</h2>
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
                        className="rounded border bg-background px-2 py-1.5"
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
