import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

export type ListingCard = {
    ulid: string;
    mls_number: string | null;
    status: string;
    price: number | null;
    beds: number | null;
    baths_full: number | null;
    baths_half: number | null;
    sqft: number | null;
    address: string | null;
    city: string | null;
    state: string | null;
    postal_code: string | null;
    photo: string | null;
    reduced: boolean;
    miles?: number | null;
    attribution: string;
    logo: string | null;
    stale: boolean;
};
type Filters = {
    q: string;
    min_price: number | null;
    max_price: number | null;
    beds: number | null;
    baths: number | null;
    min_sqft: number | null;
    statuses: string[];
    sort: string;
    near: [number, number, number] | null;
    page: number;
    per_page: number;
};
type Props = {
    results: ListingCard[];
    total: number;
    page: number;
    per_page: number;
    filters: Filters;
    licensed: boolean;
    sorts: string[];
    statuses: string[];
};

const sortLabels: Record<string, string> = {
    newest: 'Newest',
    price_asc: 'Price: low to high',
    price_desc: 'Price: high to low',
    sqft_desc: 'Largest',
    reduced: 'Price reduced',
    distance: 'Closest first',
};
export const money = (n: number | null) =>
    n === null
        ? '—'
        : n.toLocaleString(undefined, {
              style: 'currency',
              currency: 'USD',
              maximumFractionDigits: 0,
          });

