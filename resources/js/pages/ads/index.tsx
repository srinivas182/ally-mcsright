import { Head, Link, router } from '@inertiajs/react';
import { FeatureOff } from '@/components/feature-off/feature-off';
import { Megaphone } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Campaign = {
    ulid: string;
    billing: 'own' | 'boost';
    network: string;
    credits_reserved: number;
    credits_refunded: number;
    stopped_reason: string | null;
    pending_commission: number;
    earned_commission: number;
    return: number | null;
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
    boost,
    connected,
    google_connected,
    campaigns,
}: {
    ready: boolean;
    boost: boolean;
    connected: boolean;
    google_connected: boolean;
    campaigns: Campaign[];
}) {
    return (
        <>
            <Head title="Ads" />
            <div className="grid gap-6 p-4">
                <PageHeader
                    title="Ads"
                    description="Make a Facebook and Instagram, Google search or YouTube ad for one of your listings in a minute. From your own ad account RightAlly never adds a fee."
                />
                {!ready && !boost ? (
                    <FeatureOff
                        feature="ads"
                        icon={Megaphone}
                        title="Listing ads"
                        does="A Facebook and Instagram, Google search or YouTube ad for one of your listings, made in a minute."
                        points={[
                            'Written and designed from the listing for you',
                            'Paid from your own ad account; RightAlly never adds a fee',
                        ]}
                    />
                ) : !connected && !google_connected && !boost ? (
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
                                    <Badge variant="outline">{c.network}</Badge>
                                    {c.billing === 'boost' && (
                                        <Badge>Boost</Badge>
                                    )}
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
                                {c.stopped_reason && (
                                    <p className="text-sm">
                                        {c.stopped_reason}
                                    </p>
                                )}
                                {c.billing === 'boost' && (
                                    <p className="text-sm text-muted-foreground">
                                        {c.credits_reserved.toLocaleString()}{' '}
                                        credits reserved
                                        {c.credits_refunded > 0
                                            ? `, ${c.credits_refunded.toLocaleString()} given back`
                                            : ''}
                                    </p>
                                )}
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
                                {(c.pending_commission > 0 ||
                                    c.earned_commission > 0) && (
                                    <p className="text-sm">
                                        Commission from these leads:{' '}
                                        <strong>
                                            {money(c.earned_commission)}
                                        </strong>{' '}
                                        closed
                                        {c.pending_commission > 0
                                            ? `, ${money(c.pending_commission)} under contract`
                                            : ''}
                                        {c.return !== null
                                            ? ` · ${c.return}× what it cost`
                                            : ''}
                                    </p>
                                )}
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
                                            </Button>{' '}
                                            <Button
                                                variant="ghost"
                                                onClick={() =>
                                                    router.post(
                                                        `/ads/${c.ulid}/end`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                End it now
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
