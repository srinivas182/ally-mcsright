import { Head, router, useForm } from '@inertiajs/react';
import { useEffect } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Party = { role: string; name: string; email: string };
type Props = {
    deal: { ulid: string };
    proposal: {
        reading: boolean;
        read_failed: boolean;
        type: string;
        street: string;
        city: string;
        state_code: string;
        postal_code: string;
        price: string;
        contract_date: string;
        closing_date: string;
        property_type: string;
        financing: string;
        parties: Party[];
        facts: { label: string; value: string; page: number; quote: string }[];
    };
    types: string[];
    propertyTypes: Record<string, string>;
    financing: Record<string, string>;
};

const select = 'rounded border bg-background px-2 py-1';
const roles = [
    'buyer',
    'seller',
    'co_op_agent',
    'lender',
    'title',
    'escrow',
    'attorney',
    'inspector',
    'other',
];

/** Set up a new deal from what Deal-IQ read or heard (specs/ai/02, §2): nothing is assumed silently. */
export default function Setup({
    deal,
    proposal: p,
    types,
    propertyTypes,
    financing,
}: Props) {
    const form = useForm({
        type: p.type,
        street: p.street,
        city: p.city,
        state_code: p.state_code,
        postal_code: p.postal_code,
        price: p.price,
        contract_date: p.contract_date,
        closing_date: p.closing_date,
        property_type: p.property_type,
        financing: p.financing,
        parties: p.parties,
    });

    // While the contract is being read, check back every few seconds.
    useEffect(() => {
        if (!p.reading) {
            return;
        }
        const t = setTimeout(() => router.reload(), 3000);
        return () => clearTimeout(t);
    }, [p.reading]);

    useEffect(() => {
        if (!p.reading) {
            form.setData({
                type: p.type,
                street: p.street,
                city: p.city,
                state_code: p.state_code,
                postal_code: p.postal_code,
                price: p.price,
                contract_date: p.contract_date,
                closing_date: p.closing_date,
                property_type: p.property_type,
                financing: p.financing,
                parties: p.parties,
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [p.reading]);

    const field = (
        k:
            | 'street'
            | 'city'
            | 'state_code'
            | 'postal_code'
            | 'price'
            | 'contract_date'
            | 'closing_date',
        label: string,
        type = 'text',
    ) => (
        <label className="grid gap-1">
            <span className="text-muted-foreground">{label}</span>
            <Input
                type={type}
                value={form.data[k]}
                onChange={(e) => form.setData(k, e.target.value)}
            />
            {form.errors[k] && (
                <span className="text-destructive">{form.errors[k]}</span>
            )}
        </label>
    );

    return (
        <>
            <Head title="Set up the deal" />
            <div className="flex max-w-3xl flex-col gap-4 p-4 text-sm md:p-6">
                <PageHeader
                    title="Set up the deal"
                    description="Check what Deal-IQ found. Change anything that's wrong, then confirm."
                />
                {p.reading ? (
                    <p role="status" className="rounded-lg border p-4">
                        Reading the contract… this usually takes under a minute.
                    </p>
                ) : (
                    <form
                        className="grid gap-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`/deals/${deal.ulid}/setup`);
                        }}
                    >
                        {p.read_failed && (
                            <p className="text-destructive">
                                We couldn't read that file. Fill in the details
                                below, and upload a clearer copy later.
                            </p>
                        )}
                        <section className="grid gap-3 rounded-lg border p-4 md:grid-cols-2">
                            <label className="grid gap-1">
                                <span className="text-muted-foreground">
                                    Kind of deal
                                </span>
                                <select
                                    className={select}
                                    value={form.data.type}
                                    onChange={(e) =>
                                        form.setData('type', e.target.value)
                                    }
                                >
                                    {types.map((t) => (
                                        <option key={t} value={t}>
                                            {t.replace('_', ' ')}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            {field('street', 'Street')}
                            {field('city', 'City')}
                            {field('state_code', 'State (2 letters)')}
                            {field('postal_code', 'ZIP code')}
                            {field('price', 'Price ($)', 'number')}
                            {field('contract_date', 'Contract date', 'date')}
                            {field('closing_date', 'Closing date', 'date')}
                            <label className="grid gap-1">
                                <span className="text-muted-foreground">
                                    Type of home
                                </span>
                                <select
                                    className={select}
                                    value={form.data.property_type}
                                    onChange={(e) =>
                                        form.setData(
                                            'property_type',
                                            e.target.value,
                                        )
                                    }
                                >
                                    <option value="">Choose…</option>
                                    {Object.entries(propertyTypes).map(
                                        ([k, l]) => (
                                            <option key={k} value={k}>
                                                {l}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </label>
                            <label className="grid gap-1">
                                <span className="text-muted-foreground">
                                    Financing
                                </span>
                                <select
                                    className={select}
                                    value={form.data.financing}
                                    onChange={(e) =>
                                        form.setData(
                                            'financing',
                                            e.target.value,
                                        )
                                    }
                                >
                                    <option value="">Choose…</option>
                                    {Object.entries(financing).map(([k, l]) => (
                                        <option key={k} value={k}>
                                            {l}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </section>
                        <section className="grid gap-2 rounded-lg border p-4">
                            <p className="font-medium">People on the deal</p>
                            {form.data.parties.map((party, i) => (
                                <span key={i} className="flex flex-wrap gap-2">
                                    <select
                                        aria-label="Role"
                                        className={select}
                                        value={party.role}
                                        onChange={(e) =>
                                            form.setData(
                                                'parties',
                                                form.data.parties.map((x, j) =>
                                                    j === i
                                                        ? {
                                                              ...x,
                                                              role: e.target
                                                                  .value,
                                                          }
                                                        : x,
                                                ),
                                            )
                                        }
                                    >
                                        {roles.map((r) => (
                                            <option key={r} value={r}>
                                                {r.replace('_', '-')}
                                            </option>
                                        ))}
                                    </select>
                                    <Input
                                        aria-label="Name"
                                        className="w-48"
                                        value={party.name}
                                        onChange={(e) =>
                                            form.setData(
                                                'parties',
                                                form.data.parties.map((x, j) =>
                                                    j === i
                                                        ? {
                                                              ...x,
                                                              name: e.target
                                                                  .value,
                                                          }
                                                        : x,
                                                ),
                                            )
                                        }
                                    />
                                    <Input
                                        aria-label="Email"
                                        className="w-56"
                                        type="email"
                                        placeholder="Email (for messages and upload links)"
                                        value={party.email}
                                        onChange={(e) =>
                                            form.setData(
                                                'parties',
                                                form.data.parties.map((x, j) =>
                                                    j === i
                                                        ? {
                                                              ...x,
                                                              email: e.target
                                                                  .value,
                                                          }
                                                        : x,
                                                ),
                                            )
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            form.setData(
                                                'parties',
                                                form.data.parties.filter(
                                                    (_, j) => j !== i,
                                                ),
                                            )
                                        }
                                    >
                                        Remove
                                    </Button>
                                </span>
                            ))}
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="justify-self-start"
                                onClick={() =>
                                    form.setData('parties', [
                                        ...form.data.parties,
                                        { role: 'lender', name: '', email: '' },
                                    ])
                                }
                            >
                                Add someone
                            </Button>
                        </section>
                        {p.facts.length > 0 && (
                            <details className="rounded-lg border p-4">
                                <summary className="cursor-pointer font-medium">
                                    What Deal-IQ read, and where
                                </summary>
                                <ul className="mt-2 grid gap-1">
                                    {p.facts.map((f) => (
                                        <li key={f.label}>
                                            <strong>{f.label}:</strong>{' '}
                                            {f.value}{' '}
                                            <span className="text-muted-foreground">
                                                (page {f.page}: “{f.quote}”)
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </details>
                        )}
                        <Button
                            type="submit"
                            className="justify-self-start"
                            disabled={form.processing}
                        >
                            Confirm and set up the deal
                        </Button>
                    </form>
                )}
            </div>
        </>
    );
}

Setup.layout = { breadcrumbs: [{ title: 'Deals', href: '/deals' }] };
