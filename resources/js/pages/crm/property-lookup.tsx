import { Head, useForm } from '@inertiajs/react';
import { FeatureOff } from '@/components/feature-off/feature-off';
import { SearchCheck } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Data = {
    source: string;
    address?: string;
    owners: string[];
    owner_occupied: boolean | null;
    mailing?: { street?: string; city?: string; state?: string; zip?: string };
    beds?: number | null;
    baths?: number | null;
    sqft?: number | null;
    year_built?: number | null;
    last_sale?: { price: number | null; date: string | null };
    value?: { mid: number | null; low: number | null; high: number | null };
};

const money = (n: number | null | undefined) =>
    n == null
        ? '—'
        : n.toLocaleString('en-US', {
              style: 'currency',
              currency: 'USD',
              maximumFractionDigits: 0,
          });

/**
 * Property lookup (specs/connect/21): facts, last sale and value range from ATTOM, or owners and their mailing
 * address from BatchData, for one address. Paid with credits; free to look again for 30 days. No phones or emails.
 */
export default function PropertyLookup({
    available,
    credits,
    result,
}: {
    available: { attom: boolean; batchdata: boolean };
    credits: { attom: number; batchdata: number };
    result: {
        vendor: string;
        data: Data;
        fetched_at: string;
        charged: boolean;
    } | null;
}) {
    const form = useForm({
        vendor: available.attom ? 'attom' : 'batchdata',
        street: '',
        city: '',
        state: '',
        zip: '',
    });
    if (!available.attom && !available.batchdata) {
        return (
            <div className="mx-auto grid w-full max-w-6xl gap-5 p-4 md:p-6">
                <Head title="Property lookup" />
                <FeatureOff
                    feature="property_lookup"
                    icon={SearchCheck}
                    title="Property lookups"
                    does="Owners, last sale and a value range for any address."
                    points={[
                        'Who owns the home',
                        'When it last sold, and for how much',
                        'A value range, paid with credits',
                    ]}
                />
            </div>
        );
    }
    const d = result?.data;

    return (
        <>
            <Head title="Property lookup" />
            <div className="grid max-w-2xl gap-6 p-4 text-base">
                <PageHeader
                    title="Property lookup"
                    description="Owners, last sale and a value range for any address. Paid with credits; looking up the same address again within 30 days is free."
                />
                <form
                    className="grid gap-3 rounded-xl border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/property-lookup');
                    }}
                >
                    <fieldset className="grid gap-2">
                        <legend className="mb-1">What to look up</legend>
                        {available.attom && (
                            <label className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    className="size-5"
                                    checked={form.data.vendor === 'attom'}
                                    onChange={() =>
                                        form.setData('vendor', 'attom')
                                    }
                                />
                                Facts, last sale and value range (ATTOM,{' '}
                                {credits.attom} credits)
                            </label>
                        )}
                        {available.batchdata && (
                            <label className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    className="size-5"
                                    checked={form.data.vendor === 'batchdata'}
                                    onChange={() =>
                                        form.setData('vendor', 'batchdata')
                                    }
                                />
                                Owners and mailing address (BatchData,{' '}
                                {credits.batchdata} credits)
                            </label>
                        )}
                    </fieldset>
                    <label className="grid gap-1">
                        Street address
                        <Input
                            autoComplete="street-address"
                            value={form.data.street}
                            onChange={(e) =>
                                form.setData('street', e.target.value)
                            }
                        />
                        {form.errors.street && (
                            <span className="text-destructive">
                                {form.errors.street}
                            </span>
                        )}
                    </label>
                    <div className="grid grid-cols-3 gap-2">
                        <label className="grid gap-1">
                            City
                            <Input
                                value={form.data.city}
                                onChange={(e) =>
                                    form.setData('city', e.target.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1">
                            State
                            <Input
                                maxLength={2}
                                value={form.data.state}
                                onChange={(e) =>
                                    form.setData('state', e.target.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1">
                            ZIP
                            <Input
                                inputMode="numeric"
                                value={form.data.zip}
                                onChange={(e) =>
                                    form.setData('zip', e.target.value)
                                }
                            />
                        </label>
                    </div>
                    {(form.errors as Record<string, string>).vendor && (
                        <p className="text-destructive">
                            {(form.errors as Record<string, string>).vendor}
                        </p>
                    )}
                    <div>
                        <Button disabled={form.processing}>Look it up</Button>
                    </div>
                </form>
                {d && (
                    <section className="grid gap-2 rounded-xl border p-4 text-sm">
                        <h2 className="font-medium">
                            {d.address || 'Result'} ({d.source}
                            {result.charged
                                ? ''
                                : ', from your last 30 days, free'}
                            )
                        </h2>
                        <p>
                            Owners:{' '}
                            {d.owners.length > 0
                                ? d.owners.join(' and ')
                                : 'not shown'}
                        </p>
                        {d.owner_occupied !== null && (
                            <p>
                                {d.owner_occupied
                                    ? 'Lives in the home'
                                    : 'Lives elsewhere'}
                            </p>
                        )}
                        {d.mailing && Object.keys(d.mailing).length > 0 && (
                            <p>
                                Mailing address:{' '}
                                {[
                                    d.mailing.street,
                                    d.mailing.city,
                                    d.mailing.state,
                                    d.mailing.zip,
                                ]
                                    .filter(Boolean)
                                    .join(', ')}
                            </p>
                        )}
                        {d.value && (
                            <p>
                                Value range: {money(d.value.low)} to{' '}
                                {money(d.value.high)} (about{' '}
                                {money(d.value.mid)}). An automated estimate,
                                not an appraisal.
                            </p>
                        )}
                        {d.last_sale && (
                            <p>
                                Last sale: {money(d.last_sale.price)}
                                {d.last_sale.date
                                    ? ` on ${d.last_sale.date}`
                                    : ''}
                            </p>
                        )}
                        {d.beds != null && (
                            <p>
                                {d.beds} beds · {d.baths ?? '—'} baths ·{' '}
                                {d.sqft?.toLocaleString() ?? '—'} sq ft
                                {d.year_built ? ` · built ${d.year_built}` : ''}
                            </p>
                        )}
                        <p className="text-muted-foreground">
                            For your own use. Don't publish it, and don't text
                            or call owners who haven't asked to hear from you.
                        </p>
                    </section>
                )}
            </div>
        </>
    );
}
