import { Head, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Props = {
    registration: {
        status: string;
        status_label: string;
        note: string | null;
        brand: Record<string, string>;
        campaign: Record<string, string>;
        submitted_at: string | null;
    };
    types: Record<string, string>;
    statuses: Record<string, string>;
};

/** MK1: guided A2P 10DLC registration, so carriers deliver the brokerage's texts. */
export default function Texting({ registration, types }: Props) {
    const b = registration.brand;
    const c = registration.campaign;
    const locked = ['submitted', 'approved'].includes(registration.status);
    const form = useForm({
        legal_name: b.legal_name ?? '',
        business_type: b.business_type ?? '',
        ein: b.ein ?? '',
        website: b.website ?? '',
        street: b.street ?? '',
        city: b.city ?? '',
        state: b.state ?? '',
        zip: b.zip ?? '',
        contact_name: b.contact_name ?? '',
        contact_email: b.contact_email ?? '',
        contact_phone: b.contact_phone ?? '',
        description: c.description ?? '',
        sample_1: c.sample_1 ?? '',
        sample_2: c.sample_2 ?? '',
        opt_in: c.opt_in ?? '',
        volume: c.volume ?? 'under_2000',
        submit: false,
    });
    const field = (
        key: keyof typeof form.data,
        label: string,
        hint?: string,
    ) => (
        <label className="grid gap-1">
            {label}
            <Input
                disabled={locked}
                value={String(form.data[key])}
                onChange={(e) => form.setData(key, e.target.value as never)}
            />
            {hint && (
                <span className="text-sm text-muted-foreground">{hint}</span>
            )}
            {form.errors[key] && (
                <span className="text-destructive">{form.errors[key]}</span>
            )}
        </label>
    );
    const area = (
        key: 'description' | 'sample_1' | 'sample_2' | 'opt_in',
        label: string,
    ) => (
        <label className="grid gap-1">
            {label}
            <textarea
                disabled={locked}
                className="min-h-20 rounded-md border bg-background p-2"
                value={form.data[key]}
                onChange={(e) => form.setData(key, e.target.value)}
            />
            {form.errors[key] && (
                <span className="text-destructive">{form.errors[key]}</span>
            )}
        </label>
    );
    const send = (submit: boolean) => {
        form.transform((d) => ({ ...d, submit }));
        form.post('/settings/texting', { preserveScroll: true });
    };

    return (
        <>
            <Head title="Texting registration" />
            <div className="mx-auto grid w-full max-w-3xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Texting registration"
                    description="US phone carriers block business texts unless the business is registered (this is called A2P 10DLC). Answer a few questions; we wrote the rest to match how RightAlly sends texts."
                />
                <p className="flex flex-wrap items-center gap-2">
                    <Badge
                        variant={
                            registration.status === 'approved'
                                ? 'default'
                                : registration.status === 'rejected'
                                  ? 'destructive'
                                  : 'outline'
                        }
                    >
                        {registration.status_label}
                    </Badge>
                    {registration.note && (
                        <span className="text-muted-foreground">
                            {registration.note}
                        </span>
                    )}
                </p>
                <form
                    className="grid gap-6"
                    onSubmit={(e) => {
                        e.preventDefault();
                        send(true);
                    }}
                >
                    <section className="grid gap-3 rounded-lg border p-4">
                        <h2 className="font-semibold">1. Your business</h2>
                        {field(
                            'legal_name',
                            'Legal business name',
                            'Exactly as on your IRS letter.',
                        )}
                        <label className="grid gap-1">
                            Kind of business
                            <select
                                disabled={locked}
                                className="rounded-md border bg-background p-2"
                                value={form.data.business_type}
                                onChange={(e) =>
                                    form.setData(
                                        'business_type',
                                        e.target.value,
                                    )
                                }
                            >
                                <option value="">Choose…</option>
                                {Object.entries(types).map(([k, l]) => (
                                    <option key={k} value={k}>
                                        {l}
                                    </option>
                                ))}
                            </select>
                            {form.errors.business_type && (
                                <span className="text-destructive">
                                    {form.errors.business_type}
                                </span>
                            )}
                        </label>
                        {field(
                            'ein',
                            'EIN (tax ID)',
                            'Kept encrypted and never shown again.',
                        )}
                        {field(
                            'website',
                            'Website',
                            'Carriers check that it shows your business name and a privacy page.',
                        )}
                        <div className="grid gap-3 sm:grid-cols-2">
                            {field('street', 'Street address')}
                            {field('city', 'City')}
                            {field('state', 'State (two letters)')}
                            {field('zip', 'ZIP')}
                        </div>
                    </section>
                    <section className="grid gap-3 rounded-lg border p-4">
                        <h2 className="font-semibold">
                            2. Who carriers contact
                        </h2>
                        <div className="grid gap-3 sm:grid-cols-3">
                            {field('contact_name', 'Name')}
                            {field('contact_email', 'Email')}
                            {field('contact_phone', 'Phone')}
                        </div>
                    </section>
                    <section className="grid gap-3 rounded-lg border p-4">
                        <h2 className="font-semibold">
                            3. How you text (already written for you)
                        </h2>
                        {area('description', 'What your texts are about')}
                        {area('sample_1', 'Sample message 1')}
                        {area('sample_2', 'Sample message 2')}
                        {area('opt_in', 'How people agree to texts')}
                    </section>
                    {!locked && (
                        <div className="flex flex-wrap gap-2">
                            <Button disabled={form.processing}>
                                Send for registration
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={form.processing}
                                onClick={() => send(false)}
                            >
                                Save and finish later
                            </Button>
                        </div>
                    )}
                </form>
            </div>
        </>
    );
}
