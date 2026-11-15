import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { postJson } from '@/lib/http';

type Option = { id: string; name: string };
type ListingOption = {
    ulid: string;
    label: string;
    price: number | null;
    photo: string | null;
};

const money = (n: number) =>
    n.toLocaleString('en-US', { style: 'currency', currency: 'USD' });

/**
 * The listing ad builder (specs/ads/01): pick a listing, let the AI write the ad, check it, set a budget, agree,
 * publish. Housing ads reach everyone within a radius of the home (at least 15 miles); nothing else narrows them.
 */
export default function NewAd({
    connected,
    accounts,
    pages,
    listings,
    listing,
    limits,
}: {
    connected: boolean;
    accounts: Option[];
    pages: Option[];
    listings: ListingOption[];
    listing: (ListingOption & { mapped: boolean }) | null;
    limits: {
        min_daily: number;
        max_daily: number;
        max_days: number;
        min_radius: number;
    };
}) {
    const form = useForm({
        listing: listing?.ulid ?? '',
        account: accounts[0]?.id ?? '',
        page: pages[0]?.id ?? '',
        headline: '',
        body: '',
        daily_budget: 20,
        days: 14,
        radius: limits.min_radius,
        agree: false,
    });
    const [drafting, setDrafting] = useState(false);
    const [note, setNote] = useState<string | null>(null);
    const total = form.data.daily_budget * form.data.days;

    const draft = () => {
        setDrafting(true);
        setNote(null);
        postJson<{ headline: string; body: string; flags: string[] }>(
            '/ads/draft',
            { listing: form.data.listing },
        )
            .then((d) => {
                form.setData((data) => ({
                    ...data,
                    headline: d.headline,
                    body: d.body,
                }));
                if (d.flags.length > 0) {
                    setNote(
                        `Check the wording: "${d.flags.join('", "')}" is not allowed in housing ads.`,
                    );
                }
            })
            .catch(() =>
                setNote(
                    "The AI couldn't write a draft just now. Write your own, or try again.",
                ),
            )
            .finally(() => setDrafting(false));
    };

    if (!connected) {
        return (
            <div className="grid gap-4 p-4">
                <Head title="New listing ad" />
                <p>First connect your Meta ad account.</p>
                <Link href="/apps/meta_ads" className="underline">
                    Connect Meta Ads
                </Link>
            </div>
        );
    }

    return (
        <>
            <Head title="New listing ad" />
            <div className="grid max-w-2xl gap-6 p-4 text-base">
                <PageHeader
                    title="New listing ad"
                    description="Runs on Facebook and Instagram from your own ad account."
                />

                <section className="grid gap-2">
                    <h2 className="font-medium">1. Which listing?</h2>
                    {listings.length === 0 ? (
                        <p className="text-muted-foreground">
                            No active listings of yours were found. Check your
                            MLS ID under My profile.
                        </p>
                    ) : (
                        <select
                            aria-label="Listing"
                            className="h-12 rounded-md border bg-background px-3"
                            value={form.data.listing}
                            onChange={(e) =>
                                router.get(
                                    '/ads/new',
                                    { listing: e.target.value },
                                    { preserveState: false },
                                )
                            }
                        >
                            <option value="">Choose a listing</option>
                            {listings.map((l) => (
                                <option key={l.ulid} value={l.ulid}>
                                    {l.label}
                                    {l.price !== null
                                        ? ` · ${money(l.price)}`
                                        : ''}
                                </option>
                            ))}
                        </select>
                    )}
                    {listing && !listing.mapped && (
                        <p className="text-destructive">
                            This listing has no map location, so it can't be
                            advertised by area.
                        </p>
                    )}
                    {form.errors.listing && (
                        <p className="text-destructive">
                            {form.errors.listing}
                        </p>
                    )}
                </section>

                {listing && (
                    <form
                        className="grid gap-6"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/ads');
                        }}
                    >
                        <section className="grid gap-3">
                            <h2 className="font-medium">2. The ad</h2>
                            {listing.photo && (
                                <img
                                    src={listing.photo}
                                    alt={listing.label}
                                    className="max-h-64 w-full rounded-md object-cover"
                                />
                            )}
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={draft}
                                    disabled={drafting}
                                >
                                    {drafting ? 'Writing…' : 'Write it with AI'}
                                </Button>
                            </div>
                            {note && <p className="text-destructive">{note}</p>}
                            <label className="grid gap-1">
                                Headline (up to 40 characters)
                                <Input
                                    maxLength={40}
                                    value={form.data.headline}
                                    onChange={(e) =>
                                        form.setData('headline', e.target.value)
                                    }
                                />
                                {form.errors.headline && (
                                    <span className="text-destructive">
                                        {form.errors.headline}
                                    </span>
                                )}
                            </label>
                            <label className="grid gap-1">
                                Ad text
                                <textarea
                                    className="min-h-28 rounded-md border bg-background p-3"
                                    maxLength={600}
                                    value={form.data.body}
                                    onChange={(e) =>
                                        form.setData('body', e.target.value)
                                    }
                                />
                                {form.errors.body && (
                                    <span className="text-destructive">
                                        {form.errors.body}
                                    </span>
                                )}
                            </label>
                            <p className="text-sm text-muted-foreground">
                                Describe the home, not who should live there (US
                                Fair Housing). The ad links to the listing's
                                page on your website.
                            </p>
                        </section>

                        <section className="grid gap-3">
                            <h2 className="font-medium">3. Who sees it</h2>
                            <label className="grid gap-1">
                                Everyone within {form.data.radius} miles of the
                                home
                                <input
                                    type="range"
                                    min={limits.min_radius}
                                    max={50}
                                    value={form.data.radius}
                                    onChange={(e) =>
                                        form.setData(
                                            'radius',
                                            Number(e.target.value),
                                        )
                                    }
                                />
                            </label>
                            <p className="text-sm text-muted-foreground">
                                Housing ads can't be aimed by age, gender or
                                interests, and must cover at least 15 miles.
                            </p>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <label className="grid gap-1">
                                    Ad account
                                    <select
                                        className="h-12 rounded-md border bg-background px-3"
                                        value={form.data.account}
                                        onChange={(e) =>
                                            form.setData(
                                                'account',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        {accounts.map((a) => (
                                            <option key={a.id} value={a.id}>
                                                {a.name}
                                            </option>
                                        ))}
                                    </select>
                                    {form.errors.account && (
                                        <span className="text-destructive">
                                            {form.errors.account}
                                        </span>
                                    )}
                                </label>
                                <label className="grid gap-1">
                                    Facebook Page
                                    <select
                                        className="h-12 rounded-md border bg-background px-3"
                                        value={form.data.page}
                                        onChange={(e) =>
                                            form.setData('page', e.target.value)
                                        }
                                    >
                                        {pages.map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {p.name}
                                            </option>
                                        ))}
                                    </select>
                                    {form.errors.page && (
                                        <span className="text-destructive">
                                            {form.errors.page}
                                        </span>
                                    )}
                                </label>
                            </div>
                        </section>

                        <section className="grid gap-3">
                            <h2 className="font-medium">4. Budget</h2>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <label className="grid gap-1">
                                    Per day (${limits.min_daily} to $
                                    {limits.max_daily})
                                    <Input
                                        type="number"
                                        min={limits.min_daily}
                                        max={limits.max_daily}
                                        value={form.data.daily_budget}
                                        onChange={(e) =>
                                            form.setData(
                                                'daily_budget',
                                                Number(e.target.value),
                                            )
                                        }
                                    />
                                    {form.errors.daily_budget && (
                                        <span className="text-destructive">
                                            {form.errors.daily_budget}
                                        </span>
                                    )}
                                </label>
                                <label className="grid gap-1">
                                    Days (up to {limits.max_days})
                                    <Input
                                        type="number"
                                        min={1}
                                        max={limits.max_days}
                                        value={form.data.days}
                                        onChange={(e) =>
                                            form.setData(
                                                'days',
                                                Number(e.target.value),
                                            )
                                        }
                                    />
                                    {form.errors.days && (
                                        <span className="text-destructive">
                                            {form.errors.days}
                                        </span>
                                    )}
                                </label>
                            </div>
                            <label className="flex items-start gap-3">
                                <input
                                    type="checkbox"
                                    className="mt-1 size-5"
                                    checked={form.data.agree}
                                    onChange={(e) =>
                                        form.setData('agree', e.target.checked)
                                    }
                                />
                                <span>
                                    I agree to spend up to{' '}
                                    <strong>{money(total)}</strong> from my own
                                    Meta ad account (
                                    {money(form.data.daily_budget)} a day for{' '}
                                    {form.data.days} days). I can pause it at
                                    any time.
                                </span>
                            </label>
                            {form.errors.agree && (
                                <span className="text-destructive">
                                    {form.errors.agree}
                                </span>
                            )}
                        </section>

                        <div>
                            <Button
                                size="lg"
                                disabled={form.processing || !form.data.agree}
                            >
                                Publish the ad
                            </Button>
                        </div>
                    </form>
                )}
            </div>
        </>
    );
}
