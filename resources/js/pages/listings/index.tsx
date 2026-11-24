import { Head, Link, router } from '@inertiajs/react';
import {
    Bath,
    BedDouble,
    ChevronDown,
    Home,
    LayoutGrid,
    List,
    LocateFixed,
    Ruler,
    Search,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

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
    features: string[];
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
    /** C03: features seen in photos people can search on (empty until photo tagging is Live). */
    featureChoices: Record<string, string>;
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

/** "$1.25M", "$615K": short prices for the filter button. */
const short = (n: number) =>
    n >= 1_000_000
        ? `$${(n / 1_000_000).toFixed(n % 1_000_000 === 0 ? 0 : 2).replace(/\.?0+$/, '')}M`
        : `$${Math.round(n / 1000)}K`;

const baths = (l: ListingCard) =>
    l.baths_full === null
        ? null
        : (l.baths_full ?? 0) + (l.baths_half ? 0.5 : 0);

const addressLine = (l: ListingCard) =>
    [l.city, l.state, l.postal_code].filter(Boolean).join(', ');

const VIEW_KEY = 'listings-view';

/**
 * PG3: MLS search. Homes from the brokerage's licensed MLSs, shown as each MLS's rules allow: one search box, quick
 * filters as buttons, and the results as photo cards or a list.
 */
export default function Listings({
    results,
    total,
    page,
    per_page,
    filters,
    licensed,
    sorts,
    statuses,
    featureChoices,
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
        features: filters.features[0] ?? '',
    });
    const [locating, setLocating] = useState(false);
    const [view, setView] = useState<'grid' | 'list'>('grid');
    useEffect(() => {
        try {
            if (window.localStorage.getItem(VIEW_KEY) === 'list') {
                setView('list');
            }
        } catch {
            // Storage can be blocked; the grid is fine.
        }
    }, []);
    const pickView = (v: 'grid' | 'list') => {
        setView(v);
        try {
            window.localStorage.setItem(VIEW_KEY, v);
        } catch {
            // Not remembered; nothing else to do.
        }
    };

    const go = (
        next: Partial<typeof f> = {},
        extra: Record<string, unknown> = {},
    ) => {
        const merged = { ...f, ...next };
        setF(merged);
        router.get(
            '/listings',
            {
                ...merged,
                statuses: merged.statuses.join(','),
                page: 1,
                ...extra,
            },
            { preserveState: true, preserveScroll: true },
        );
    };
    // SR1: homes near where the agent is standing (the browser asks first).
    const nearMe = (miles: number) => {
        if (!navigator.geolocation) {
            return;
        }
        setLocating(true);
        navigator.geolocation.getCurrentPosition(
            (p) => {
                setLocating(false);
                go({
                    near: `${p.coords.latitude.toFixed(5)},${p.coords.longitude.toFixed(5)},${miles}`,
                    sort: 'distance',
                });
            },
            () => setLocating(false),
        );
    };
    const pages = Math.max(1, Math.ceil(total / per_page));
    const priceLabel =
        f.min_price === '' && f.max_price === ''
            ? 'Price'
            : f.max_price === ''
              ? `${short(Number(f.min_price))}+`
              : f.min_price === ''
                ? `Up to ${short(Number(f.max_price))}`
                : `${short(Number(f.min_price))} – ${short(Number(f.max_price))}`;
    const active =
        f.min_price !== '' ||
        f.max_price !== '' ||
        f.beds !== '' ||
        f.baths !== '' ||
        f.features !== '' ||
        f.near !== '';

    return (
        <>
            <Head title="MLS search" />
            <div className="mx-auto grid w-full max-w-7xl gap-5 p-4 md:p-6">
                <PageHeader
                    title="MLS search"
                    description="Homes from your MLSs, shown exactly as each MLS's rules allow."
                />
                {!licensed ? (
                    <EmptyState
                        title="No MLS yet"
                        description="Your brokerage owner can request MLS access in Settings › MLS."
                    />
                ) : (
                    <>
                        <div className="grid gap-3 rounded-xl border bg-card p-3 shadow-xs md:p-4">
                            <form
                                className="flex gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    go();
                                }}
                            >
                                <div className="relative flex-1">
                                    <Search
                                        aria-hidden="true"
                                        className="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-muted-foreground"
                                    />
                                    <Input
                                        aria-label="City, ZIP, address or MLS number"
                                        placeholder="City, ZIP, address or MLS #"
                                        className="h-12 pl-10 text-base"
                                        value={f.q}
                                        onChange={(e) =>
                                            setF({ ...f, q: e.target.value })
                                        }
                                    />
                                </div>
                                <Button className="h-12 px-6 text-base">
                                    Search
                                </Button>
                            </form>
                            <div className="flex flex-wrap items-center gap-2 text-sm">
                                <Popover>
                                    <PopoverTrigger asChild>
                                        <FilterButton
                                            on={
                                                f.min_price !== '' ||
                                                f.max_price !== ''
                                            }
                                        >
                                            {priceLabel}
                                        </FilterButton>
                                    </PopoverTrigger>
                                    <PopoverContent
                                        align="start"
                                        className="grid w-72 gap-3"
                                    >
                                        <p className="font-medium">Price</p>
                                        <div className="grid grid-cols-2 gap-2">
                                            <Input
                                                aria-label="Lowest price"
                                                inputMode="numeric"
                                                placeholder="No min"
                                                value={f.min_price}
                                                onChange={(e) =>
                                                    setF({
                                                        ...f,
                                                        min_price:
                                                            e.target.value.replace(
                                                                /\D/g,
                                                                '',
                                                            ),
                                                    })
                                                }
                                            />
                                            <Input
                                                aria-label="Highest price"
                                                inputMode="numeric"
                                                placeholder="No max"
                                                value={f.max_price}
                                                onChange={(e) =>
                                                    setF({
                                                        ...f,
                                                        max_price:
                                                            e.target.value.replace(
                                                                /\D/g,
                                                                '',
                                                            ),
                                                    })
                                                }
                                            />
                                        </div>
                                        <Button size="sm" onClick={() => go()}>
                                            Show homes
                                        </Button>
                                    </PopoverContent>
                                </Popover>
                                <Choice
                                    label="Beds"
                                    value={String(f.beds)}
                                    options={[1, 2, 3, 4, 5]}
                                    onPick={(v) => go({ beds: v })}
                                />
                                <Choice
                                    label="Baths"
                                    value={String(f.baths)}
                                    options={[1, 2, 3, 4]}
                                    onPick={(v) => go({ baths: v })}
                                />
                                <Popover>
                                    <PopoverTrigger asChild>
                                        <FilterButton on>
                                            {f.statuses.length === 0
                                                ? 'Any status'
                                                : f.statuses.length === 1
                                                  ? f.statuses[0]
                                                  : `${f.statuses.length} statuses`}
                                        </FilterButton>
                                    </PopoverTrigger>
                                    <PopoverContent
                                        align="start"
                                        className="grid w-64 gap-2"
                                    >
                                        <p className="font-medium">Status</p>
                                        {statuses.map((s) => (
                                            <label
                                                key={s}
                                                className="flex items-center gap-2"
                                            >
                                                <input
                                                    type="checkbox"
                                                    className="size-4"
                                                    checked={f.statuses.includes(
                                                        s,
                                                    )}
                                                    onChange={(e) =>
                                                        setF({
                                                            ...f,
                                                            statuses: e.target
                                                                .checked
                                                                ? [
                                                                      ...f.statuses,
                                                                      s,
                                                                  ]
                                                                : f.statuses.filter(
                                                                      (x) =>
                                                                          x !==
                                                                          s,
                                                                  ),
                                                        })
                                                    }
                                                />
                                                {s}
                                            </label>
                                        ))}
                                        <Button size="sm" onClick={() => go()}>
                                            Show homes
                                        </Button>
                                    </PopoverContent>
                                </Popover>
                                {Object.keys(featureChoices).length > 0 && (
                                    <select
                                        aria-label="Seen in the photos"
                                        className={cn(
                                            'h-9 rounded-full border bg-background px-3',
                                            f.features !== '' &&
                                                'border-primary bg-primary/5 text-primary',
                                        )}
                                        value={f.features}
                                        onChange={(e) =>
                                            go({ features: e.target.value })
                                        }
                                    >
                                        <option value="">Has anything</option>
                                        {Object.entries(featureChoices).map(
                                            ([k, label]) => (
                                                <option key={k} value={k}>
                                                    Has: {label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                )}
                                <Popover>
                                    <PopoverTrigger asChild>
                                        <FilterButton on={f.near !== ''}>
                                            <LocateFixed aria-hidden="true" />
                                            {f.near !== ''
                                                ? `Within ${f.near.split(',')[2]} miles`
                                                : 'Near me'}
                                        </FilterButton>
                                    </PopoverTrigger>
                                    <PopoverContent
                                        align="start"
                                        className="grid w-60 gap-2"
                                    >
                                        <p className="font-medium">
                                            Homes near where you are
                                        </p>
                                        <div className="flex gap-2">
                                            {[1, 3, 10].map((m) => (
                                                <Button
                                                    key={m}
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={locating}
                                                    onClick={() => nearMe(m)}
                                                >
                                                    {m}{' '}
                                                    {m === 1 ? 'mile' : 'miles'}
                                                </Button>
                                            ))}
                                        </div>
                                        <p className="text-xs text-muted-foreground">
                                            Your browser asks before sharing
                                            where you are.
                                        </p>
                                    </PopoverContent>
                                </Popover>
                                {active && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            go({
                                                min_price: '',
                                                max_price: '',
                                                beds: '',
                                                baths: '',
                                                features: '',
                                                near: '',
                                                sort:
                                                    f.sort === 'distance'
                                                        ? 'newest'
                                                        : f.sort,
                                            })
                                        }
                                    >
                                        <X aria-hidden="true" /> Clear filters
                                    </Button>
                                )}
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            <p className="text-base">
                                <strong>{total.toLocaleString()}</strong>{' '}
                                {total === 1 ? 'home' : 'homes'}
                            </p>
                            <label className="ml-auto flex items-center gap-2 text-sm text-muted-foreground">
                                Sort by
                                <select
                                    className="h-9 rounded-md border bg-background px-2 text-foreground"
                                    value={f.sort}
                                    onChange={(e) =>
                                        go({ sort: e.target.value })
                                    }
                                >
                                    {sorts
                                        .filter(
                                            (s) =>
                                                s !== 'distance' ||
                                                f.near !== '',
                                        )
                                        .map((s) => (
                                            <option key={s} value={s}>
                                                {sortLabels[s] ?? s}
                                            </option>
                                        ))}
                                </select>
                            </label>
                            <div
                                role="group"
                                aria-label="Show as"
                                className="flex rounded-md border p-0.5"
                            >
                                {(
                                    [
                                        ['grid', LayoutGrid, 'Photos'],
                                        ['list', List, 'List'],
                                    ] as const
                                ).map(([v, Icon, label]) => (
                                    <Button
                                        key={v}
                                        size="sm"
                                        variant={
                                            view === v ? 'secondary' : 'ghost'
                                        }
                                        aria-pressed={view === v}
                                        onClick={() => pickView(v)}
                                    >
                                        <Icon aria-hidden="true" /> {label}
                                    </Button>
                                ))}
                            </div>
                        </div>

                        {results.length === 0 ? (
                            <EmptyState
                                title="No homes match"
                                description="Try a wider price range, fewer beds or another area."
                            />
                        ) : view === 'grid' ? (
                            <ul className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                                {results.map((l) => (
                                    <li key={l.ulid}>
                                        <HomeCard home={l} />
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <HomeList homes={results} />
                        )}
                        {pages > 1 && (
                            <nav
                                className="flex items-center justify-center gap-3 text-sm"
                                aria-label="Pages"
                            >
                                <Button
                                    variant="outline"
                                    disabled={page <= 1}
                                    onClick={() => go({}, { page: page - 1 })}
                                >
                                    Previous
                                </Button>
                                <span>
                                    Page {page} of {pages}
                                </span>
                                <Button
                                    variant="outline"
                                    disabled={page >= pages}
                                    onClick={() => go({}, { page: page + 1 })}
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

function FilterButton({
    on,
    children,
    ...props
}: React.ComponentProps<'button'> & { on: boolean }) {
    return (
        <button
            type="button"
            {...props}
            className={cn(
                'inline-flex h-9 items-center gap-1.5 rounded-full border bg-background px-3.5 font-medium hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none [&_svg]:size-4',
                on && 'border-primary bg-primary/5 text-primary',
            )}
        >
            {children}
            <ChevronDown aria-hidden="true" className="opacity-60" />
        </button>
    );
}

/** Beds and baths: "Any, 1+, 2+ …" as one row of buttons. */
function Choice({
    label,
    value,
    options,
    onPick,
}: {
    label: string;
    value: string;
    options: number[];
    onPick: (v: string) => void;
}) {
    return (
        <Popover>
            <PopoverTrigger asChild>
                <FilterButton on={value !== ''}>
                    {value === '' ? label : `${value}+ ${label.toLowerCase()}`}
                </FilterButton>
            </PopoverTrigger>
            <PopoverContent align="start" className="grid w-auto gap-2">
                <p className="font-medium">{label}</p>
                <div
                    role="group"
                    aria-label={label}
                    className="flex overflow-hidden rounded-md border"
                >
                    {['', ...options.map(String)].map((o) => (
                        <button
                            key={o}
                            type="button"
                            aria-pressed={value === o}
                            onClick={() => onPick(o)}
                            className={cn(
                                'min-w-11 border-r px-3 py-2 text-sm last:border-r-0 hover:bg-muted',
                                value === o &&
                                    'bg-primary text-primary-foreground hover:bg-primary',
                            )}
                        >
                            {o === '' ? 'Any' : `${o}+`}
                        </button>
                    ))}
                </div>
            </PopoverContent>
        </Popover>
    );
}

function Facts({ home: l }: { home: ListingCard }) {
    const b = baths(l);

    return (
        <span className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm [&_svg]:size-4 [&_svg]:text-muted-foreground">
            <span className="inline-flex items-center gap-1.5">
                <BedDouble aria-hidden="true" />
                <strong>{l.beds ?? '—'}</strong> beds
            </span>
            <span className="inline-flex items-center gap-1.5">
                <Bath aria-hidden="true" />
                <strong>{b ?? '—'}</strong> baths
            </span>
            <span className="inline-flex items-center gap-1.5">
                <Ruler aria-hidden="true" />
                <strong>{l.sqft ? l.sqft.toLocaleString() : '—'}</strong> sq ft
            </span>
        </span>
    );
}

function Photo({
    home: l,
    className,
}: {
    home: ListingCard;
    className?: string;
}) {
    return l.photo ? (
        <img
            src={l.photo}
            alt={l.address ?? 'Home'}
            loading="lazy"
            className={cn('w-full object-cover', className)}
        />
    ) : (
        <div
            className={cn(
                'grid w-full place-items-center bg-gradient-to-br from-slate-100 to-slate-200 text-slate-400 dark:from-slate-800 dark:to-slate-900 dark:text-slate-500',
                className,
            )}
        >
            <span className="grid justify-items-center gap-1 text-sm">
                <Home aria-hidden="true" className="size-9" />
                Photos coming
            </span>
        </div>
    );
}

function StatusChips({ home: l }: { home: ListingCard }) {
    return (
        <>
            {l.status !== 'Active' && (
                <Badge className="bg-amber-500 text-white hover:bg-amber-500">
                    {l.status}
                </Badge>
            )}
            {l.reduced && (
                <Badge className="bg-rose-600 text-white hover:bg-rose-600">
                    Price cut
                </Badge>
            )}
        </>
    );
}

function HomeCard({ home: l }: { home: ListingCard }) {
    return (
        <Link
            href={`/listings/${l.ulid}`}
            className="group grid overflow-hidden rounded-xl border bg-card shadow-xs transition-shadow hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            <div className="relative overflow-hidden">
                <Photo
                    home={l}
                    className="aspect-[3/2] transition-transform duration-300 group-hover:scale-[1.02] motion-reduce:transition-none"
                />
                <span className="absolute top-3 left-3 flex gap-1.5">
                    <StatusChips home={l} />
                </span>
                {l.miles != null && (
                    <span className="absolute right-3 bottom-3 rounded-full bg-black/60 px-2.5 py-1 text-xs font-medium text-white">
                        {l.miles} miles away
                    </span>
                )}
            </div>
            <div className="grid gap-2 p-4">
                <p className="text-2xl font-semibold tracking-tight tabular-nums">
                    {money(l.price)}
                </p>
                <Facts home={l} />
                <p className="grid leading-snug">
                    <span className="font-medium">
                        {l.address ?? 'Address withheld'}
                    </span>
                    <span className="text-muted-foreground">
                        {addressLine(l)}
                    </span>
                </p>
                <p className="flex items-center gap-2 border-t pt-2 text-xs text-muted-foreground">
                    {l.logo && (
                        <img src={l.logo} alt="" className="h-4 w-auto" />
                    )}
                    <span>{l.attribution}</span>
                    {l.mls_number && (
                        <span className="ml-auto">MLS {l.mls_number}</span>
                    )}
                </p>
            </div>
        </Link>
    );
}

function HomeList({ homes }: { homes: ListingCard[] }) {
    return (
        <div className="overflow-x-auto rounded-xl border bg-card">
            <table className="w-full text-left text-sm">
                <thead className="bg-muted/50 text-muted-foreground">
                    <tr>
                        <th className="px-4 py-3 font-medium">Home</th>
                        <th className="px-4 py-3 text-right font-medium">
                            Price
                        </th>
                        <th className="px-4 py-3 font-medium">Beds</th>
                        <th className="px-4 py-3 font-medium">Baths</th>
                        <th className="px-4 py-3 font-medium">Sq ft</th>
                        <th className="px-4 py-3 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody>
                    {homes.map((l) => (
                        <tr key={l.ulid} className="border-t hover:bg-muted/40">
                            <td className="px-4 py-2">
                                <Link
                                    href={`/listings/${l.ulid}`}
                                    className="flex items-center gap-3"
                                >
                                    <Photo
                                        home={l}
                                        className="aspect-[4/3] w-20 shrink-0 rounded-md [&_span]:text-[0px] [&_svg]:size-5"
                                    />
                                    <span className="grid">
                                        <span className="font-medium underline-offset-2 hover:underline">
                                            {l.address ?? 'Address withheld'}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {addressLine(l)}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {l.attribution}
                                        </span>
                                    </span>
                                </Link>
                            </td>
                            <td className="px-4 py-2 text-right font-semibold tabular-nums">
                                {money(l.price)}
                            </td>
                            <td className="px-4 py-2">{l.beds ?? '—'}</td>
                            <td className="px-4 py-2">{baths(l) ?? '—'}</td>
                            <td className="px-4 py-2 tabular-nums">
                                {l.sqft ? l.sqft.toLocaleString() : '—'}
                            </td>
                            <td className="px-4 py-2">
                                <span className="flex flex-wrap gap-1">
                                    {l.status === 'Active' && !l.reduced ? (
                                        <Badge variant="outline">Active</Badge>
                                    ) : (
                                        <StatusChips home={l} />
                                    )}
                                </span>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
