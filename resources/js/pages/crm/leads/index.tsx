import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import {
    VisitorJourney,
    type JourneySummary,
} from '@/components/crm/visitor-journey';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { StatCard } from '@/components/ui/stat-card';

type Lead = {
    ulid: string;
    received: string;
    name: string;
    contact: string | null;
    source: string;
    channel: string;
    summary: string;
    message: string | null;
    status: string;
    agent: string | null;
    response_minutes: number | null;
    new_contact: boolean;
    can_claim: boolean;
    score: number | null;
    score_label: 'hot' | 'warm' | 'cold' | null;
    score_reasons: string[];
    journey: JourneySummary | null;
};
type Props = {
    leads: Lead[];
    review: { ulid: string; received: string; source: string; raw: string }[];
    stats: {
        total: number;
        by_channel: Record<string, number>;
        median_minutes: number | null;
        within_5: number | null;
    };
    alerts: {
        texts: boolean;
        calls: boolean;
        away_until: string | null;
        phone: string | null;
        sms_allowed: boolean;
        voice_allowed: boolean;
        copy_all: boolean;
        can_copy: boolean;
    };
    canManage: boolean;
    focus: string | null;
};

export const channelLabels: Record<string, string> = {
    ai_search: 'AI search (ChatGPT, Perplexity…)',
    organic: 'Search (SEO)',
    paid: 'Paid ads',
    social: 'Social',
    portal: 'Portals',
    email: 'Email',
    referral_site: 'Other websites',
    direct: 'Direct',
};
const statusLabels: Record<string, string> = {
    new: 'New',
    assigned: 'Waiting for a reply',
    offered: 'Up for grabs',
    responded: 'Contacted',
    pond: 'In the pond',
};

const when = (iso: string) =>
    new Date(iso).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });

