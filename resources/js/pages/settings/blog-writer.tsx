import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Desk = {
    id: number;
    agent: number | null;
    author: string | null;
    state: string;
    mode: string;
    level: number;
    allowance: { week: number; day: number };
    paused: string | null;
    cities: string;
    ideas: string;
    words: string;
};
type Draft = {
    ulid: string;
    city: string;
    status: string;
    title: string;
    description: string;
    body: string;
    note: string | null;
    faq: { q: string; a: string }[];
    facts: { fact: string; source: string }[];
};
type Props = {
    included: boolean;
    manager: boolean;
    hasSite: boolean;
    defaults: { state: string; cities: string };
    states: Record<string, string>;
    agents: { id: number; name: string }[];
    desks: Desk[];
    drafts: Draft[];
    published: { title: string; published_url: string }[];
};

const LEVELS = ['', 'Starting: 3 posts a week', 'Trusted: up to 1 a day'];
const opts = { preserveScroll: true };
const field = 'rounded-md border bg-background p-2 text-base';

/**
 * Settings → AI blog writer (MK4): local posts under your name, each one unique and approved by a person. Starts at
 * 3 a week and unlocks up to 1 a day only when Google indexes the posts.
 */
export default function BlogWriter(props: Props) {
    const { included, manager, hasSite, desks, drafts, published } = props;
    const review = drafts.filter((d) => d.status === 'review');
    const skipped = drafts.filter((d) => d.status === 'skipped');

    return (
        <>
            <Head title="AI blog writer" />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="AI blog writer"
                    description="Local articles for home buyers and sellers, written for your website under your name. You approve every post. Each one uses your own words or your area's market figures, so it is never the same as anyone else's."
                />
                {!included && (
                    <div className="rounded-lg border-2 border-primary/40 p-4 text-lg">
                        The AI blog writer is an extra.{' '}
                        {manager ? (
                            <Link
                                href="/settings/billing"
                                className="text-primary underline"
                            >
                                Add it under Billing → Extras
                            </Link>
                        ) : (
                            'Ask your broker to add it.'
                        )}
                    </div>
                )}
                {included && manager && !hasSite && (
                    <p className="text-lg">
                        First{' '}
                        <Link href="/settings/website" className="underline">
                            set up your website
                        </Link>
                        : posts appear on its blog.
                    </p>
                )}
                {included && (
                    <>
                        {desks.map((d) => (
                            <DeskForm key={d.id} desk={d} {...props} />
                        ))}
                        {manager && (
                            <DeskForm
                                desk={null}
                                {...props}
                                first={desks.length === 0}
                            />
                        )}
                    </>
                )}

                <section className="grid gap-3">
                    <h2 className="text-lg font-medium">
                        Waiting for approval ({review.length})
                    </h2>
                    {review.length === 0 && (
                        <p className="text-muted-foreground">
                            Nothing waiting. New posts arrive overnight.
                        </p>
                    )}
                    {review.map((d) => (
                        <DraftCard key={d.ulid} draft={d} manager={manager} />
                    ))}
                </section>

                {skipped.length > 0 && (
                    <section className="grid gap-1">
                        <h2 className="text-lg font-medium">
                            Days skipped, and why
                        </h2>
                        {skipped.map((d) => (
                            <p key={d.ulid} className="text-muted-foreground">
                                {d.note}
                            </p>
                        ))}
                    </section>
                )}

                {published.length > 0 && (
                    <section className="grid gap-1">
                        <h2 className="text-lg font-medium">Published</h2>
                        {published.map((p) => (
                            <a
                                key={p.published_url}
                                className="underline"
                                href={p.published_url}
                                target="_blank"
                                rel="noopener"
                            >
                                {p.title}
                            </a>
                        ))}
                    </section>
                )}
            </div>
        </>
    );
}

