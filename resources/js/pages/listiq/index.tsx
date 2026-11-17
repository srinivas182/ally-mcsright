import { Head, Link, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

/** List-IQ (LI1): listings being made ready. */
export default function ListIqIndex({
    listings,
    deals,
}: {
    listings: {
        ulid: string;
        street: string;
        city: string | null;
        status: string;
        updated: string;
        done: number;
        total: number;
    }[];
    deals: { ulid: string; label: string }[];
}) {
    const form = useForm({
        deal: '',
        street: '',
        city: '',
        state: '',
        zip: '',
        beds: '',
        baths: '',
        sqft: '',
        year_built: '',
        price: '',
    });

    return (
        <>
            <Head title="List-IQ" />
            <div className="grid gap-5 p-4 text-base">
                <PageHeader
                    title="List-IQ"
                    description="Get a listing ready: photos, description, staging and everything the MLS needs, with a checklist."
                />
                <form
                    className="grid gap-3 rounded-lg border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/list-iq');
                    }}
                >
                    <h2 className="text-lg font-semibold">Start a listing</h2>
                    {deals.length > 0 && (
                        <label className="grid gap-1 text-sm">
                            From one of your listing deals
                            <select
                                className="h-10 rounded-md border bg-background px-3"
                                value={form.data.deal}
                                onChange={(e) =>
                                    form.setData('deal', e.target.value)
                                }
                            >
                                <option value="">
                                    Or type an address below
                                </option>
                                {deals.map((d) => (
                                    <option key={d.ulid} value={d.ulid}>
                                        {d.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    {!form.data.deal && (
                        <div className="grid gap-3 sm:grid-cols-4">
                            <label className="grid gap-1 text-sm sm:col-span-2">
                                Street address
                                <Input
                                    value={form.data.street}
                                    onChange={(e) =>
                                        form.setData('street', e.target.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                City
                                <Input
                                    value={form.data.city}
                                    onChange={(e) =>
                                        form.setData('city', e.target.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                ZIP
                                <Input
                                    value={form.data.zip}
                                    inputMode="numeric"
                                    onChange={(e) =>
                                        form.setData('zip', e.target.value)
                                    }
                                />
                            </label>
                        </div>
                    )}
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                        {(
                            [
                                ['beds', 'Beds'],
                                ['baths', 'Baths'],
                                ['sqft', 'Square feet'],
                                ['year_built', 'Year built'],
                                ['price', 'List price ($)'],
                            ] as const
                        ).map(([k, label]) => (
                            <label key={k} className="grid gap-1 text-sm">
                                {label}
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
                    <Button
                        size="lg"
                        className="justify-self-start"
                        disabled={form.processing}
                    >
                        Start
                    </Button>
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
                {listings.length === 0 ? (
                    <EmptyState
                        title="No listings in preparation"
                        description="Start one above; it takes a minute."
                    />
                ) : (
                    <ul className="grid gap-2 sm:grid-cols-2">
                        {listings.map((l) => (
                            <li key={l.ulid}>
                                <Link
                                    href={`/list-iq/${l.ulid}`}
                                    className="grid gap-1 rounded-lg border p-3 hover:border-primary"
                                >
                                    <strong>{l.street}</strong>
                                    <span className="text-sm text-muted-foreground">
                                        {l.status === 'ready'
                                            ? 'Ready to go live'
                                            : `${l.done} of ${l.total} done`}{' '}
                                        · {l.updated}
                                    </span>
                                    <span className="h-2 overflow-hidden rounded-full bg-muted">
                                        <span
                                            className="block h-full bg-primary"
                                            style={{
                                                width: `${(100 * l.done) / Math.max(1, l.total)}%`,
                                            }}
                                        />
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
