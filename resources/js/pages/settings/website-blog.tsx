import { Head, Link } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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

const stateLabel = { draft: 'Draft', live: 'Live', scheduled: 'Scheduled' };

/** Settings → Website → blog: every post with its state and search/AI-answer score. */
export default function WebsiteBlog({ posts }: { posts: Post[] }) {
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
                    <Button asChild className="ml-auto">
                        <Link href="/settings/website/blog/new">New post</Link>
                    </Button>
                </div>
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
            </div>
        </>
    );
}
