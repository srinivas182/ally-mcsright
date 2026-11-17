import type { FormDataConvertible } from '@inertiajs/core';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { getJson } from '@/lib/http';

type Adj = { key: string; label: string; cents: number; edited: boolean };
type Comp = {
    listing_id: number;
    address: string;
    city: string | null;
    price_cents: number;
    sold_on: string | null;
    beds: number | null;
    baths_full: number | null;
    baths_half: number | null;
    sqft: number | null;
    year_built: number | null;
    miles: number | null;
    reason: string;
    adjustments: Adj[];
    adjusted_cents: number;
    photo: string | null;
};
type NetLine = { key: string; label: string; cents: number; note: string };
type Result = {
    comps: Comp[];
    low_cents: number;
    likely_cents: number;
    high_cents: number;
    confidence: string;
    confidence_reason: string;
    sold: boolean;
    market: {
        zip: string;
        sold_90: number;
        median_dom: number | null;
        sale_to_list: number | null;
        active: number;
        months_of_inventory: number | null;
        ppsf_change: number | null;
    };
    alternates: {
        listing_id: number;
        address: string | null;
        price_cents: number;
        reason: string;
    }[];
    net: Record<
        'low' | 'likely' | 'high',
        { price_cents: number; net_cents: number; lines: NetLine[] }
    >;
};
type Cma = {
    ulid: string;
    title: string;
    lang: string;
    summary: string | null;
    net: {
        payoff_cents: number;
        listing_pct: number;
        buyer_pct: number;
        closing_pct: number;
        repairs_cents: number;
        concessions_cents: number;
    };
    shared_at: string | null;
    share_expires: string | null;
    views: number;
    last_viewed: string | null;
    contact: {
        ulid: string;
        first_name: string;
        last_name: string | null;
    } | null;
};

const usd = (cents: number) =>
    (cents / 100).toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 0,
    });
const conf: Record<string, string> = {
    high: 'High confidence',
    medium: 'Medium confidence',
    low: 'Low confidence',
    none: 'No comps yet',
};

