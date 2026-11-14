import { router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type SavedSearchRow = {
    ulid: string;
    name: string;
    frequency: string;
    last_sent_at: string | null;
    sent_count: number;
    unsubscribed: boolean;
};

const frequencies = [
    ['instant', 'As soon as they list'],
    ['daily', 'Daily at 8am'],
    ['weekly', 'Mondays at 8am'],
    ['off', 'No emails'],
] as const;

/** A contact's saved home searches with new-listing and price-drop email alerts. */
export function SavedSearches({
    contactUlid,
    searches,
    canEmail,
}: {
    contactUlid: string;
    searches: SavedSearchRow[];
    canEmail: boolean;
}) {
    const opts = { preserveScroll: true };
    const form = useForm({
        q: '',
        min_price: '',
        max_price: '',
        beds: '',
        name: '',
        frequency: canEmail ? 'daily' : 'off',
    });

    return (
        <section className="grid gap-2 rounded-lg border p-4 text-sm">
            <h2 className="font-semibold">Saved searches</h2>
            {searches.length === 0 && (
                <p className="text-muted-foreground">
                    No saved searches. New listings and price drops are emailed
                    to them.
                </p>
            )}
            <ul className="divide-y">
                {searches.map((s) => (
                    <li
                        key={s.ulid}
                        className="flex flex-wrap items-center gap-2 py-2"
                    >
                        <span className="font-medium">{s.name}</span>
                        {s.unsubscribed && (
                            <Badge variant="outline">They unsubscribed</Badge>
                        )}
                        <span className="text-xs text-muted-foreground">
                            {s.sent_count} alerts sent
                            {s.last_sent_at
                                ? `, last ${new Date(s.last_sent_at).toLocaleDateString()}`
                                : ''}
                        </span>
                        <select
                            aria-label={`${s.name} alerts`}
                            className="ml-auto rounded border bg-background px-2 py-1"
                            value={s.frequency}
                            onChange={(e) =>
                                router.patch(
                                    `/searches/${s.ulid}`,
                                    { frequency: e.target.value },
                                    opts,
                                )
                            }
                        >
                            {frequencies.map(([k, label]) => (
                                <option key={k} value={k}>
                                    {label}
                                </option>
                            ))}
                        </select>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                router.delete(`/searches/${s.ulid}`, opts)
                            }
                        >
                            Delete
                        </Button>
                    </li>
                ))}
            </ul>
            <form
                className="grid gap-2 sm:grid-cols-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/contacts/${contactUlid}/searches`, {
                        ...opts,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                <Input
                    aria-label="Place"
                    placeholder="City, ZIP or neighbourhood"
                    className="sm:col-span-3"
                    value={form.data.q}
                    onChange={(e) => form.setData('q', e.target.value)}
                />
                <Input
                    aria-label="Min price"
                    inputMode="numeric"
                    placeholder="Min price"
                    value={form.data.min_price}
                    onChange={(e) => form.setData('min_price', e.target.value)}
                />
                <Input
                    aria-label="Max price"
                    inputMode="numeric"
                    placeholder="Max price"
                    value={form.data.max_price}
                    onChange={(e) => form.setData('max_price', e.target.value)}
                />
                <select
                    aria-label="Beds"
                    className="rounded border bg-background px-2 py-1.5"
                    value={form.data.beds}
                    onChange={(e) => form.setData('beds', e.target.value)}
                >
                    <option value="">Any beds</option>
                    {[1, 2, 3, 4, 5].map((n) => (
                        <option key={n} value={n}>
                            {n}+ beds
                        </option>
                    ))}
                </select>
                <Input
                    aria-label="Search name"
                    placeholder="Name (optional)"
                    className="sm:col-span-2"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                />
                <select
                    aria-label="Alerts"
                    className="rounded border bg-background px-2 py-1.5"
                    value={form.data.frequency}
                    onChange={(e) => form.setData('frequency', e.target.value)}
                >
                    {frequencies.map(([k, label]) => (
                        <option
                            key={k}
                            value={k}
                            disabled={!canEmail && k !== 'off'}
                        >
                            {label}
                        </option>
                    ))}
                </select>
                <Button
                    className="justify-self-start"
                    disabled={form.processing}
                >
                    Save search
                </Button>
                {Object.values(form.errors)[0] && (
                    <p role="alert" className="text-destructive sm:col-span-3">
                        {Object.values(form.errors)[0]}
                    </p>
                )}
            </form>
        </section>
    );
}
