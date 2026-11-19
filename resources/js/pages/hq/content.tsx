import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Desk = {
    id: number;
    state: string;
    name: string;
    mode: string;
    level: number;
    allowance: { week: number; day: number };
    today: number;
    paused: string | null;
    cities: string;
    indexed: {
        submitted: number;
        indexed: number;
        warnings: number;
        day: string;
        source: string;
    } | null;
    published: number;
};
type Draft = {
    ulid: string;
    state: string;
    city: string;
    kind: string;
    status: string;
    title: string;
    description: string;
    body: string;
    note: string | null;
    faq: { q: string; a: string }[];
    facts: { fact: string; source: string }[];
    similarity: number;
};

const LEVELS = [
    '',
    'Starting: 3 a week',
    'Trusted: up to 1 a day',
    'Up to 4 a day',
];

/**
 * HQ → Content desk (MK3): turn writing on per state, see each state's level (unlocked by real Search Console
 * numbers, never by choice), and the morning review: read, edit, publish or reject each draft.
 */
export default function Content({
    desks,
    drafts,
    states,
    published,
}: {
    desks: Desk[];
    drafts: Draft[];
    states: Record<string, string>;
    published: { title: string; published_url: string; state: string }[];
}) {
    const [form, setForm] = useState({ state: 'TX', mode: 'test', cities: '' });
    const [open, setOpen] = useState<string | null>(null);
    const [edit, setEdit] = useState<Record<string, Partial<Draft>>>({});
    const review = drafts.filter((d) => d.status === 'review');
    const skipped = drafts.filter((d) => d.status === 'skipped');

    return (
        <>
            <Head title="Content desk" />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Content desk"
                    description="AI writes pages and articles for broker owners and agents, state by state. Nothing is published until a person approves it. Each state starts at 3 a week; more unlocks only when Google indexes the pages."
                />

                <section className="grid gap-3">
                    <h2 className="text-lg font-medium">States</h2>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left text-muted-foreground">
                                <th className="py-2">State</th>
                                <th>Mode</th>
                                <th>Level</th>
                                <th>Indexed</th>
                                <th>Published</th>
                            </tr>
                        </thead>
                        <tbody>
                            {desks.map((d) => (
                                <tr key={d.id} className="border-t">
                                    <td className="py-2">{d.name}</td>
                                    <td>{d.mode}</td>
                                    <td>
                                        {LEVELS[d.level]}
                                        {d.paused && (
                                            <Badge variant="destructive">
                                                Paused: {d.paused}
                                            </Badge>
                                        )}
                                    </td>
                                    <td>
                                        {d.indexed
                                            ? `${d.indexed.indexed} of ${d.indexed.submitted}${d.indexed.warnings > 0 ? `, ${d.indexed.warnings} warning(s)` : ''} (${d.indexed.day})`
                                            : 'Connect Search Console to unlock more'}
                                    </td>
                                    <td>{d.published}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <form
                        className="grid gap-2 rounded-md border p-3 text-sm sm:grid-cols-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.post('/hq/content/desks', form, {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <select
                            aria-label="State"
                            className="h-10 rounded-md border bg-background px-2"
                            value={form.state}
                            onChange={(e) =>
                                setForm({ ...form, state: e.target.value })
                            }
                        >
                            {Object.entries(states).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </select>
                        <select
                            aria-label="Mode"
                            className="h-10 rounded-md border bg-background px-2"
                            value={form.mode}
                            onChange={(e) =>
                                setForm({ ...form, mode: e.target.value })
                            }
                        >
                            <option value="off">Off</option>
                            <option value="test">
                                Test (approve saves as a draft)
                            </option>
                            <option value="live">Live</option>
                        </select>
                        <input
                            aria-label="Cities, most important first"
                            placeholder="Cities, most important first (optional)"
                            className="h-10 rounded-md border bg-background px-3"
                            value={form.cities}
                            onChange={(e) =>
                                setForm({ ...form, cities: e.target.value })
                            }
                        />
                        <Button type="submit">Save state</Button>
                    </form>
                </section>

                <section className="grid gap-3">
                    <h2 className="text-lg font-medium">
                        This morning's review ({review.length})
                    </h2>
                    {review.length === 0 && (
                        <p className="text-muted-foreground">
                            Nothing waiting. New drafts arrive each night.
                        </p>
                    )}
                    {review.map((d) => {
                        const e = edit[d.ulid] ?? {};
                        return (
                            <article
                                key={d.ulid}
                                className="grid gap-2 rounded-lg border p-4"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <h3 className="font-medium">{d.title}</h3>
                                    <span className="text-sm text-muted-foreground">
                                        {d.kind === 'landing'
                                            ? 'Landing page'
                                            : 'Article'}{' '}
                                        · {d.city}, {d.state} ·{' '}
                                        {Math.round(d.similarity * 100)}% like
                                        anything else
                                    </span>
                                </div>
                                <p className="text-sm">{d.description}</p>
                                <Button
                                    variant="ghost"
                                    className="justify-start"
                                    onClick={() =>
                                        setOpen(open === d.ulid ? null : d.ulid)
                                    }
                                >
                                    {open === d.ulid ? 'Hide' : 'Read and edit'}
                                </Button>
                                {open === d.ulid && (
                                    <div className="grid gap-2 text-sm">
                                        <input
                                            aria-label="Title"
                                            className="h-10 rounded-md border bg-background px-3"
                                            value={e.title ?? d.title}
                                            onChange={(ev) =>
                                                setEdit({
                                                    ...edit,
                                                    [d.ulid]: {
                                                        ...e,
                                                        title: ev.target.value,
                                                    },
                                                })
                                            }
                                        />
                                        <textarea
                                            aria-label="Description"
                                            className="rounded-md border bg-background p-2"
                                            rows={2}
                                            value={
                                                e.description ?? d.description
                                            }
                                            onChange={(ev) =>
                                                setEdit({
                                                    ...edit,
                                                    [d.ulid]: {
                                                        ...e,
                                                        description:
                                                            ev.target.value,
                                                    },
                                                })
                                            }
                                        />
                                        <textarea
                                            aria-label="Text"
                                            className="rounded-md border bg-background p-2 font-mono"
                                            rows={14}
                                            value={e.body ?? d.body}
                                            onChange={(ev) =>
                                                setEdit({
                                                    ...edit,
                                                    [d.ulid]: {
                                                        ...e,
                                                        body: ev.target.value,
                                                    },
                                                })
                                            }
                                        />
                                        <h4 className="font-medium">
                                            Facts to check
                                        </h4>
                                        <ul className="list-disc pl-5">
                                            {d.facts.map((f, i) => (
                                                <li key={i}>
                                                    {f.fact}{' '}
                                                    <a
                                                        className="underline"
                                                        href={f.source}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                    >
                                                        source
                                                    </a>
                                                </li>
                                            ))}
                                        </ul>
                                        <h4 className="font-medium">
                                            Questions
                                        </h4>
                                        <ul className="list-disc pl-5">
                                            {d.faq.map((q, i) => (
                                                <li key={i}>
                                                    <strong>{q.q}</strong> {q.a}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        onClick={() =>
                                            router.post(
                                                `/hq/content/drafts/${d.ulid}/approve`,
                                                {
                                                    title: e.title ?? '',
                                                    description:
                                                        e.description ?? '',
                                                    body: e.body ?? '',
                                                },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Approve and publish
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                `/hq/content/drafts/${d.ulid}/reject`,
                                                {
                                                    why:
                                                        window.prompt(
                                                            'Why? (helps the writer)',
                                                        ) ?? '',
                                                },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Reject
                                    </Button>
                                </div>
                            </article>
                        );
                    })}
                </section>

                {skipped.length > 0 && (
                    <section className="grid gap-1 text-sm">
                        <h2 className="text-lg font-medium">
                            Skipped (did not pass the checks)
                        </h2>
                        {skipped.map((d) => (
                            <p key={d.ulid} className="text-muted-foreground">
                                {d.title} — {d.note}
                            </p>
                        ))}
                    </section>
                )}

                <section className="grid gap-1 text-sm">
                    <h2 className="text-lg font-medium">Recently published</h2>
                    {published.map((p) => (
                        <a
                            key={p.published_url}
                            className="underline"
                            href={p.published_url}
                            target="_blank"
                            rel="noopener"
                        >
                            {p.title} ({p.state})
                        </a>
                    ))}
                </section>
            </div>
        </>
    );
}