/** One CMA (CM1): the range, the comps with their adjustments (editable), the market, the net sheet, the summary and sharing. */
export default function CmaPage({
    cma,
    result: r,
    shareUrl,
}: {
    cma: Cma;
    result: Result;
    shareUrl: string | null;
}) {
    const opts = { preserveScroll: true };
    const errors = usePage().props.errors as Record<string, string>;
    const patch = (data: Record<string, FormDataConvertible>) =>
        router.patch(`/pricing/cma/${cma.ulid}`, data, opts);
    const [q, setQ] = useState('');
    const [found, setFound] = useState<
        {
            ulid: string;
            address: string;
            price_cents: number;
            sold_on: string | null;
        }[]
    >([]);
    const [net, setNet] = useState({
        payoff: cma.net.payoff_cents / 100,
        listing_pct: cma.net.listing_pct,
        buyer_pct: cma.net.buyer_pct,
        closing_pct: cma.net.closing_pct,
        repairs: cma.net.repairs_cents / 100,
        concessions: cma.net.concessions_cents / 100,
    });
    const [copied, setCopied] = useState(false);

    return (
        <>
            <Head title={`CMA · ${cma.title}`} />
            <div className="grid gap-5 p-4 text-base">
                <PageHeader
                    title={cma.title}
                    description={
                        cma.contact
                            ? `For ${cma.contact.first_name} ${cma.contact.last_name ?? ''}`
                            : 'Comparative market analysis'
                    }
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/pricing">All CMAs</Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <a href={`/pricing/cma/${cma.ulid}/pdf`}>
                                    Download PDF
                                </a>
                            </Button>
                            <Button
                                onClick={() =>
                                    router.post(
                                        `/pricing/cma/${cma.ulid}/share`,
                                        {},
                                        opts,
                                    )
                                }
                            >
                                {cma.shared_at
                                    ? 'New share link'
                                    : 'Share with the seller'}
                            </Button>
                        </div>
                    }
                />

                {shareUrl && (
                    <div
                        className="flex flex-wrap items-center gap-2 rounded-lg border border-emerald-300 bg-emerald-50 p-3 text-sm dark:border-emerald-800 dark:bg-emerald-950/30"
                        role="status"
                    >
                        <span className="min-w-0 flex-1 break-all">
                            {shareUrl}
                        </span>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => {
                                void navigator.clipboard?.writeText(shareUrl);
                                setCopied(true);
                            }}
                        >
                            {copied ? 'Copied' : 'Copy link'}
                        </Button>
                    </div>
                )}
                {cma.shared_at && (
                    <p className="text-sm text-muted-foreground">
                        Shared {cma.shared_at} (link works until{' '}
                        {cma.share_expires}) ·{' '}
                        {cma.views > 0
                            ? `opened ${cma.views}×, last ${cma.last_viewed}`
                            : 'not opened yet'}
                    </p>
                )}

                <section
                    className="grid gap-2 rounded-lg border p-4"
                    aria-labelledby="price"
                >
                    <h2 id="price" className="text-sm text-muted-foreground">
                        Suggested price
                    </h2>
                    <p className="text-3xl font-semibold">
                        {usd(r.low_cents)} – {usd(r.high_cents)}
                    </p>
                    <p>
                        Most likely <strong>{usd(r.likely_cents)}</strong>{' '}
                        <Badge variant="outline">
                            {conf[r.confidence] ?? r.confidence}
                        </Badge>
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {r.confidence_reason}
                    </p>
                    {!r.sold && (
                        <p className="text-sm text-amber-700 dark:text-amber-300">
                            Using asking prices: your MLS licence doesn't
                            include sold data.
                        </p>
                    )}
                </section>

                <section className="grid gap-3" aria-labelledby="comps">
                    <h2 id="comps" className="text-lg font-semibold">
                        Comparable homes ({r.comps.length})
                    </h2>
                    {r.comps.map((c) => (
                        <article
                            key={c.listing_id}
                            className="grid gap-3 rounded-lg border p-3 sm:grid-cols-[160px_1fr]"
                        >
                            {c.photo ? (
                                <img
                                    src={c.photo}
                                    alt={c.address}
                                    className="h-28 w-full rounded object-cover sm:w-40"
                                    loading="lazy"
                                />
                            ) : (
                                <div className="hidden sm:block" />
                            )}
                            <div className="grid gap-2">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <strong>{c.address}</strong>
                                        <p className="text-sm text-muted-foreground">
                                            {c.reason}
                                        </p>
                                        <p className="text-sm">
                                            {c.beds ?? '–'} bd ·{' '}
                                            {c.baths_full ?? '–'}
                                            {c.baths_half
                                                ? `.${c.baths_half}`
                                                : ''}{' '}
                                            ba ·{' '}
                                            {c.sqft
                                                ? `${c.sqft.toLocaleString()} sq ft`
                                                : ''}{' '}
                                            {c.year_built
                                                ? `· ${c.year_built}`
                                                : ''}
                                        </p>
                                    </div>
                                    <div className="text-right">
                                        <p className="text-sm text-muted-foreground">
                                            {r.sold ? 'Sold' : 'Asking'}{' '}
                                            {usd(c.price_cents)}{' '}
                                            {c.sold_on ? `· ${c.sold_on}` : ''}
                                        </p>
                                        <p className="font-semibold">
                                            Adjusted {usd(c.adjusted_cents)}
                                        </p>
                                    </div>
                                </div>
                                {c.adjustments.length > 0 && (
                                    <ul className="grid gap-1 text-sm">
                                        {c.adjustments.map((a) => (
                                            <li
                                                key={a.key}
                                                className="flex flex-wrap items-center gap-2"
                                            >
                                                <span className="min-w-0 flex-1">
                                                    {a.label}
                                                </span>
                                                <Input
                                                    aria-label={`${a.label} adjustment in dollars`}
                                                    className="h-8 w-28 text-right"
                                                    inputMode="numeric"
                                                    defaultValue={Math.round(
                                                        a.cents / 100,
                                                    )}
                                                    onBlur={(e) => {
                                                        if (
                                                            Number(
                                                                e.target.value,
                                                            ) *
                                                                100 !==
                                                            a.cents
                                                        ) {
                                                            patch({
                                                                overrides: {
                                                                    [c.listing_id]:
                                                                        {
                                                                            [a.key]:
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                },
                                                            });
                                                        }
                                                    }}
                                                />
                                                {a.edited && (
                                                    <button
                                                        type="button"
                                                        className="text-xs underline"
                                                        onClick={() =>
                                                            patch({
                                                                overrides: {
                                                                    [c.listing_id]:
                                                                        {
                                                                            [a.key]:
                                                                                null,
                                                                        },
                                                                },
                                                            })
                                                        }
                                                    >
                                                        reset
                                                    </button>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                <button
                                    type="button"
                                    className="justify-self-start text-sm underline"
                                    onClick={() =>
                                        patch({ on: { [c.listing_id]: false } })
                                    }
                                >
                                    Leave this one out
                                </button>
                            </div>
                        </article>
                    ))}

                    {r.alternates.length > 0 && (
                        <details className="rounded-lg border p-3">
                            <summary className="cursor-pointer">
                                Other homes found ({r.alternates.length})
                            </summary>
                            <ul className="mt-2 grid gap-2">
                                {r.alternates.map((a) => (
                                    <li
                                        key={a.listing_id}
                                        className="flex flex-wrap items-center gap-2 text-sm"
                                    >
                                        <span className="min-w-0 flex-1">
                                            <strong>{a.address}</strong> ·{' '}
                                            {usd(a.price_cents)} ·{' '}
                                            <span className="text-muted-foreground">
                                                {a.reason}
                                            </span>
                                        </span>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                patch({
                                                    on: {
                                                        [a.listing_id]: true,
                                                    },
                                                })
                                            }
                                        >
                                            Use it
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        </details>
                    )}

                    <form
                        className="flex flex-wrap items-end gap-2"
                        onSubmit={async (e) => {
                            e.preventDefault();
                            const res = await getJson<{ homes: typeof found }>(
                                `/pricing/cma/${cma.ulid}/search?q=${encodeURIComponent(q)}`,
                            );
                            setFound(res.homes);
                        }}
                    >
                        <label className="grid gap-1 text-sm">
                            Add a home by street
                            <Input
                                className="w-64"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="e.g. 14 Oak"
                            />
                        </label>
                        <Button variant="outline">Search</Button>
                    </form>
                    {found.length > 0 && (
                        <ul className="grid gap-1 text-sm">
                            {found.map((h) => (
                                <li
                                    key={h.ulid}
                                    className="flex flex-wrap items-center gap-2"
                                >
                                    <span className="min-w-0 flex-1">
                                        {h.address} · {usd(h.price_cents)}{' '}
                                        {h.sold_on ? `· sold ${h.sold_on}` : ''}
                                    </span>
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                `/pricing/cma/${cma.ulid}/comps`,
                                                { listing: h.ulid },
                                                {
                                                    ...opts,
                                                    onSuccess: () =>
                                                        setFound([]),
                                                },
                                            )
                                        }
                                    >
                                        Add
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {errors.comp && (
                        <p className="text-sm text-red-700">{errors.comp}</p>
                    )}
                </section>

                <section
                    className="grid gap-2 rounded-lg border p-4"
                    aria-labelledby="market"
                >
                    <h2 id="market" className="font-semibold">
                        The market in {r.market.zip}
                    </h2>
                    <ul className="grid gap-1 text-sm sm:grid-cols-2">
                        <li>{r.market.sold_90} sold in the last 90 days</li>
                        <li>{r.market.active} for sale now</li>
                        {r.market.median_dom !== null && (
                            <li>{r.market.median_dom} median days on market</li>
                        )}
                        {r.market.sale_to_list !== null && (
                            <li>{r.market.sale_to_list}% of list price</li>
                        )}
                        {r.market.months_of_inventory !== null && (
                            <li>
                                {r.market.months_of_inventory} months of homes
                                for sale
                            </li>
                        )}
                        {r.market.ppsf_change !== null && (
                            <li>
                                Price per sq ft{' '}
                                {r.market.ppsf_change >= 0 ? 'up' : 'down'}{' '}
                                {Math.abs(r.market.ppsf_change)}% on the 3
                                months before
                            </li>
                        )}
                    </ul>
                </section>

                <section
                    className="grid gap-3 rounded-lg border p-4"
                    aria-labelledby="net"
                >
                    <h2 id="net" className="font-semibold">
                        Seller net sheet
                    </h2>
                    <form
                        className="grid grid-cols-2 gap-3 sm:grid-cols-6"
                        onSubmit={(e) => {
                            e.preventDefault();
                            patch({ net });
                        }}
                    >
                        {(
                            [
                                ['payoff', 'Mortgage payoff ($)'],
                                ['listing_pct', 'Listing commission %'],
                                ['buyer_pct', "Buyer's agent %"],
                                ['closing_pct', 'Closing costs %'],
                                ['repairs', 'Repairs ($)'],
                                ['concessions', 'Help with buyer costs ($)'],
                            ] as const
                        ).map(([k, label]) => (
                            <label key={k} className="grid gap-1 text-sm">
                                {label}
                                <Input
                                    type="number"
                                    step="0.01"
                                    value={net[k]}
                                    onChange={(e) =>
                                        setNet({
                                            ...net,
                                            [k]: Number(e.target.value),
                                        })
                                    }
                                />
                            </label>
                        ))}
                        <Button
                            variant="outline"
                            className="col-span-2 justify-self-start sm:col-span-6"
                        >
                            Update the net sheet
                        </Button>
                    </form>
                    <p className="text-xs text-muted-foreground">
                        Commissions are negotiable, and offering the buyer's
                        agent anything is the seller's choice.
                    </p>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-muted-foreground">
                                <tr>
                                    <th className="py-1 text-left font-normal">
                                        At a price of
                                    </th>
                                    {(['low', 'likely', 'high'] as const).map(
                                        (k) => (
                                            <th
                                                key={k}
                                                className="py-1 text-right font-normal"
                                            >
                                                {usd(r.net[k].price_cents)}
                                            </th>
                                        ),
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {r.net.likely.lines.map((line, i) => (
                                    <tr key={line.key} className="border-t">
                                        <td className="py-1">{line.label}</td>
                                        {(
                                            ['low', 'likely', 'high'] as const
                                        ).map((k) => (
                                            <td
                                                key={k}
                                                className="py-1 text-right"
                                            >
                                                {usd(r.net[k].lines[i].cents)}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                                <tr className="border-t font-semibold">
                                    <td className="py-1">
                                        Walk away with about
                                    </td>
                                    {(['low', 'likely', 'high'] as const).map(
                                        (k) => (
                                            <td
                                                key={k}
                                                className="py-1 text-right"
                                            >
                                                {usd(r.net[k].net_cents)}
                                            </td>
                                        ),
                                    )}
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section
                    className="grid gap-2 rounded-lg border p-4"
                    aria-labelledby="summary"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="summary" className="font-semibold">
                            Pricing summary for the seller
                        </h2>
                        <div className="flex gap-2">
                            <select
                                className="h-9 rounded-md border bg-background px-2 text-sm"
                                aria-label="Report language"
                                value={cma.lang}
                                onChange={(e) =>
                                    patch({ lang: e.target.value })
                                }
                            >
                                <option value="en">English</option>
                                <option value="es">Español</option>
                            </select>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        `/pricing/cma/${cma.ulid}/summary`,
                                        {},
                                        opts,
                                    )
                                }
                            >
                                {cma.summary
                                    ? 'Write it again'
                                    : 'Write it for me'}
                            </Button>
                        </div>
                    </div>
                    <p className="whitespace-pre-line">
                        {cma.summary ??
                            'Not written yet. A plain summary is used if you share without one.'}
                    </p>
                    {errors.summary && (
                        <p className="text-sm text-red-700">{errors.summary}</p>
                    )}
                </section>
            </div>
        </>
    );
}
