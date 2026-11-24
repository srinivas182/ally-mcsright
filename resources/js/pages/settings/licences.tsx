import { Head, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Licence = {
    id: number;
    state: string;
    number: string;
    holder: string | null;
    status: string;
    expires_on: string | null;
    checked_at: string | null;
    hq_checked: boolean;
    good: boolean;
};

/** What each result from the state's records means, in plain words. */
const STATUS: Record<string, { label: string; note: string }> = {
    active: {
        label: 'Active',
        note: 'Confirmed in the state records. MLS boards in this state can be added.',
    },
    test: {
        label: 'Test licence',
        note: 'Accepted while payments are in test mode.',
    },
    unchecked: {
        label: 'Being checked',
        note: 'This state has no public records here yet; RightAlly checks it with the state.',
    },
    new: {
        label: 'New: being checked',
        note: 'Not in the state records yet. RightAlly checks it with the state; no MLS boards in this state until it shows as active.',
    },
    expired: {
        label: 'Expired',
        note: 'Renew it with the state to add MLS boards in this state.',
    },
    inactive: {
        label: 'Inactive',
        note: 'The state shows it as inactive. Make it active to add MLS boards in this state.',
    },
    suspended: {
        label: 'Suspended',
        note: 'The state shows it as suspended. Contact the state, or us if this is wrong.',
    },
    not_found: {
        label: 'Not found',
        note: 'The state records no longer show this number. Check it, or contact us.',
    },
};

/** LV2: Settings › Licences (owners): the brokerage's licences and what the state's records say about each. */
export default function Licences({
    licences,
    states,
    mayPublish,
}: {
    licences: Licence[];
    states: Record<string, string>;
    mayPublish: boolean;
}) {
    const form = useForm({
        state: Object.keys(states)[0] ?? '',
        number: '',
        new: false,
    });

    return (
        <>
            <Head title="Licences" />
            <div className="grid max-w-3xl gap-6 p-4 text-base">
                <PageHeader
                    title="Licences"
                    description="Your brokerage's real estate licences. We check each one in the state's public records every day and email you 60, 30 and 7 days before one expires. MLS boards can be added in states where a licence is active."
                />
                {!mayPublish && (
                    <p className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                        None of your licences is active yet, so your website
                        shows a &quot;coming soon&quot; page. It can go live as
                        soon as one is.
                    </p>
                )}
                <section className="grid gap-3">
                    {licences.length === 0 && (
                        <p className="text-muted-foreground">
                            No licences yet. Add yours below.
                        </p>
                    )}
                    {licences.map((l) => {
                        const s = STATUS[l.status] ?? {
                            label: l.status,
                            note: '',
                        };

                        return (
                            <div
                                key={l.id}
                                className="flex flex-wrap items-start justify-between gap-3 rounded-lg border p-4"
                            >
                                <div className="grid gap-1">
                                    <p className="font-medium">
                                        {states[l.state] ?? l.state} licence{' '}
                                        {l.number}{' '}
                                        <Badge
                                            variant={
                                                l.good
                                                    ? 'secondary'
                                                    : 'destructive'
                                            }
                                        >
                                            {l.hq_checked && l.good
                                                ? 'Checked by RightAlly'
                                                : s.label}
                                        </Badge>
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {s.note}
                                        {l.expires_on
                                            ? ` Expires ${l.expires_on}.`
                                            : ''}
                                    </p>
                                </div>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            `/settings/licences/${l.id}/remove`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Remove
                                </Button>
                            </div>
                        );
                    })}
                </section>
                <form
                    className="grid gap-3 rounded-lg border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/settings/licences', {
                            preserveScroll: true,
                            onSuccess: () => form.reset('number', 'new'),
                        });
                    }}
                >
                    <h2 className="font-medium">Add a licence</h2>
                    <div className="flex flex-wrap items-end gap-3">
                        <label className="grid gap-1 text-sm">
                            State
                            <select
                                className="h-9 rounded-md border bg-background px-2"
                                value={form.data.state}
                                onChange={(e) =>
                                    form.setData('state', e.target.value)
                                }
                            >
                                {Object.entries(states).map(([code, name]) => (
                                    <option key={code} value={code}>
                                        {name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Licence number
                            <Input
                                className="w-56"
                                autoComplete="off"
                                value={form.data.number}
                                onChange={(e) =>
                                    form.setData('number', e.target.value)
                                }
                            />
                        </label>
                        <Button type="submit" disabled={form.processing}>
                            Check and add
                        </Button>
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.new}
                            onChange={(e) =>
                                form.setData('new', e.target.checked)
                            }
                        />
                        My licence is new (issued in the last few days)
                    </label>
                    {(form.errors.number || form.errors.state) && (
                        <p className="text-sm text-destructive" role="alert">
                            {form.errors.number ?? form.errors.state}
                        </p>
                    )}
                </form>
            </div>
        </>
    );
}

Licences.layout = {
    breadcrumbs: [{ title: 'Licences', href: '/settings/licences' }],
};
