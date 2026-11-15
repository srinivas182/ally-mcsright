import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Funnel = {
    ulid: string;
    kind: string;
    headline: string;
    intro: string | null;
    area: string | null;
    status: 'live' | 'paused';
    url: string | null;
    visits: number;
    leads: number;
    conversion: number | null;
    source: number | null;
    for: string;
};

/**
 * Grow → Funnels (specs/grow/03): a buyer or seller funnel in three taps (template, area, publish), and what each
 * one brings in. Leads go through the normal intake: routing, speed to lead, AI first responder.
 */
export default function Funnels({
    site_live,
    kinds,
    manager,
    funnels,
}: {
    site_live: boolean;
    kinds: Record<string, string>;
    manager: boolean;
    funnels: Funnel[];
}) {
    const [creating, setCreating] = useState(funnels.length === 0);
    const form = useForm({
        kind: 'new_listings',
        area: '',
        max_price: '',
        headline: '',
        for_brokerage: manager,
    });
    const [copied, setCopied] = useState<string | null>(null);

    return (
        <>
            <Head title="Funnels" />
            <div className="grid max-w-4xl gap-6 p-4 text-base md:p-6">
                <PageHeader
                    title="Funnels"
                    description="One page, one job: buyers get homes, sellers get a value. Every visitor who asks becomes a lead, followed up straight away."
                    actions={
                        site_live && (
                            <Button onClick={() => setCreating(!creating)}>
                                New funnel
                            </Button>
                        )
                    }
                />
                {!site_live && (
                    <p className="rounded-lg border p-4">
                        Funnels live on your brokerage website. Publish the
                        website first under Settings → Website.
                    </p>
                )}
                {creating && site_live && (
                    <form
                        className="grid gap-4 rounded-lg border p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/funnels', {
                                preserveScroll: true,
                                onSuccess: () => {
                                    form.reset();
                                    setCreating(false);
                                },
                            });
                        }}
                    >
                        <fieldset className="grid gap-2">
                            <legend className="mb-1 font-medium">
                                1. What should it do?
                            </legend>
                            {Object.entries(kinds).map(([key, label]) => (
                                <label
                                    key={key}
                                    className="flex items-center gap-3"
                                >
                                    <input
                                        type="radio"
                                        className="size-5"
                                        checked={form.data.kind === key}
                                        onChange={() =>
                                            form.setData('kind', key)
                                        }
                                    />
                                    {label}
                                </label>
                            ))}
                        </fieldset>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="grid gap-1">
                                <span className="font-medium">2. Where?</span>
                                <Input
                                    placeholder="Fondren, Jackson, MS or 39211"
                                    value={form.data.area}
                                    onChange={(e) =>
                                        form.setData('area', e.target.value)
                                    }
                                />
                            </label>
                            {form.data.kind !== 'home_value' && (
                                <label className="grid gap-1">
                                    Price up to (optional)
                                    <Input
                                        inputMode="numeric"
                                        placeholder="$400,000"
                                        value={form.data.max_price}
                                        onChange={(e) =>
                                            form.setData(
                                                'max_price',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </label>
                            )}
                        </div>
                        <label className="grid gap-1">
                            Headline (optional: we write one for you)
                            <Input
                                value={form.data.headline}
                                onChange={(e) =>
                                    form.setData('headline', e.target.value)
                                }
                            />
                        </label>
                        {manager && (
                            <label className="flex items-center gap-3">
                                <input
                                    type="checkbox"
                                    className="size-5"
                                    checked={form.data.for_brokerage}
                                    onChange={(e) =>
                                        form.setData(
                                            'for_brokerage',
                                            e.target.checked,
                                        )
                                    }
                                />
                                For the brokerage (leads follow your lead
                                rules); untick to keep the leads yourself
                            </label>
                        )}
                        <p className="text-sm text-muted-foreground">
                            Describe homes and places, not who should live there
                            (US Fair Housing). Visitors see homes or a value
                            first; texting only with their consent.
                        </p>
                        {Object.values(form.errors).map((m) => (
                            <p key={m} className="text-destructive">
                                {m}
                            </p>
                        ))}
                        <div>
                            <Button size="lg" disabled={form.processing}>
                                3. Publish
                            </Button>
                        </div>
                    </form>
                )}

                {funnels.length > 0 && (
                    <ul className="grid gap-3">
                        {funnels.map((f) => (
                            <li
                                key={f.ulid}
                                className="grid gap-2 rounded-lg border p-4"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="font-medium">
                                        {f.headline}
                                    </span>
                                    <span className="flex items-center gap-2">
                                        {manager && (
                                            <Badge variant="outline">
                                                {f.for}
                                            </Badge>
                                        )}
                                        <Badge
                                            variant={
                                                f.status === 'live'
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {f.status === 'live'
                                                ? 'Live'
                                                : 'Paused'}
                                        </Badge>
                                    </span>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {f.visits.toLocaleString()} visits ·{' '}
                                    {f.leads.toLocaleString()} leads
                                    {f.conversion !== null
                                        ? ` · ${f.conversion}% became leads`
                                        : ''}
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    {f.url && (
                                        <>
                                            <Button variant="outline" asChild>
                                                <a
                                                    href={f.url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    Open
                                                </a>
                                            </Button>
                                            <Button
                                                variant="outline"
                                                onClick={() => {
                                                    void navigator.clipboard?.writeText(
                                                        f.url ?? '',
                                                    );
                                                    setCopied(f.ulid);
                                                }}
                                            >
                                                {copied === f.ulid
                                                    ? 'Link copied'
                                                    : 'Copy link'}
                                            </Button>
                                        </>
                                    )}
                                    {f.status === 'live' && f.url && (
                                        <Button variant="outline" asChild>
                                            <Link
                                                href={`/ads/new?funnel=${f.ulid}`}
                                            >
                                                Promote with an ad
                                            </Link>
                                        </Button>
                                    )}
                                    {f.source !== null && (
                                        <Button variant="outline" asChild>
                                            <Link
                                                href={`/leads?source=${f.source}`}
                                            >
                                                See its leads
                                            </Link>
                                        </Button>
                                    )}
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            router.patch(
                                                `/funnels/${f.ulid}`,
                                                {
                                                    status:
                                                        f.status === 'live'
                                                            ? 'paused'
                                                            : 'live',
                                                },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {f.status === 'live'
                                            ? 'Pause'
                                            : 'Turn back on'}
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