function DeskForm({
    desk,
    manager,
    defaults,
    states,
    agents,
    first = false,
}: Props & { desk: Desk | null; first?: boolean }) {
    const [open, setOpen] = useState(desk !== null || first);
    const [form, setForm] = useState({
        mode: desk?.mode ?? 'test',
        state: desk?.state ?? defaults.state,
        cities: desk?.cities ?? defaults.cities,
        ideas: desk?.ideas ?? '',
        words: desk?.words ?? '',
        agent: desk?.agent ?? (null as number | null),
    });
    const set = (k: keyof typeof form, v: string | number | null) =>
        setForm({ ...form, [k]: v });

    if (!open) {
        return (
            <Button variant="outline" onClick={() => setOpen(true)}>
                Add a blog writer for one of your agents
            </Button>
        );
    }

    return (
        <form
            className="grid gap-3 rounded-lg border p-4"
            onSubmit={(e) => {
                e.preventDefault();
                router.post('/settings/blog-writer', form, opts);
            }}
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-lg font-medium">
                    {desk === null
                        ? first
                            ? 'Your blog writer'
                            : 'A blog writer for an agent'
                        : desk.author
                          ? `Posts by ${desk.author}`
                          : 'Posts under the brokerage name'}
                </h2>
                {desk && (
                    <span className="flex gap-2">
                        <Badge variant="secondary">
                            {LEVELS[desk.level] ?? LEVELS[2]}
                        </Badge>
                        {desk.paused && (
                            <Badge variant="destructive">
                                Paused: {desk.paused}
                            </Badge>
                        )}
                    </span>
                )}
            </div>
            {manager && (
                <div className="grid gap-3 sm:grid-cols-3">
                    <label className="grid gap-1">
                        Status
                        <select
                            className={field}
                            value={form.mode}
                            onChange={(e) => set('mode', e.target.value)}
                        >
                            <option value="off">Off</option>
                            <option value="test">
                                Practice (approved posts stay drafts)
                            </option>
                            <option value="live">On</option>
                        </select>
                    </label>
                    <label className="grid gap-1">
                        State
                        <select
                            className={field}
                            value={form.state}
                            onChange={(e) => set('state', e.target.value)}
                        >
                            <option value="">Choose…</option>
                            {Object.entries(states).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </select>
                    </label>
                    {desk === null && !first ? (
                        <label className="grid gap-1">
                            Agent
                            <select
                                className={field}
                                value={form.agent ?? ''}
                                onChange={(e) =>
                                    set(
                                        'agent',
                                        e.target.value === ''
                                            ? null
                                            : Number(e.target.value),
                                    )
                                }
                            >
                                <option value="">Choose…</option>
                                {agents.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    ) : (
                        <span />
                    )}
                    <label className="grid gap-1 sm:col-span-3">
                        Towns and cities you work in (comma between each)
                        <input
                            className={field}
                            value={form.cities}
                            onChange={(e) => set('cities', e.target.value)}
                        />
                    </label>
                </div>
            )}
            <label className="grid gap-1">
                Ideas for posts (one per line; each is used once)
                <textarea
                    className={field}
                    rows={4}
                    placeholder="What sellers on the east side ask me most"
                    value={form.ideas}
                    onChange={(e) => set('ideas', e.target.value)}
                />
            </label>
            <label className="grid gap-1">
                In your own words: what you know about your area that others
                don't (tap the microphone on your phone's keyboard to speak it)
                <textarea
                    className={field}
                    rows={5}
                    value={form.words}
                    onChange={(e) => set('words', e.target.value)}
                />
            </label>
            <Button type="submit" size="lg" className="justify-self-start">
                Save
            </Button>
        </form>
    );
}

function DraftCard({ draft: d, manager }: { draft: Draft; manager: boolean }) {
    const [open, setOpen] = useState(false);
    const [e, setE] = useState<Partial<Draft>>({});

    return (
        <article className="grid gap-2 rounded-lg border p-4">
            <h3 className="text-lg font-medium">{d.title}</h3>
            <p className="text-muted-foreground">{d.description}</p>
            <Button
                variant="ghost"
                className="justify-start"
                onClick={() => setOpen(!open)}
            >
                {open ? 'Hide' : 'Read and edit'}
            </Button>
            {open && (
                <div className="grid gap-2">
                    <input
                        aria-label="Title"
                        className={field}
                        value={e.title ?? d.title}
                        onChange={(ev) =>
                            setE({ ...e, title: ev.target.value })
                        }
                    />
                    <textarea
                        aria-label="Text"
                        className={field}
                        rows={14}
                        value={e.body ?? d.body}
                        onChange={(ev) => setE({ ...e, body: ev.target.value })}
                    />
                    <h4 className="font-medium">Facts to check</h4>
                    <ul className="list-disc pl-5">
                        {d.facts.map((f, i) => (
                            <li key={i}>
                                {f.fact} —{' '}
                                {f.source.startsWith('https://') ? (
                                    <a
                                        className="underline"
                                        href={f.source}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        source
                                    </a>
                                ) : f.source === 'mls-figures' ? (
                                    'your MLS figures'
                                ) : (
                                    'your own words'
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {manager ? (
                <div className="flex flex-wrap gap-2">
                    <Button
                        size="lg"
                        onClick={() =>
                            router.post(
                                `/settings/blog-writer/drafts/${d.ulid}/approve`,
                                { title: e.title ?? '', body: e.body ?? '' },
                                opts,
                            )
                        }
                    >
                        Approve and publish
                    </Button>
                    <Button
                        size="lg"
                        variant="outline"
                        onClick={() =>
                            router.post(
                                `/settings/blog-writer/drafts/${d.ulid}/reject`,
                                {
                                    why:
                                        window.prompt(
                                            'Why? (helps the writer)',
                                        ) ?? '',
                                },
                                opts,
                            )
                        }
                    >
                        Not this one
                    </Button>
                </div>
            ) : (
                <p className="text-sm text-muted-foreground">
                    Your broker approves posts before they go on the website.
                </p>
            )}
        </article>
    );
}
