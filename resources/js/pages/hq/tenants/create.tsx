import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Props = { platformDomain: string };

function toSlug(name: string): string {
    return name
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 40);
}

export default function CreateTenant({ platformDomain }: Props) {
    // One key per form visit: double submits provision only once.
    const [idempotencyKey] = useState(() => crypto.randomUUID());
    const [slugEdited, setSlugEdited] = useState(false);

    const form = useForm({
        type: 'brokerage',
        name: '',
        slug: '',
        state_code: '',
        owner_name: '',
        owner_email: '',
        idempotency_key: idempotencyKey,
    });

    return (
        <>
            <Head title="New tenant" />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4 md:p-6">
                <header>
                    <h1 className="text-2xl font-semibold">New tenant</h1>
                    <p className="text-sm text-muted-foreground">
                        The tenant goes live immediately on its RightAlly
                        address.
                    </p>
                </header>

                <form
                    className="grid gap-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/hq/tenants');
                    }}
                >
                    <div className="grid gap-2">
                        <Label>Type</Label>
                        <RadioGroup
                            value={form.data.type}
                            onValueChange={(type) => form.setData('type', type)}
                            className="flex gap-6"
                        >
                            <div className="flex items-center gap-2">
                                <RadioGroupItem
                                    value="brokerage"
                                    id="type-brokerage"
                                />
                                <Label htmlFor="type-brokerage">
                                    Brokerage
                                </Label>
                            </div>
                            <div className="flex items-center gap-2">
                                <RadioGroupItem value="solo" id="type-solo" />
                                <Label htmlFor="type-solo">Solo agent</Label>
                            </div>
                        </RadioGroup>
                    </div>

                    <FormField label="Name" error={form.errors.name} required>
                        {(control) => (
                            <Input
                                {...control}
                                value={form.data.name}
                                onChange={(event) => {
                                    form.setData('name', event.target.value);
                                    if (!slugEdited) {
                                        form.setData(
                                            'slug',
                                            toSlug(event.target.value),
                                        );
                                    }
                                }}
                            />
                        )}
                    </FormField>

                    <FormField
                        label="Address"
                        help={`${form.data.slug || 'your-address'}.${platformDomain}`}
                        error={form.errors.slug}
                        required
                    >
                        {(control) => (
                            <Input
                                {...control}
                                value={form.data.slug}
                                onChange={(event) => {
                                    setSlugEdited(true);
                                    form.setData(
                                        'slug',
                                        event.target.value.toLowerCase(),
                                    );
                                }}
                            />
                        )}
                    </FormField>

                    <FormField
                        label="State"
                        help="Two-letter code, e.g. MS"
                        error={form.errors.state_code}
                    >
                        {(control) => (
                            <Input
                                {...control}
                                maxLength={2}
                                className="w-24 uppercase"
                                value={form.data.state_code}
                                onChange={(event) =>
                                    form.setData(
                                        'state_code',
                                        event.target.value.toUpperCase(),
                                    )
                                }
                            />
                        )}
                    </FormField>

                    <div className="grid gap-5 md:grid-cols-2">
                        <FormField
                            label="Owner name"
                            error={form.errors.owner_name}
                            required
                        >
                            {(control) => (
                                <Input
                                    {...control}
                                    value={form.data.owner_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'owner_name',
                                            event.target.value,
                                        )
                                    }
                                />
                            )}
                        </FormField>
                        <FormField
                            label="Owner email"
                            help="Receives the invitation to sign in"
                            error={form.errors.owner_email}
                            required
                        >
                            {(control) => (
                                <Input
                                    {...control}
                                    type="email"
                                    value={form.data.owner_email}
                                    onChange={(event) =>
                                        form.setData(
                                            'owner_email',
                                            event.target.value,
                                        )
                                    }
                                />
                            )}
                        </FormField>
                    </div>

                    <div className="flex justify-end">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Create tenant
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

CreateTenant.layout = {
    breadcrumbs: [
        { title: 'Tenants', href: '/hq/tenants' },
        { title: 'New tenant', href: '/hq/tenants/create' },
    ],
};
