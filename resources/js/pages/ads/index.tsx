import { Head, Link, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Campaign = {
    ulid: string;
    listing: string;
    headline: string;
    status: string;
    error: string | null;
    daily_budget: number;
    max_spend: number;
    starts_on: string;
    ends_on: string;
    spend: number;
    impressions: number;
    clicks: number;
    leads: number;
    cost_per_click: number | null;
    results_at: string | null;
    mine: boolean;
    by: string | null;
};

const money = (n: number) =>
    n.toLocaleString('en-US', { style: 'currency', currency: 'USD' });

const statusLabel: Record<string, string> = {
    publishing: 'Publishing',
    active: 'Running',
    paused: 'Paused',
    ended: 'Ended',
    failed: "Didn't publish",
};

/** Listing ads: what is running, what it has spent and brought in (specs/ads/01). */
export default function Ads({
    ready,
    connected,
    campaigns,
}: {
    ready: boolean;
    connected: boolean;
    campaigns: Campaign[];
}) {
    return (
        <>
            <Head title="Ads" />
            <div className="grid gap-6 p-4">
                <PageHeader
                    title="Ads"
                    description="Make a Facebook and Instagram ad for one of your listings in a minute. It runs from your own ad account; RightAlly never adds a fee."
                />
                {!ready ? (
                    <p className="rounded-lg border p-5">
                        Listing ads aren't switched on yet. Please ask RightAlly
                        support.
                    </p>
                ) : !connected ? (
                    <section className="grid gap-3 rounded-lg border p-5">
                        <h2 className="font-medium">
                            First, connect your Meta ad account
                        </h2>
                        <p className="text-muted-foreground">
                            Sign in to Facebook once and choose your ad account
                            and Page. Your ads are billed by Meta to your own
                            account.
                        </p>
                        <div>
                            <Link
                                href="/apps/meta_ads"
                                className="inline-flex h-12 items-center rounded-md bg-primary px-6 font-medium text-primary-foreground"
                            >
                                Connect Meta Ads
                            </Link>
                        </div>
                    </section>
                ) : (
                    <div>
                        <Link
                            href="/ads/new"
                            className="inline-flex h-12 items-center rounded-md bg-primary px-6 font-medium text-primary-foreground"
                        >
                            Make a listing ad
                        </Link>
                    </div>
                )}

                {campaigns.length === 0 ? (
                    <p className="text-muted-foreground">No ads yet.</p>
                ) : (
                    <ul className="grid gap-3">
                        {campaigns.map((c) => (
                            <li
                                key={c.ulid}
                                className="grid gap-2 rounded-lg border p-4"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="font-medium">
                                        {c.listing}
                                        {c.by ? ` · ${c.by}` : ''}
                                    </span>
                                    <Badge
                                        variant={
                                            c.status === 'failed'
                                                ? 'destructive'
                                                : 'outline'
                                        }
                                    >
                                        {statusLabel[c.status] ?? c.status}
                                    </Badge>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    “{c.headline}” · {money(c.daily_budget)} a
                                    day, {c.starts_on} to {c.ends_on} (at most{' '}
                                    {money(c.max_spend)})
                                </p>
                                {c.error && (
                                    <p className="text-sm text-destructive">
                                        {c.error}
                                    </p>
                                )}
                                <dl className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-5">
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Spent
                                        </dt>
                                        <dd>{money(c.spend)}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Seen
                                        </dt>
                                        <dd>
                                            {c.impressions.toLocaleString()}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Clicks
                                        </dt>
                                        <dd>{c.clicks.toLocaleString()}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Per click
                                        </dt>
                                        <dd>
                                            {c.cost_per_click !== null
                                                ? money(c.cost_per_click)
                                                : '–'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Leads
                                        </dt>
                                        <dd>{c.leads}</dd>
                                    </div>
                                </dl>
                                {c.mine &&
                                    (c.status === 'active' ||
                                        c.status === 'paused') && (
                                        <div>
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        `/ads/${c.ulid}/${c.status === 'active' ? 'pause' : 'resume'}`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {c.status === 'active'
                                                    ? 'Pause'
                                                    : 'Run again'}
                                            </Button>
                                        </div>
                                    )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
