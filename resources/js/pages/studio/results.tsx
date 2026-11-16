import { Head, Link, router } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';

type Totals = {
    posts: number;
    reach: number;
    interactions: number;
    clicks: number;
    leads: number;
    rate: number | null;
};

type Row = Totals & { key: string };

type Top = {
    ulid: string;
    text: string;
    image: string | null;
    format: string;
    style: string;
    reach: number;
    interactions: number;
    clicks: number;
    leads: number;
    url: string | null;
    posted: string;
};

const n = (v: number) => v.toLocaleString('en-US');
const label: Record<string, string> = {
    photo: 'Single photo',
    carousel: 'Carousel',
    reel: 'Reel',
    tour: 'YouTube tour',
    link: 'Link',
};

function Breakdown({ title, rows }: { title: string; rows: Row[] }) {
    if (rows.length === 0) return null;

    return (
        <section className="grid gap-2">
            <h2 className="font-medium">{title}</h2>
            <table className="w-full text-sm">
                <thead className="text-left text-muted-foreground">
                    <tr>
                        <th className="py-2">&nbsp;</th>
                        <th>Posts</th>
                        <th>Reached</th>
                        <th>Interactions</th>
                        <th>Leads</th>
                        <th>Rate</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((r) => (
                        <tr key={r.key} className="border-t">
                            <td className="py-2">{label[r.key] ?? r.key}</td>
                            <td>{n(r.posts)}</td>
                            <td>{n(r.reach)}</td>
                            <td>{n(r.interactions)}</td>
                            <td>{n(r.leads)}</td>
                            <td>{r.rate === null ? '—' : `${r.rate}%`}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </section>
    );
}

/**
 * Studio → Results (specs/grow/13, SA4): a month of posts, what works by format, style and time of day, the top
 * posts, and what Autopilot has learned. Facebook and Instagram share numbers; leads come from post links.
 */
export default function StudioResults({
    ready,
    report,
    learned,
    learn_after,
    can_team,
    team,
    agents,
    months,
}: {
    ready: boolean;
    report: {
        month: string;
        label: string;
        totals: Totals;
        formats: Row[];
        styles: Row[];
        times: Row[];
        top: Top[];
    };
    learned: { style: string | null; times: string[]; measured: number };
    learn_after: number;
    can_team: boolean;
    team: boolean;
    agents: (Totals & { name: string })[];
    months: string[];
}) {
    if (!ready) {
        return (
            <div className="p-4">
                <Head title="Results" />
                <EmptyState
                    title="Results come with Ally Studio"
                    description="Choose a Studio package and see what your posts bring in."
                />
                <div className="mt-4">
                    <Button asChild>
                        <Link href="/studio">See the packages</Link>
                    </Button>
                </div>
            </div>
        );
    }
    const t = report.totals;
    const go = (q: Record<string, string | number>) =>
        router.get('/studio/results', q, { preserveScroll: true });
    const tiles: [string, string][] = [
        ['Posts', n(t.posts)],
        ['People reached', n(t.reach)],
        ['Likes, comments, shares, saves', n(t.interactions)],
        ['Engagement rate', t.rate === null ? '—' : `${t.rate}%`],
        ['Link clicks', n(t.clicks)],
        ['Leads from posts', n(t.leads)],
    ];

    return (
        <>
            <Head title="Results" />
            <div className="grid max-w-5xl gap-6 p-4 text-base">
                <PageHeader
                    title={`Results: ${report.label}`}
                    description="What your posts did. Facebook and Instagram share their numbers; leads are people who came in through a post's link."
                />
                <div className="flex flex-wrap items-center gap-3">
                    <select
                        className="h-10 rounded-md border bg-background px-3"
                        aria-label="Month"
                        value={report.month}
                        onChange={(e) =>
                            go({
                                month: e.target.value,
                                ...(team ? { team: 1 } : {}),
                            })
                        }
                    >
                        {months.map((m) => (
                            <option key={m} value={m}>
                                {m}
                            </option>
                        ))}
                    </select>
                    {can_team && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                go(
                                    team
                                        ? { month: report.month }
                                        : { month: report.month, team: 1 },
                                )
                            }
                        >
                            {team ? 'Just me' : 'The whole team'}
                        </Button>
                    )}
                </div>
                <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    {tiles.map(([k, v]) => (
                        <div key={k} className="rounded-xl border p-4">
                            <dt className="text-sm text-muted-foreground">
                                {k}
                            </dt>
                            <dd className="mt-1 text-2xl font-semibold">{v}</dd>
                        </div>
                    ))}
                </dl>
                <p className="rounded-lg border p-3 text-sm">
                    {learned.measured < learn_after
                        ? `Autopilot learns what works once ${learn_after} of your posts have numbers (${learned.measured} so far).`
                        : `Autopilot has learned from ${learned.measured} posts${learned.style ? `: it leads with your best style` : ''}${learned.times.length > 0 ? ` and posts first at ${learned.times[0]}` : ''}, still trying something new every third post.`}
                </p>
                {t.posts === 0 ? (
                    <EmptyState
                        title="No posts this month"
                        description="Posts from Ally Studio and Social posts show here once they go out."
                    />
                ) : (
                    <>
                        <Breakdown title="By format" rows={report.formats} />
                        <Breakdown title="By style" rows={report.styles} />
                        <Breakdown title="By time of day" rows={report.times} />
                        <section className="grid gap-2">
                            <h2 className="font-medium">Top posts</h2>
                            <ul className="grid gap-3 sm:grid-cols-2">
                                {report.top.map((p) => (
                                    <li
                                        key={p.ulid}
                                        className="flex gap-3 rounded-xl border p-2"
                                    >
                                        {p.image && (
                                            <img
                                                src={p.image}
                                                alt="Post"
                                                loading="lazy"
                                                className="aspect-[4/5] w-20 flex-none rounded object-cover"
                                            />
                                        )}
                                        <div className="grid content-start gap-1 text-sm">
                                            <span className="line-clamp-2">
                                                {p.text}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {label[p.format] ?? p.format} ·{' '}
                                                {p.style} · {p.posted}
                                            </span>
                                            <span>
                                                {n(p.reach)} reached ·{' '}
                                                {n(p.interactions)} interactions
                                                · {n(p.leads)} leads
                                            </span>
                                            {p.url && (
                                                <a
                                                    href={p.url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="underline"
                                                >
                                                    Open the post
                                                </a>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    </>
                )}
                {team && agents.length > 0 && (
                    <section className="grid gap-2">
                        <h2 className="font-medium">By agent</h2>
                        <table className="w-full text-sm">
                            <thead className="text-left text-muted-foreground">
                                <tr>
                                    <th className="py-2">Agent</th>
                                    <th>Posts</th>
                                    <th>Reached</th>
                                    <th>Interactions</th>
                                    <th>Leads</th>
                                </tr>
                            </thead>
                            <tbody>
                                {agents.map((a) => (
                                    <tr key={a.name} className="border-t">
                                        <td className="py-2">{a.name}</td>
                                        <td>{n(a.posts)}</td>
                                        <td>{n(a.reach)}</td>
                                        <td>{n(a.interactions)}</td>
                                        <td>{n(a.leads)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                )}
            </div>
        </>
    );
}
