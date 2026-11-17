import { Head, Link, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Prefill = {
    contact?: string;
    name?: string;
    street?: string;
    city?: string;
    state?: string;
    zip?: string;
    listing?: string;
} | null;

const usd = (cents: number) =>
    (cents / 100).toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 0,
    });

/** Pricing (CM1): start a CMA, see past CMAs and buyer estimates, and (for the broker) the default figures. */
export default function PricingIndex({
    cmas,
    sheets,
    sold,
    prefill,
    defaults,
    broker,
}: {
    cmas: {
        ulid: string;
        title: string;
        likely_cents: number;
        views: number;
        shared: boolean;
        updated: string;
    }[];
    sheets: {
        ulid: string;
        title: string;
        monthly_cents: number;
        updated: string;
    }[];
    sold: boolean;
    prefill: Prefill;
    defaults: Record<string, number>;
    broker: boolean;
}) {
    const form = useForm({
        listing: prefill?.listing ?? '',
        contact: prefill?.contact ?? '',
        street: prefill?.street ?? '',
        city: prefill?.city ?? '',
        state: prefill?.state ?? '',
        zip: prefill?.zip ?? '',
        beds: '',
        baths_full: '',
        baths_half: '',
        sqft: '',
        year_built: '',
        lot_sqft: '',
        garage: '',
        pool: '',
        lang: 'en',
    });
    const d = useForm(defaults);
    const field = (
        key: keyof typeof form.data,
        label: string,
        props: React.InputHTMLAttributes<HTMLInputElement> = {},
    ) => (
        <label className="grid gap-1 text-sm">
            {label}
            <Input
                value={form.data[key]}
                onChange={(e) => form.setData(key, e.target.value)}
                {...props}
            />
        </label>
    );

    return (
        <>
            <Head title="CMA and estimates" />
            <div className="grid gap-5 p-4 text-base">
                <PageHeader
                    title="CMA and estimates"
                    description="Price a home from comparable sales, show the seller what they'd walk away with, and work out a buyer's costs."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/pricing/buyer">
                                Buyer cost estimate
                            </Link>
                        </Button>
                    }
                />
                {!sold && (
                    <p className="rounded border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-800 dark:bg-amber-950/30">
                        Your MLS licence doesn't include sold data yet, so CMAs
                        use asking prices of homes for sale. Ask RightAlly to
                        add a back-office or VOW licence for sold comps.
                    </p>
                )}

                <form
                    className="grid gap-3 rounded-lg border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/pricing/cma');
                    }}
                >
                    <h2 className="text-lg font-semibold">
                        New CMA{prefill?.name ? ` for ${prefill.name}` : ''}
                    </h2>
                    {form.data.listing ? (
                        <p className="text-sm">
                            From the listing you picked. Details come from the
                            MLS.
                        </p>
                    ) : (
                        <>
                            <div className="grid gap-3 sm:grid-cols-4">
                                <div className="sm:col-span-2">
                                    {field('street', 'Street address', {
                                        required: true,
                                        autoComplete: 'street-address',
                                    })}
                                </div>
                                {field('city', 'City')}
                                <div className="grid grid-cols-2 gap-2">
                                    {field('state', 'State', { maxLength: 2 })}
                                    {field('zip', 'ZIP', {
                                        required: true,
                                        inputMode: 'numeric',
                                        maxLength: 5,
                                    })}
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
                                {field('sqft', 'Square feet', {
                                    required: true,
                                    inputMode: 'numeric',
                                })}
                                {field('beds', 'Beds', {
                                    inputMode: 'numeric',
                                })}
                                {field('baths_full', 'Full baths', {
                                    inputMode: 'numeric',
                                })}
                                {field('baths_half', 'Half baths', {
                                    inputMode: 'numeric',
                                })}
                                {field('year_built', 'Year built', {
                                    inputMode: 'numeric',
                                })}
                                {field('lot_sqft', 'Lot (sq ft)', {
                                    inputMode: 'numeric',
                                })}
                                {field('garage', 'Garage spaces', {
                                    inputMode: 'numeric',
                                })}
                                <label className="grid gap-1 text-sm">
                                    Pool
                                    <select
                                        className="h-10 rounded-md border bg-background px-3"
                                        value={form.data.pool}
                                        onChange={(e) =>
                                            form.setData('pool', e.target.value)
                                        }
                                    >
                                        <option value="">Not sure</option>
                                        <option value="1">Yes</option>
                                        <option value="0">No</option>
                                    </select>
                                </label>
                            </div>
                        </>
                    )}
                    <div className="flex flex-wrap items-end gap-3">
                        <label className="grid gap-1 text-sm">
                            Report language
                            <select
                                className="h-10 rounded-md border bg-background px-3"
                                value={form.data.lang}
                                onChange={(e) =>
                                    form.setData('lang', e.target.value)
                                }
                            >
                                <option value="en">English</option>
                                <option value="es">Español</option>
                            </select>
                        </label>
                        <Button size="lg" disabled={form.processing}>
                            {form.processing
                                ? 'Finding comps…'
                                : 'Find comps and price it'}
                        </Button>
                    </div>
                    {Object.values(form.errors).map((m) => (
                        <p
                            key={m}
                            role="alert"
                            className="text-sm text-red-700"
                        >
                            {m}
                        </p>
                    ))}
                </form>

                <section className="grid gap-2" aria-labelledby="past">
                    <h2 id="past" className="text-lg font-semibold">
                        Your CMAs
                    </h2>
                    {cmas.length === 0 ? (
                        <EmptyState
                            title="No CMAs yet"
                            description="Your first takes about two minutes."
                        />
                    ) : (
                        <ul className="grid gap-2 sm:grid-cols-2">
                            {cmas.map((c) => (
                                <li key={c.ulid}>
                                    <Link
                                        href={`/pricing/cma/${c.ulid}`}
                                        className="grid gap-1 rounded-lg border p-3 hover:border-primary"
                                    >
                                        <strong>{c.title}</strong>
                                        <span className="text-sm text-muted-foreground">
                                            {c.likely_cents > 0
                                                ? `About ${usd(c.likely_cents)}`
                                                : 'No price yet'}{' '}
                                            · {c.updated}
                                            {c.shared &&
                                                ` · shared${c.views > 0 ? `, opened ${c.views}×` : ', not opened yet'}`}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {sheets.length > 0 && (
                    <section className="grid gap-2" aria-labelledby="sheets">
                        <h2 id="sheets" className="text-lg font-semibold">
                            Buyer estimates
                        </h2>
                        <ul className="grid gap-2 sm:grid-cols-2">
                            {sheets.map((s) => (
                                <li key={s.ulid}>
                                    <Link
                                        href={`/pricing/buyer?sheet=${s.ulid}`}
                                        className="grid gap-1 rounded-lg border p-3 hover:border-primary"
                                    >
                                        <strong>{s.title}</strong>
                                        <span className="text-sm text-muted-foreground">
                                            {usd(s.monthly_cents)} a month ·{' '}
                                            {s.updated}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {broker && (
                    <form
                        className="grid gap-3 rounded-lg border p-4 text-sm"
                        onSubmit={(e) => {
                            e.preventDefault();
                            d.post('/pricing/defaults', {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <h2 className="text-base font-semibold">
                            Default figures (review)
                        </h2>
                        <p className="text-muted-foreground">
                            Starting points for net sheets and buyer estimates.
                            Check them with your title company and lenders.
                        </p>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            {(
                                [
                                    [
                                        'listing_commission_pct',
                                        'Listing commission %',
                                    ],
                                    ['buyer_agent_pct', "Buyer's agent %"],
                                    [
                                        'seller_closing_pct',
                                        'Seller closing costs %',
                                    ],
                                    [
                                        'buyer_closing_pct',
                                        'Buyer closing costs %',
                                    ],
                                    [
                                        'property_tax_pct',
                                        'Property tax % a year',
                                    ],
                                    ['insurance_pct', 'Insurance % a year'],
                                    ['interest_rate', 'Interest rate %'],
                                ] as const
                            ).map(([k, label]) => (
                                <label key={k} className="grid gap-1">
                                    {label}
                                    <Input
                                        type="number"
                                        step="0.01"
                                        value={d.data[k]}
                                        onChange={(e) =>
                                            d.setData(k, Number(e.target.value))
                                        }
                                    />
                                </label>
                            ))}
                        </div>
                        <Button
                            variant="outline"
                            className="justify-self-start"
                            disabled={d.processing}
                        >
                            Save defaults
                        </Button>
                    </form>
                )}
            </div>
        </>
    );
}
