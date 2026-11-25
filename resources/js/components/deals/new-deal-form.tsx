import { useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export const typeLabel: Record<string, string> = {
    listing: 'Listing',
    buyer: 'Buyer',
    dual: 'Dual',
    lease: 'Lease',
    referral: 'Referral',
    new_construction: 'New construction',
};

/** A deal typed in by hand (address, kind, price, agent). It starts in the CRM pipeline, not under contract yet. */
export function NewDealForm({
    canCreateFor,
    contact = '',
    type = 'buyer',
}: {
    canCreateFor: { ulid: string; name: string }[];
    contact?: string;
    /** The kind of deal picked to start with (PG3: "Add a listing" starts on Listing). */
    type?: string;
}) {
    const form = useForm({
        type,
        street: '',
        city: '',
        state_code: '',
        postal_code: '',
        mls_number: '',
        price: '',
        agent: '',
        buyer_agent: '',
        contact,
    });

    return (
        <form
            className="grid gap-3 rounded-lg border bg-card p-4 text-sm md:grid-cols-4"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/deals');
            }}
        >
            <label className="grid gap-1">
                Kind of deal
                <select
                    className="h-10 rounded-md border bg-background px-2"
                    value={form.data.type}
                    onChange={(e) => form.setData('type', e.target.value)}
                >
                    {Object.entries(typeLabel).map(([v, l]) => (
                        <option key={v} value={v}>
                            {l}
                        </option>
                    ))}
                </select>
            </label>
            <label className="grid gap-1 md:col-span-2">
                Street address
                <Input
                    placeholder="123 Main St"
                    value={form.data.street}
                    onChange={(e) => form.setData('street', e.target.value)}
                />
            </label>
            <label className="grid gap-1">
                City
                <Input
                    value={form.data.city}
                    onChange={(e) => form.setData('city', e.target.value)}
                />
            </label>
            <label className="grid gap-1">
                State
                <Input
                    placeholder="TX"
                    maxLength={2}
                    value={form.data.state_code}
                    onChange={(e) =>
                        form.setData('state_code', e.target.value.toUpperCase())
                    }
                />
            </label>
            <label className="grid gap-1">
                ZIP
                <Input
                    value={form.data.postal_code}
                    onChange={(e) =>
                        form.setData('postal_code', e.target.value)
                    }
                />
            </label>
            <label className="grid gap-1">
                MLS number (optional)
                <Input
                    value={form.data.mls_number}
                    onChange={(e) => form.setData('mls_number', e.target.value)}
                />
            </label>
            <label className="grid gap-1">
                Price ($)
                <Input
                    type="number"
                    inputMode="numeric"
                    value={form.data.price}
                    onChange={(e) => form.setData('price', e.target.value)}
                />
            </label>
            {canCreateFor.length > 0 && (
                <label className="grid gap-1">
                    Agent
                    <select
                        className="h-10 rounded-md border bg-background px-2"
                        value={form.data.agent}
                        onChange={(e) => form.setData('agent', e.target.value)}
                    >
                        <option value="">Me</option>
                        {canCreateFor.map((a) => (
                            <option key={a.ulid} value={a.ulid}>
                                {a.name}
                            </option>
                        ))}
                    </select>
                </label>
            )}
            {form.data.type === 'dual' && (
                <p className="self-center text-muted-foreground md:col-span-2">
                    Dual: your brokerage is on both sides. A linked buyer side
                    is made with it, with its own commission and agent.
                </p>
            )}
            {form.data.type === 'dual' && canCreateFor.length > 0 && (
                <label className="grid gap-1">
                    Buyer's agent
                    <select
                        className="h-10 rounded-md border bg-background px-2"
                        value={form.data.buyer_agent}
                        onChange={(e) =>
                            form.setData('buyer_agent', e.target.value)
                        }
                    >
                        <option value="">Same agent</option>
                        {canCreateFor.map((a) => (
                            <option key={a.ulid} value={a.ulid}>
                                {a.name}
                            </option>
                        ))}
                    </select>
                </label>
            )}
            {form.data.contact && (
                <span className="self-center text-muted-foreground md:col-span-2">
                    The client from your contacts is added as a party.
                </span>
            )}
            {Object.values(form.errors).length > 0 && (
                <p role="alert" className="text-destructive md:col-span-4">
                    {Object.values(form.errors)[0]}
                </p>
            )}
            <div className="md:col-span-4">
                <Button type="submit" disabled={form.processing}>
                    Add the deal
                </Button>
            </div>
        </form>
    );
}
