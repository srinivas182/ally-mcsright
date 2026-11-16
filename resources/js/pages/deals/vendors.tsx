import { Head, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Vendor = {
    ulid: string;
    category: string;
    name: string;
    email: string | null;
    phone: string | null;
    website: string | null;
    note: string | null;
    show_clients: boolean;
};
type Props = {
    vendors: Vendor[];
    categories: Record<string, string>;
    canEdit: boolean;
};

/** The brokerage's preferred vendors: for booking appointments and for clients' moving checklists (specs/ai/02, DQ5b). */
export default function Vendors({ vendors, categories, canEdit }: Props) {
    const form = useForm({
        category: 'inspector',
        name: '',
        email: '',
        phone: '',
        website: '',
        note: '',
        show_clients: true,
    });

    return (
        <>
            <Head title="Preferred vendors" />
            <div className="flex flex-col gap-4 p-4 text-sm md:p-6">
                <PageHeader
                    title="Preferred vendors"
                    description="Used when booking inspections, appraisals and surveys, and shown to clients in their moving checklist (when you choose)."
                />
                {canEdit && (
                    <form
                        className="grid gap-2 rounded-lg border p-4 md:grid-cols-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/deals/vendors', {
                                preserveScroll: true,
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        <select
                            aria-label="What they do"
                            className="rounded border bg-background px-2 py-1"
                            value={form.data.category}
                            onChange={(e) =>
                                form.setData('category', e.target.value)
                            }
                        >
                            {Object.entries(categories).map(([k, l]) => (
                                <option key={k} value={k}>
                                    {l}
                                </option>
                            ))}
                        </select>
                        <Input
                            aria-label="Name"
                            placeholder="Name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Email"
                            type="email"
                            placeholder="Email"
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData('email', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Phone"
                            placeholder="Phone"
                            value={form.data.phone}
                            onChange={(e) =>
                                form.setData('phone', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Website"
                            placeholder="https://"
                            value={form.data.website}
                            onChange={(e) =>
                                form.setData('website', e.target.value)
                            }
                        />
                        <Input
                            aria-label="Note for clients"
                            placeholder="Note, e.g. 10% off for our clients"
                            className="md:col-span-2"
                            value={form.data.note}
                            onChange={(e) =>
                                form.setData('note', e.target.value)
                            }
                        />
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.data.show_clients}
                                onChange={(e) =>
                                    form.setData(
                                        'show_clients',
                                        e.target.checked,
                                    )
                                }
                            />
                            Show to clients
                        </label>
                        {Object.values(form.errors)[0] && (
                            <span className="text-destructive md:col-span-4">
                                {Object.values(form.errors)[0]}
                            </span>
                        )}
                        <Button
                            type="submit"
                            size="sm"
                            className="justify-self-start"
                            disabled={form.processing}
                        >
                            Add the vendor
                        </Button>
                    </form>
                )}
                {vendors.length === 0 ? (
                    <p className="text-muted-foreground">
                        No preferred vendors yet.
                    </p>
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {vendors.map((v) => (
                            <li
                                key={v.ulid}
                                className="flex flex-wrap items-center gap-2 p-3"
                            >
                                <Badge variant="outline">
                                    {categories[v.category] ?? v.category}
                                </Badge>
                                <strong>{v.name}</strong>
                                {[v.phone, v.email].filter(Boolean).join(' · ')}
                                {v.website && (
                                    <a
                                        className="underline"
                                        href={v.website}
                                        rel="noopener noreferrer nofollow"
                                        target="_blank"
                                    >
                                        Website
                                    </a>
                                )}
                                {v.note && (
                                    <span className="text-muted-foreground">
                                        {v.note}
                                    </span>
                                )}
                                {!v.show_clients && (
                                    <Badge variant="secondary">
                                        Agents only
                                    </Badge>
                                )}
                                {canEdit && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            router.delete(
                                                `/deals/vendors/${v.ulid}`,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Remove
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

Vendors.layout = { breadcrumbs: [{ title: 'Deals', href: '/deals' }] };
