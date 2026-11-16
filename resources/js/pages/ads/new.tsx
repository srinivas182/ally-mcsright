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

type FunnelTarget = {
    ulid: string;
    label: string;
    photo: string | null;
    error: string | null;
    headline: string;
    body: string;
};

const money = (n: number) =>
    n.toLocaleString('en-US', { style: 'currency', currency: 'USD' });

/**
 * The ad builder (specs/ads/01, specs/grow/03): pick a listing or a funnel, write the ad (the AI drafts listing
 * ads; a funnel starts from its own headline), check it, set a budget, agree, publish. Housing ads reach everyone
 * within a radius of the home or area (at least 15 miles); nothing else narrows them.
 */
export default function NewAd({
    connected,
    boost,
    accounts,
    pages,
    listings,
    listing,
    funnels,
    funnel,
    limits,
    creatives,
    chosen,
    form_pages,
    privacy_url,
}: {
    connected: boolean;
    boost: {
        credits_per_dollar: number;
        margin_percent: number;
        balance: number;
        pool: boolean;
    } | null;
    accounts: Option[];
    pages: Option[];
    listings: ListingOption[];
    listing: (ListingOption & { mapped: boolean }) | null;
    funnels: { ulid: string; label: string }[];
    funnel: FunnelTarget | null;
    limits: {
        min_daily: number;
        max_daily: number;
        max_days: number;
        min_radius: number;
    };
    creatives: {
        designs: {
            ulid: string;
            label: string;
            image: string | null;
            slides: number;
        }[];
        videos: { ulid: string; label: string; image: string | null }[];
        posts: {
            ulid: string;
            label: string;
            image: string | null;
            page: string;
        }[];
    } | null;
    chosen: { studio_design: string; studio_video: string; post: string };
    form_pages: string[];
    privacy_url: string | null;
}) {
    const form = useForm({
        listing: funnel ? '' : (listing?.ulid ?? ''),
        funnel: funnel?.ulid ?? '',
        account: accounts[0]?.id ?? '',
        page: pages[0]?.id ?? '',
        headline: funnel?.headline ?? '',
        body: funnel?.body ?? '',
        daily_budget: 20,
        days: 14,
        radius: limits.min_radius,
        agree: false,
        stop_loss: true,
        pay: connected ? 'own' : 'boost',
        studio_design: chosen.studio_design,
        studio_video: chosen.studio_video,
        post: chosen.post,
        goal: 'website',
        privacy_url: privacy_url ?? '',
    });
    const madeOf = form.data.studio_video
        ? `video:${form.data.studio_video}`
        : form.data.studio_design
          ? `design:${form.data.studio_design}`
          : form.data.post
            ? `post:${form.data.post}`
            : 'photo';
    const pick = (v: string) => {
        const [kind, id] = v.split(':');
        form.setData((d) => ({
            ...d,
            studio_design: kind === 'design' ? id : '',
            studio_video: kind === 'video' ? id : '',
            post: kind === 'post' ? id : '',
            goal: kind === 'post' ? 'website' : d.goal,
        }));
    };
    const canForm =
        form.data.pay !== 'boost' &&
        madeOf.split(':')[0] !== 'post' &&
        form_pages.includes(form.data.page);
    const options: { key: string; label: string; image: string | null }[] = [
        {
            key: 'photo',
            label: 'The listing photo',
            image: funnel?.photo ?? listing?.photo ?? null,
        },
        ...(creatives?.designs ?? []).map((d) => ({
            key: `design:${d.ulid}`,
            label: `${d.label} ${d.slides >= 2 ? `carousel (${d.slides})` : 'design'}`,
            image: d.image,
        })),
        ...(creatives?.videos ?? []).map((v) => ({
            key: `video:${v.ulid}`,
            label: v.label,
            image: v.image,
        })),
        ...(creatives?.posts ?? []).map((p) => ({
            key: `post:${p.ulid}`,
            label: `Your post: ${p.label}`,
            image: p.image,
        })),
    ];
    const boosting = form.data.pay === 'boost' && boost !== null;
    const credits = boost
        ? Math.ceil(
              boost.credits_per_dollar *
                  form.data.daily_budget *
                  form.data.days,
          )
        : 0;
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

    if (!connected && boost === null) {
        return (
            <div className="grid gap-4 p-4">
                <Head title="New ad" />
                <p>First connect your Meta ad account.</p>
                <Link href="/apps/meta_ads" className="underline">
                    Connect Meta Ads
                </Link>
            </div>
        );
    }

    return (
        <>
            <Head title="New ad" />
            <div className="grid max-w-2xl gap-6 p-4 text-base">
                <PageHeader
                    title={funnel ? 'Promote a funnel' : 'New listing ad'}
                    description="Runs on Facebook and Instagram from your own ad account."
                />

                <section className="grid gap-2">
                    <h2 className="font-medium">
                        1. {funnel ? 'Which funnel?' : 'Which listing?'}
                    </h2>
                    {funnels.length > 0 && (
                        <select
                            aria-label="Funnel"
                            className="h-12 rounded-md border bg-background px-3"
                            value={form.data.funnel}
                            onChange={(e) =>
                                router.get(
                                    '/ads/new',
                                    e.target.value === ''
                                        ? {}
                                        : { funnel: e.target.value },
                                    { preserveState: false },
                                )
                            }
                        >
                            <option value="">A listing, not a funnel</option>
                            {funnels.map((f) => (
                                <option key={f.ulid} value={f.ulid}>
                                    Funnel: {f.label}
                                </option>
                            ))}
                        </select>
                    )}
                    {funnel?.error && (
                        <p className="text-destructive">{funnel.error}</p>
                    )}
                    {form.errors.funnel && (
                        <p className="text-destructive">{form.errors.funnel}</p>
                    )}
                    {funnel ? null : listings.length === 0 ? (
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

                {(funnel ? !funnel.error : listing) && (
                    <form
                        className="grid gap-6"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/ads');
                        }}
                    >
                        <section className="grid gap-3">
                            <h2 className="font-medium">2. The ad</h2>
                            {options.length > 1 ? (
                                <fieldset className="grid gap-2">
                                    <legend className="mb-1">Made from</legend>
                                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                        {options.map((o) => (
                                            <label
                                                key={o.key}
                                                className={`grid cursor-pointer gap-1 rounded-lg border p-1 text-xs ${madeOf === o.key ? 'ring-2 ring-primary' : ''}`}
                                            >
                                                <input
                                                    type="radio"
                                                    name="made_of"
                                                    className="sr-only"
                                                    checked={madeOf === o.key}
                                                    onChange={() => pick(o.key)}
                                                />
                                                {o.image && (
                                                    <img
                                                        src={o.image}
                                                        alt=""
                                                        loading="lazy"
                                                        className="aspect-[4/5] w-full rounded object-cover"
                                                    />
                                                )}
                                                <span>{o.label}</span>
                                            </label>
                                        ))}
                                    </div>
                                    {(form.errors as Record<string, string>)
                                        .studio_design ??
                                        (form.errors as Record<string, string>)
                                            .studio_video ??
                                        (form.errors as Record<string, string>)
                                            .post}
                                </fieldset>
                            ) : (
                                (funnel?.photo ?? listing?.photo) && (
                                    <img
                                        src={
                                            funnel?.photo ??
                                            listing?.photo ??
                                            ''
                                        }
                                        alt={
                                            funnel?.label ??
                                            listing?.label ??
                                            ''
                                        }
                                        className="max-h-64 w-full rounded-md object-cover"
                                    />
                                )
                            )}
                            <fieldset className="grid gap-2">
                                <legend className="mb-1">
                                    When someone taps the ad
                                </legend>
                                <label className="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        className="size-5"
                                        checked={form.data.goal === 'website'}
                                        onChange={() =>
                                            form.setData('goal', 'website')
                                        }
                                    />
                                    They see the listing on your website
                                </label>
                                <label className="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        className="size-5"
                                        disabled={!canForm}
                                        checked={form.data.goal === 'form'}
                                        onChange={() =>
                                            form.setData('goal', 'form')
                                        }
                                    />
                                    A short form opens on Facebook or Instagram
                                    (name, email, phone filled in for them); the
                                    lead goes straight to your CRM
                                </label>
                                {!canForm && (
                                    <span className="text-sm text-muted-foreground">
                                        Instant forms need your own Meta ad
                                        account and Facebook Lead Ads connected
                                        for this Page (Apps).
                                    </span>
                                )}
                                {form.data.goal === 'form' && (
                                    <label className="grid gap-1">
                                        Your brokerage&apos;s privacy policy
                                        link (shown on the form)
                                        <Input
                                            type="url"
                                            value={form.data.privacy_url}
                                            onChange={(e) =>
                                                form.setData(
                                                    'privacy_url',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </label>
                                )}
                                {(['goal', 'privacy_url'] as const).map(
                                    (k) =>
                                        (form.errors as Record<string, string>)[
                                            k
                                        ] && (
                                            <span
                                                key={k}
                                                className="text-destructive"
                                            >
                                                {
                                                    (
                                                        form.errors as Record<
                                                            string,
                                                            string
                                                        >
                                                    )[k]
                                                }
                                            </span>
                                        ),
                                )}
                            </fieldset>
                            <div hidden={funnel !== null}>
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
                                Fair Housing).{' '}
                                {funnel
                                    ? "The ad links to the funnel's page on your website."
                                    : "The ad links to the listing's page on your website."}
                            </p>
                        </section>

                        <section className="grid gap-3">
                            <h2 className="font-medium">3. Who sees it</h2>
                            <label className="grid gap-1">
                                Everyone within {form.data.radius} miles of{' '}
                                {funnel ? 'the area' : 'the home'}
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
                            {!boosting && (
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
                                                form.setData(
                                                    'page',
                                                    e.target.value,
                                                )
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
                            )}
                        </section>

                        <section className="grid gap-3">
                            <h2 className="font-medium">4. Budget</h2>
                            {boost && (
                                <fieldset className="grid gap-2">
                                    <legend className="mb-1">Pay with</legend>
                                    {connected && (
                                        <label className="flex items-center gap-3">
                                            <input
                                                type="radio"
                                                className="size-5"
                                                checked={
                                                    form.data.pay === 'own'
                                                }
                                                onChange={() =>
                                                    form.setData('pay', 'own')
                                                }
                                            />
                                            My own Meta ad account
                                        </label>
                                    )}
                                    <label className="flex items-center gap-3">
                                        <input
                                            type="radio"
                                            className="size-5"
                                            checked={form.data.pay === 'boost'}
                                            onChange={() =>
                                                form.setData('pay', 'boost')
                                            }
                                        />
                                        RightAlly credits (Boost): no ad account
                                        needed
                                    </label>
                                    {form.errors.pay && (
                                        <span className="text-destructive">
                                            {form.errors.pay}
                                        </span>
                                    )}
                                </fieldset>
                            )}
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
                                {boosting && boost ? (
                                    <span>
                                        I agree to use up to{' '}
                                        <strong>
                                            {credits.toLocaleString()} credits
                                        </strong>{' '}
                                        ({money(total)} of ads plus RightAlly's{' '}
                                        {boost.margin_percent}%). Credits it
                                        doesn't spend come back to me. You have{' '}
                                        {boost.balance.toLocaleString()} credits
                                        {boost.pool
                                            ? ' (your brokerage can cover the rest)'
                                            : ''}
                                        .
                                    </span>
                                ) : (
                                    <span>
                                        I agree to spend up to{' '}
                                        <strong>{money(total)}</strong> from my
                                        own Meta ad account (
                                        {money(form.data.daily_budget)} a day
                                        for {form.data.days} days). I can pause
                                        it at any time.
                                    </span>
                                )}
                            </label>
                            {form.errors.agree && (
                                <span className="text-destructive">
                                    {form.errors.agree}
                                </span>
                            )}
                            <label className="flex items-start gap-3">
                                <input
                                    type="checkbox"
                                    className="mt-1 size-5"
                                    checked={form.data.stop_loss}
                                    onChange={(e) =>
                                        form.setData(
                                            'stop_loss',
                                            e.target.checked,
                                        )
                                    }
                                />
                                <span>
                                    Stop-loss: pause it for me if half the
                                    budget brings no lead, or leads cost more
                                    than three times our usual.
                                </span>
                            </label>
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