/** Listings from the brokerage's licensed MLSs, shown as each MLS's rules allow. */
export default function Listings({
    results,
    total,
    page,
    per_page,
    filters,
    licensed,
    sorts,
    statuses,
}: Props) {
    const [f, setF] = useState({
        q: filters.q,
        min_price: filters.min_price ?? '',
        max_price: filters.max_price ?? '',
        beds: filters.beds ?? '',
        baths: filters.baths ?? '',
        statuses: filters.statuses,
        sort: filters.sort,
        near: filters.near ? filters.near.join(',') : '',
    });
    const [locating, setLocating] = useState(false);
    const go = (extra: Record<string, unknown> = {}) =>
        router.get(
            '/listings',
            { ...f, statuses: f.statuses.join(','), ...extra },
            { preserveState: true, preserveScroll: true },
        );
    // SR1: homes near where the agent is standing (the browser asks first).
    const nearMe = (miles: number) => {
        if (!navigator.geolocation) {
            return;
        }
        setLocating(true);
        navigator.geolocation.getCurrentPosition(
            (p) => {
                setLocating(false);
                const near = `${p.coords.latitude.toFixed(5)},${p.coords.longitude.toFixed(5)},${miles}`;
                setF({ ...f, near, sort: 'distance' });
                go({ near, sort: 'distance', page: 1 });
            },
            () => setLocating(false),
        );
    };
    const pages = Math.max(1, Math.ceil(total / per_page));

    return (
        <>
            <Head title="Listings" />
            <div className="mx-auto grid w-full max-w-6xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Listings"
                    description="Homes from your MLSs, shown exactly as each MLS's rules allow."
                />
                {!licensed ? (
                    <EmptyState
                        title="No MLS yet"
                        description="Your brokerage owner can request MLS access in Settings → MLS."
                    />
                ) : (
                    <>
                        <form
                            className="grid gap-2 rounded-lg border p-3 text-sm sm:grid-cols-3 lg:grid-cols-6"
                            onSubmit={(e) => {
                                e.preventDefault();
                                go({ page: 1 });
                            }}
                        >
                            <Input
                                aria-label="City, ZIP, address or MLS number"
                                placeholder="City, ZIP, address or MLS #"
                                className="sm:col-span-2"
                                value={f.q}
                                onChange={(e) =>
                                    setF({ ...f, q: e.target.value })
                                }
                            />
                            <Input
                                aria-label="Min price"
                                inputMode="numeric"
                                placeholder="Min price"
                                value={f.min_price}
                                onChange={(e) =>
                                    setF({ ...f, min_price: e.target.value })
                                }
                            />
                            <Input
                                aria-label="Max price"
                                inputMode="numeric"
                                placeholder="Max price"
                                value={f.max_price}
                                onChange={(e) =>
                                    setF({ ...f, max_price: e.target.value })
                                }
                            />
                            <select
                                aria-label="Beds"
                                className="rounded border bg-background px-2 py-1.5"
                                value={f.beds}
                                onChange={(e) =>
                                    setF({ ...f, beds: e.target.value })
                                }
                            >
                                <option value="">Any beds</option>
                                {[1, 2, 3, 4, 5].map((n) => (
                                    <option key={n} value={n}>
                                        {n}+ beds
                                    </option>
                                ))}
                            </select>
                            <select
                                aria-label="Baths"
                                className="rounded border bg-background px-2 py-1.5"
                                value={f.baths}
                                onChange={(e) =>
                                    setF({ ...f, baths: e.target.value })
                                }
                            >
                                <option value="">Any baths</option>
                                {[1, 2, 3, 4].map((n) => (
                                    <option key={n} value={n}>
                                        {n}+ baths
                                    </option>
                                ))}
                            </select>
                            <fieldset className="flex flex-wrap gap-3 sm:col-span-3 lg:col-span-4">
                                {statuses.map((s) => (
                                    <label
                                        key={s}
                                        className="flex items-center gap-1"
                                    >
                                        <input
                                            type="checkbox"
                                            className="size-4"
                                            checked={f.statuses.includes(s)}
                                            onChange={(e) =>
                                                setF({
                                                    ...f,
                                                    statuses: e.target.checked
                                                        ? [...f.statuses, s]
                                                        : f.statuses.filter(
                                                              (x) => x !== s,
                                                          ),
                                                })
                                            }
                                        />
                                        {s}
                                    </label>
                                ))}
                            </fieldset>
                            <select
                                aria-label="Sort"
                                className="rounded border bg-background px-2 py-1.5"
                                value={f.sort}
                                onChange={(e) =>
                                    setF({ ...f, sort: e.target.value })
                                }
                            >
                                {sorts
                                    .filter(
                                        (s) =>
                                            s !== 'distance' || f.near !== '',
                                    )
                                    .map((s) => (
                                        <option key={s} value={s}>
                                            {sortLabels[s] ?? s}
                                        </option>
                                    ))}
                            </select>
                            <Button>Search</Button>
                        </form>
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <span>Near me:</span>
                            {[1, 3, 10].map((m) => (
                                <Button
                                    key={m}
                                    size="sm"
                                    variant="outline"
                                    disabled={locating}
                                    onClick={() => nearMe(m)}
                                >
                                    {m} {m === 1 ? 'mile' : 'miles'}
                                </Button>
                            ))}
                            {f.near !== '' && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => {
                                        setF({
                                            ...f,
                                            near: '',
                                            sort: 'newest',
                                        });
                                        go({
                                            near: '',
                                            sort: 'newest',
                                            page: 1,
                                        });
                                    }}
                                >
                                    Anywhere
                                </Button>
                            )}
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {total.toLocaleString()} homes
                        </p>
                        {results.length === 0 ? (
                            <EmptyState
                                title="No homes match"
                                description="Try widening the price range or area."
                            />
                        ) : (
                            <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {results.map((l) => (
                                    <li
                                        key={l.ulid}
                                        className="overflow-hidden rounded-lg border"
                                    >
                                        <Link
                                            href={`/listings/${l.ulid}`}
                                            className="grid"
                                        >
                                            {l.photo ? (
                                                <img
                                                    src={l.photo}
                                                    alt={l.address ?? 'Home'}
                                                    loading="lazy"
                                                    className="aspect-[4/3] w-full object-cover"
                                                />
                                            ) : (
                                                <div className="grid aspect-[4/3] place-items-center bg-muted text-sm text-muted-foreground">
                                                    Photos coming
                                                </div>
                                            )}
                                            <div className="grid gap-1 p-3 text-sm">
                                                <span className="flex items-center gap-2">
                                                    <strong className="text-base">
                                                        {money(l.price)}
                                                    </strong>
                                                    {l.reduced && (
                                                        <Badge variant="outline">
                                                            Price reduced
                                                        </Badge>
                                                    )}
                                                    {l.status !== 'Active' && (
                                                        <Badge variant="outline">
                                                            {l.status}
                                                        </Badge>
                                                    )}
                                                </span>
                                                <span>
                                                    {l.beds ?? '—'} bd ·{' '}
                                                    {(l.baths_full ?? 0) +
                                                        (l.baths_half
                                                            ? 0.5
                                                            : 0) || '—'}{' '}
                                                    ba ·{' '}
                                                    {l.sqft
                                                        ? `${l.sqft.toLocaleString()} sq ft`
                                                        : '—'}
                                                </span>
                                                <span className="text-muted-foreground">
                                                    {[
                                                        l.address,
                                                        l.city,
                                                        l.state,
                                                        l.postal_code,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(', ')}
                                                </span>
                                                {l.miles != null && (
                                                    <span>
                                                        {l.miles} miles away
                                                    </span>
                                                )}
                                                <span className="text-xs text-muted-foreground">
                                                    {l.attribution}
                                                </span>
                                            </div>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {pages > 1 && (
                            <nav
                                className="flex items-center gap-2 text-sm"
                                aria-label="Pages"
                            >
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={page <= 1}
                                    onClick={() => go({ page: page - 1 })}
                                >
                                    Previous
                                </Button>
                                <span>
                                    Page {page} of {pages}
                                </span>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={page >= pages}
                                    onClick={() => go({ page: page + 1 })}
                                >
                                    Next
                                </Button>
                            </nav>
                        )}
                    </>
                )}
            </div>
        </>
    );
}
