import { Head, Link, useForm } from '@inertiajs/react';

import { confirmCredits } from '@/components/credit-consent';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Post = {
    ulid: string;
    title: string;
    slug: string;
    category: string | null;
    author: string | null;
    state: 'draft' | 'live' | 'scheduled';
    published_at: string | null;
    score: number;
};
type Props = {
    posts: Post[];
    places: { slug: string; name: string }[];
    market_updates: { enabled: boolean; places: string[] };
};

const stateLabel = { draft: 'Draft', live: 'Live', scheduled: 'Scheduled' };
const opts = { preserveScroll: true };

/** Settings → Website → blog: posts with their score, the AI writer and monthly market updates. */
export default function WebsiteBlog({ posts, places, market_updates }: Props) {
    return (
        <>
            <Head title="Blog" />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Blog"
                    description="Local market updates and guides bring visitors from Google and AI assistants. Each post shows its search and AI-answer score."
                />
                <div className="flex items-center gap-2">
                    <Link
                        href="/settings/website"
                        className="text-sm text-muted-foreground"
                    >
                        ← Website
                    </Link>
                    <Button asChild className="ml-auto" variant="outline">
                        <Link href="/settings/website/blog/new">New post</Link>
                    </Button>
                </div>
                <AiWriter places={places} />
                {posts.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No posts yet. Start with the question buyers ask you
                        most.
                    </p>
                ) : (
                    <ul className="grid gap-2 text-sm">
                        {posts.map((p) => (
                            <li
                                key={p.ulid}
                                className="flex flex-wrap items-center gap-2 rounded-lg border p-3"
                            >
                                <Link
                                    href={`/settings/website/blog/${p.ulid}`}
                                    className="font-medium hover:underline"
                                >
                                    {p.title}
                                </Link>
                                <Badge
                                    variant={
                                        p.state === 'live'
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                >
                                    {stateLabel[p.state]}
                                </Badge>
                                {p.category && (
                                    <span className="text-muted-foreground">
                                        {p.category}
                                    </span>
                                )}
                                <span className="ml-auto text-xs text-muted-foreground">
                                    {p.published_at
                                        ? new Date(
                                              p.published_at,
                                          ).toLocaleDateString()
                                        : ''}{' '}
                                    {p.author ? `· ${p.author}` : ''}
                                </span>
                                <Badge variant="outline">Score {p.score}</Badge>
                            </li>
                        ))}
                    </ul>
                )}
                <MarketUpdates places={places} settings={market_updates} />
            </div>
        </>
    );
}

function AiWriter({ places }: { places: Props['places'] }) {
    const form = useForm({
        topic: '',
        audience: '',
        tone: 'friendly',
        length: 'medium',
        area: '',
        keywords: '',
    });

    return (
        <form
            className="grid gap-3 rounded-lg border p-4 text-sm"
            onSubmit={(e) => {
                e.preventDefault();
                void confirmCredits('ai.site_post').then(
                    (ok) => ok && form.post('/settings/website/ai/post'),
                );
            }}
        >
            <h2 className="font-semibold">Write a post with AI</h2>
            <p className="text-muted-foreground">
                AI writes a draft from your brief, using only real figures from
                your MLS data. It checks the draft for search and AI answers and
                for Fair Housing wording. Nothing is published until you choose
                to. Uses AI credits.
            </p>
            <Input
                aria-label="Topic"
                placeholder="Topic, e.g. How much are closing costs in Austin?"
                value={form.data.topic}
                onChange={(e) => form.setData('topic', e.target.value)}
            />
            <div className="grid gap-3 sm:grid-cols-2">
                <Input
                    aria-label="Readers"
                    placeholder="Readers, e.g. first-time buyers"
                    value={form.data.audience}
                    onChange={(e) => form.setData('audience', e.target.value)}
                />
                <Input
                    aria-label="Keywords"
                    placeholder="Keywords (optional)"
                    value={form.data.keywords}
                    onChange={(e) => form.setData('keywords', e.target.value)}
                />
                <select
                    aria-label="Area figures"
                    className="rounded-md border bg-background px-2 py-2"
                    value={form.data.area}
                    onChange={(e) => form.setData('area', e.target.value)}
                >
                    <option value="">No market figures</option>
                    {places.map((p) => (
                        <option key={p.slug} value={p.slug}>
                            Use figures for {p.name}
                        </option>
                    ))}
                </select>
                <div className="flex gap-2">
                    <select
                        aria-label="Tone"
                        className="flex-1 rounded-md border bg-background px-2"
                        value={form.data.tone}
                        onChange={(e) => form.setData('tone', e.target.value)}
                    >
                        <option value="friendly">Friendly</option>
                        <option value="expert">Expert</option>
                        <option value="luxury">Luxury</option>
                    </select>
                    <select
                        aria-label="Length"
                        className="flex-1 rounded-md border bg-background px-2"
                        value={form.data.length}
                        onChange={(e) => form.setData('length', e.target.value)}
                    >
                        <option value="short">Short</option>
                        <option value="medium">Medium</option>
                        <option value="long">Long</option>
                    </select>
                </div>
            </div>
            {Object.values(form.errors).map((m) => (
                <p key={m} className="text-destructive">
                    {m}
                </p>
            ))}
            <div>
                <Button
                    disabled={form.processing || form.data.topic.trim() === ''}
                >
                    {form.processing
                        ? 'Writing… (up to a minute)'
                        : 'Write draft'}
                </Button>
            </div>
        </form>
    );
}

function MarketUpdates({
    places,
    settings,
}: {
    places: Props['places'];
    settings: Props['market_updates'];
}) {
    const form = useForm({
        enabled: settings.enabled,
        places: settings.places,
    });
    const toggle = (slug: string, on: boolean) =>
        form.setData(
            'places',
            on
                ? [...form.data.places, slug].slice(0, 10)
                : form.data.places.filter((p) => p !== slug),
        );

    return (
        <form
            className="grid gap-3 rounded-lg border p-4 text-sm"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/settings/website/ai/market-updates', opts);
            }}
        >
            <h2 className="font-semibold">Monthly market updates</h2>
            <p className="text-muted-foreground">
                On the 1st of each month, AI drafts a market update for each
                area you choose from that month&apos;s MLS figures, and we email
                you to review it. Up to 10 areas. Uses AI credits.
            </p>
            <label className="flex items-center gap-2">
                <input
                    type="checkbox"
                    checked={form.data.enabled}
                    onChange={(e) => form.setData('enabled', e.target.checked)}
                />{' '}
                Draft monthly market updates
            </label>
            {places.length === 0 ? (
                <p className="text-muted-foreground">
                    Areas appear once your MLS listings are connected.
                </p>
            ) : (
                <div className="flex max-h-48 flex-wrap gap-3 overflow-y-auto">
                    {places.map((p) => (
                        <label key={p.slug} className="flex items-center gap-1">
                            <input
                                type="checkbox"
                                checked={form.data.places.includes(p.slug)}
                                onChange={(e) =>
                                    toggle(p.slug, e.target.checked)
                                }
                            />{' '}
                            {p.name}
                        </label>
                    ))}
                </div>
            )}
            {Object.values(form.errors).map((m) => (
                <p key={m} className="text-destructive">
                    {m}
                </p>
            ))}
            <div>
                <Button variant="outline" disabled={form.processing}>
                    Save
                </Button>
            </div>
        </form>
    );
}
