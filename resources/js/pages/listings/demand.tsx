import { Head, Link, router, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Report = {
    ulid: string;
    address: string;
    zip: string;
    inputs: {
        price: number;
        beds: number | null;
        baths: number | null;
        sqft: number | null;
    };
    agents_told: boolean;
    place: { city: string; state: string };
    price: number;
    buyers: number;
    curve: { price: number; buyers: number }[];
    agents: { id: number; name: string; buyers: number }[];
    shoppers: number;
    favourited: number;
    competition: {
        total: number;
        median_price: number | null;
        homes: {
            ulid: string;
            label: string;
            price: number | null;
            beds: number | null;
            sqft: number | null;
            photo: string | null;
            attribution: string | null;
        }[];
    };
};

const money = (n: number) =>
    n.toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 0,
    });

/**
 * Buyer demand before listing (specs/grow/10): how many of the brokerage's own buyers want a home like this, at
 * each price, through which agents, and what it competes with. Counts only, never people.
 */
export default function Demand({
    reports,
    report,
}: {
    reports: {
        ulid: string;
        address: string;
        zip: string;
        buyers: number;
        at: string;
    }[];
    report: Report | null;
}) {
    const form = useForm({
        address: '',
        zip: '',
        price: '',
        beds: '',
        baths: '',
        sqft: '',
    });
    const max = report ? Math.max(1, ...report.curve.map((c) => c.buyers)) : 1;

    return (
        <>
            <Head title="Buyer demand" />
            <div className="grid max-w-3xl gap-6 p-4 text-base">
                <PageHeader
                    title="Buyer demand"
                    description="Before the listing appointment: how many of your brokerage's buyers want a home like this, at each price. Counts only, never names."
                />

                <form
                    className="grid gap-3 rounded-lg border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/listings/demand');
                    }}
                >
                    <div className="grid gap-3 sm:grid-cols-[2fr_1fr]">
                        <label className="grid gap-1">
                            Street address
                            <Input
                                value={form.data.address}
                                onChange={(e) =>
                                    form.setData('address', e.target.value)
                                }
                                autoComplete="street-address"
                            />
                            {form.errors.address && (
                                <span className="text-destructive">
                                    {form.errors.address}
                                </span>
                            )}
                        </label>
                        <label className="grid gap-1">
                            ZIP code
                            <Input
                                inputMode="numeric"
                                maxLength={5}
                                value={form.data.zip}
                                onChange={(e) =>
                                    form.setData('zip', e.target.value)
                                }
                            />
                            {form.errors.zip && (
                                <span className="text-destructive">
                                    {form.errors.zip}
                                </span>
                            )}
                        </label>
                    </div>
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <label className="grid gap-1">
                            Expected price
                            <Input
                                inputMode="numeric"
                                placeholder="$450,000"
                                value={form.data.price}
                                onChange={(e) =>
                                    form.setData('price', e.target.value)
                                }
                            />
                        </label>
                        {(['beds', 'baths', 'sqft'] as const).map((k) => (
                            <label key={k} className="grid gap-1">
                                {k === 'sqft'
                                    ? 'Square feet'
                                    : k === 'beds'
                                      ? 'Bedrooms'
                                      : 'Bathrooms'}
                                <Input
                                    inputMode="numeric"
                                    value={form.data[k]}
                                    onChange={(e) =>
                                        form.setData(k, e.target.value)
                                    }
                                />
                            </label>
                        ))}
                    </div>
                    {form.errors.price && (
                        <p className="text-destructive">{form.errors.price}</p>
                    )}
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Counting…' : 'See buyer demand'}
                        </Button>
                    </div>
                </form>

                {report && (
                    <section className="grid gap-4" aria-label="Report">
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 className="text-lg font-semibold">
                                {report.address}, {report.zip} at{' '}
                                {money(report.inputs.price)}
                            </h2>
                            <div className="flex gap-2">
                                <Button variant="outline" asChild>
                                    <a
                                        href={`/listings/demand/${report.ulid}/print`}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        Report for the seller
                                    </a>
                                </Button>
                                <Button variant="outline" asChild>
                                    <a
                                        href={`/listings/demand/${report.ulid}/print?lang=es`}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        En español
                                    </a>
                                </Button>
                            </div>
                        </div>
                        <p className="text-4xl font-bold">
                            {report.buyers.toLocaleString()}
                            <span className="ml-2 text-base font-normal text-muted-foreground">
                                buyers want a home like this
                            </span>
                        </p>
                        <p className="text-muted-foreground">
                            {report.shoppers.toLocaleString()} people looked at
                            similar homes on your website in the last 90 days
                            {report.favourited > 0
                                ? `; ${report.favourited.toLocaleString()} saved them`
                                : ''}
                            .
                        </p>
                        <div className="grid gap-1">
                            <h3 className="font-medium">Demand by price</h3>
                            <ul className="grid gap-1">
                                {report.curve.map((c) => (
                                    <li
                                        key={c.price}
                                        className="grid grid-cols-[6rem_3rem_1fr] items-center gap-2 text-sm"
                                    >
                                        <span>{money(c.price)}</span>
                                        <span className="text-right">
                                            {c.buyers}
                                        </span>
                                        <span
                                            className="h-3 rounded bg-primary"
                                            style={{
                                                width: `${Math.round((c.buyers * 100) / max)}%`,
                                            }}
                                            aria-hidden
                                        />
                                    </li>
                                ))}
                            </ul>
                        </div>
                        <div className="grid gap-2">
                            <h3 className="font-medium">
                                Agents with matching buyers
                            </h3>
                            {report.agents.length === 0 ? (
                                <p className="text-muted-foreground">
                                    None of your brokerage's agents hold
                                    matching buyers yet.
                                </p>
                            ) : (
                                <>
                                    <ul className="grid gap-1">
                                        {report.agents.map((a) => (
                                            <li key={a.id}>
                                                {a.name}: {a.buyers}{' '}
                                                {a.buyers === 1
                                                    ? 'buyer'
                                                    : 'buyers'}
                                            </li>
                                        ))}
                                    </ul>
                                    <div>
                                        <Button
                                            disabled={report.agents_told}
                                            onClick={() =>
                                                router.post(
                                                    `/listings/demand/${report.ulid}/tell`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {report.agents_told
                                                ? 'Agents told'
                                                : 'Tell these agents it’s coming'}
                                        </Button>
                                    </div>
                                </>
                            )}
                        </div>
                        <div className="grid gap-2">
                            <h3 className="font-medium">
                                Similar homes for sale now (
                                {report.competition.total})
                                {report.competition.median_price !== null &&
                                    ` · median ${money(report.competition.median_price)}`}
                            </h3>
                            <ul className="grid gap-2">
                                {report.competition.homes.map((h) => (
                                    <li
                                        key={h.ulid}
                                        className="flex gap-3 text-sm"
                                    >
                                        {h.photo && (
                                            <img
                                                src={h.photo}
                                                alt={h.label}
                                                className="h-14 w-20 rounded object-cover"
                                            />
                                        )}
                                        <span>
                                            <Link
                                                href={`/listings/${h.ulid}`}
                                                className="font-medium underline"
                                            >
                                                {h.price !== null
                                                    ? money(h.price)
                                                    : ''}{' '}
                                                {h.label}
                                            </Link>
                                            <br />
                                            <span className="text-muted-foreground">
                                                {h.attribution}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </section>
                )}

                <section className="grid gap-2">
                    <h2 className="font-medium">Your recent reports</h2>
                    {reports.length === 0 ? (
                        <EmptyState
                            title="No reports yet"
                            description="Run one before your next listing appointment."
                        />
                    ) : (
                        <ul className="grid gap-1">
                            {reports.map((r) => (
                                <li key={r.ulid}>
                                    <Link
                                        href={`/listings/demand?report=${r.ulid}`}
                                        className="underline"
                                    >
                                        {r.address}, {r.zip}
                                    </Link>{' '}
                                    · {r.buyers} buyers
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}
