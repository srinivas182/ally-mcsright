import { Head, router, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Lead = {
    ulid: string;
    name: string;
    source: string;
    city: string | null;
    added: string | null;
    in_pond_since: string | null;
};
type Props = {
    leads: Lead[];
    settings: { enabled: boolean; after_days: number; daily_claims: number };
    claimsLeft: number;
    isManager: boolean;
};

/** Company leads nobody contacted in time; claim one and call it now. */
export default function Pond({
    leads,
    settings,
    claimsLeft,
    isManager,
}: Props) {
    const form = useForm(settings);

    return (
        <>
            <Head title="Lead pond" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Lead pond"
                    description={
                        settings.enabled
                            ? `Company leads not contacted within ${settings.after_days} days. You can claim ${claimsLeft} more today.`
                            : 'The lead pond is off.'
                    }
                />
                {isManager && (
                    <form
                        className="flex flex-wrap items-end gap-3 rounded-lg border p-4 text-sm"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/contacts/pond/settings', {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={form.data.enabled}
                                onChange={(e) =>
                                    form.setData('enabled', e.target.checked)
                                }
                            />
                            Use the lead pond
                        </label>
                        <label className="grid gap-1">
                            Move after (days without contact)
                            <Input
                                type="number"
                                min={1}
                                max={30}
                                className="w-24"
                                value={form.data.after_days}
                                onChange={(e) =>
                                    form.setData(
                                        'after_days',
                                        Number(e.target.value),
                                    )
                                }
                            />
                        </label>
                        <label className="grid gap-1">
                            Claims per agent per day
                            <Input
                                type="number"
                                min={1}
                                max={50}
                                className="w-24"
                                value={form.data.daily_claims}
                                onChange={(e) =>
                                    form.setData(
                                        'daily_claims',
                                        Number(e.target.value),
                                    )
                                }
                            />
                        </label>
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </form>
                )}
                {leads.length === 0 ? (
                    <EmptyState
                        title="The pond is empty"
                        description="Every company lead is being worked."
                    />
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {leads.map((l) => (
                            <li
                                key={l.ulid}
                                className="flex flex-wrap items-center justify-between gap-2 p-3"
                            >
                                <span className="grid">
                                    <span className="font-medium">
                                        {l.name}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {l.source}
                                        {l.city ? ` · ${l.city}` : ''}
                                        {l.in_pond_since
                                            ? ` · in pond since ${new Date(l.in_pond_since).toLocaleDateString()}`
                                            : ''}
                                    </span>
                                </span>
                                <Button
                                    size="sm"
                                    disabled={claimsLeft === 0}
                                    onClick={() =>
                                        router.post(`/contacts/${l.ulid}/claim`)
                                    }
                                >
                                    Claim
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

Pond.layout = {
    breadcrumbs: [
        { title: 'Contacts', href: '/contacts' },
        { title: 'Lead pond', href: '/contacts/pond' },
    ],
};