/** Incoming leads: who got each one, how fast they were contacted, and where they came from. */
export default function Leads({
    leads,
    review,
    stats,
    alerts,
    canManage,
    focus,
}: Props) {
    const opts = { preserveScroll: true };
    const [away, setAway] = useState('4');
    const [byScore, setByScore] = useState(false);
    const save = (
        texts: boolean,
        calls: boolean,
        awayHours?: number | null,
        copyAll: boolean = alerts.copy_all,
    ) =>
        router.post(
            '/leads/alerts',
            {
                texts,
                calls,
                copy_all: copyAll,
                away_hours:
                    awayHours === undefined
                        ? alerts.away_until
                            ? null
                            : 0
                        : awayHours,
            },
            opts,
        );
    const channels = Object.entries(stats.by_channel).filter(([, n]) => n > 0);

    return (
        <>
            <Head title="Leads" />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Leads"
                    description="Every new lead, who has it and how fast they were contacted. Unanswered leads move to the next agent."
                    actions={
                        canManage ? (
                            <span className="flex flex-wrap gap-2">
                                <Button variant="outline" asChild>
                                    <Link href="/leads/report">
                                        Return on spend and speed
                                    </Link>
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link href="/leads/settings">
                                        Sources and routing
                                    </Link>
                                </Button>
                            </span>
                        ) : undefined
                    }
                />

                <div className="grid gap-3 sm:grid-cols-3">
                    <StatCard label="Leads, last 30 days" value={stats.total} />
                    <StatCard
                        label="Median time to first contact"
                        value={
                            stats.median_minutes === null
                                ? '—'
                                : `${stats.median_minutes} min`
                        }
                    />
                    <StatCard
                        label="Contacted within 5 minutes"
                        value={
                            stats.within_5 === null ? '—' : `${stats.within_5}%`
                        }
                    />
                </div>

                {channels.length > 0 && (
                    <section className="grid gap-2 text-sm">
                        <h2 className="font-semibold">Where leads came from</h2>
                        <ul className="flex flex-wrap gap-2">
                            {channels.map(([channel, n]) => (
                                <li key={channel}>
                                    <Badge variant="outline">
                                        {channelLabels[channel] ?? channel}: {n}
                                    </Badge>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section className="grid gap-2 text-sm">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="font-semibold">
                            {canManage ? 'All leads' : 'Your leads'}
                        </h2>
                        <label className="ml-auto flex items-center gap-2 text-xs">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={byScore}
                                onChange={(e) => setByScore(e.target.checked)}
                            />
                            Hottest first
                        </label>
                    </div>
                    {leads.length === 0 ? (
                        <EmptyState
                            title="No leads yet"
                            description={
                                canManage
                                    ? 'Add a lead source to start receiving leads.'
                                    : 'New leads assigned to you appear here.'
                            }
                        />
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {(byScore
                                ? [...leads].sort(
                                      (a, b) =>
                                          (b.score ?? -1) - (a.score ?? -1),
                                  )
                                : leads
                            ).map((l) => (
                                <li
                                    key={l.ulid}
                                    className={`grid gap-1 p-3 ${focus === l.ulid ? 'bg-muted' : ''}`}
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        {l.contact ? (
                                            <Link
                                                className="font-medium underline"
                                                href={`/contacts/${l.contact}`}
                                            >
                                                {l.name}
                                            </Link>
                                        ) : (
                                            <span className="font-medium">
                                                {l.name}
                                            </span>
                                        )}
                                        <Badge
                                            variant={
                                                l.status === 'assigned' ||
                                                l.status === 'offered'
                                                    ? 'destructive'
                                                    : 'outline'
                                            }
                                        >
                                            {statusLabels[l.status] ?? l.status}
                                        </Badge>
                                        {l.score !== null && (
                                            <Badge
                                                variant={
                                                    l.score_label === 'hot'
                                                        ? 'destructive'
                                                        : 'outline'
                                                }
                                                title={l.score_reasons.join(
                                                    ' · ',
                                                )}
                                            >
                                                {l.score_label === 'hot'
                                                    ? 'Hot'
                                                    : l.score_label === 'warm'
                                                      ? 'Warm'
                                                      : 'Cold'}{' '}
                                                {l.score}
                                            </Badge>
                                        )}
                                        {!l.new_contact && (
                                            <Badge variant="outline">
                                                Returning contact
                                            </Badge>
                                        )}
                                        <span className="ml-auto text-xs text-muted-foreground">
                                            {when(l.received)}
                                        </span>
                                    </div>
                                    <p className="text-muted-foreground">
                                        {l.summary} ·{' '}
                                        {channelLabels[l.channel] ?? l.channel}
                                        {l.agent ? ` · ${l.agent}` : ''}
                                        {l.response_minutes !== null
                                            ? ` · contacted in ${l.response_minutes} min`
                                            : ''}
                                    </p>
                                    {l.score_reasons.length > 0 && (
                                        <p className="text-xs text-muted-foreground">
                                            Why:{' '}
                                            {l.score_reasons
                                                .slice(0, 3)
                                                .join(' · ')}
                                        </p>
                                    )}
                                    {l.message && (
                                        <p className="italic">“{l.message}”</p>
                                    )}
                                    {l.journey && (
                                        <VisitorJourney
                                            leadUlid={l.ulid}
                                            journey={l.journey}
                                        />
                                    )}
                                    {l.can_claim && (
                                        <Button
                                            size="sm"
                                            className="justify-self-start"
                                            onClick={() =>
                                                router.post(
                                                    `/leads/${l.ulid}/claim`,
                                                    {},
                                                    opts,
                                                )
                                            }
                                        >
                                            Claim this lead
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {canManage && review.length > 0 && (
                    <section className="grid gap-2 text-sm">
                        <h2 className="font-semibold">
                            Emails we could not read ({review.length})
                        </h2>
                        <p className="text-muted-foreground">
                            These forwarded emails had no email address or phone
                            number. Add the person by hand if they are a real
                            lead.
                        </p>
                        {review.map((r) => (
                            <details
                                key={r.ulid}
                                className="rounded border p-2"
                            >
                                <summary>
                                    {r.source} · {when(r.received)}
                                </summary>
                                <pre className="mt-2 text-xs whitespace-pre-wrap">
                                    {r.raw}
                                </pre>
                            </details>
                        ))}
                    </section>
                )}

                <section className="grid gap-2 rounded-lg border p-4 text-sm">
                    <h2 className="font-semibold">Your lead alerts</h2>
                    <p className="text-xs text-muted-foreground">
                        You always get an email. You can also get a text, and an
                        automated call where pressing 1 connects you to the lead
                        straight away (8am–9pm only)
                        {alerts.phone ? `, at ${alerts.phone}` : ''}.
                    </p>
                    <div className="flex flex-wrap gap-4">
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-4"
                                disabled={!alerts.sms_allowed}
                                checked={alerts.texts}
                                onChange={(e) =>
                                    save(e.target.checked, alerts.calls)
                                }
                            />
                            Text me new leads
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-4"
                                disabled={!alerts.voice_allowed}
                                checked={alerts.calls}
                                onChange={(e) =>
                                    save(alerts.texts, e.target.checked)
                                }
                            />
                            Call me and connect me to new leads
                        </label>
                        {alerts.can_copy && (
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    className="size-4"
                                    checked={alerts.copy_all}
                                    onChange={(e) =>
                                        save(
                                            alerts.texts,
                                            alerts.calls,
                                            undefined,
                                            e.target.checked,
                                        )
                                    }
                                />
                                Email me a copy of every new lead, with who got
                                it
                            </label>
                        )}
                    </div>
                    {alerts.can_copy && (
                        <p className="text-xs text-muted-foreground">
                            Leads that go to the lead pond are always emailed to
                            the broker, so no lead waits unseen.
                        </p>
                    )}
                    {(!alerts.sms_allowed || !alerts.voice_allowed) && (
                        <p className="text-xs text-muted-foreground">
                            Some alerts are not switched on for your brokerage
                            yet.
                        </p>
                    )}
                    {!alerts.phone && (
                        <p className="text-xs text-muted-foreground">
                            Add your mobile number to your profile to get texts
                            or calls.
                        </p>
                    )}
                    <div className="flex flex-wrap items-center gap-2">
                        {alerts.away_until ? (
                            <>
                                <span>
                                    You are away until {when(alerts.away_until)}
                                    : no new leads.
                                </span>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        save(alerts.texts, alerts.calls, 0)
                                    }
                                >
                                    I'm back
                                </Button>
                            </>
                        ) : (
                            <>
                                <span>Pause new leads for</span>
                                <select
                                    aria-label="Pause for"
                                    className="rounded border bg-background px-2 py-1"
                                    value={away}
                                    onChange={(e) => setAway(e.target.value)}
                                >
                                    <option value="2">2 hours</option>
                                    <option value="4">4 hours</option>
                                    <option value="24">1 day</option>
                                    <option value="168">1 week</option>
                                </select>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        save(
                                            alerts.texts,
                                            alerts.calls,
                                            Number(away),
                                        )
                                    }
                                >
                                    Pause
                                </Button>
                            </>
                        )}
                    </div>
                </section>
            </div>
        </>
    );
}
