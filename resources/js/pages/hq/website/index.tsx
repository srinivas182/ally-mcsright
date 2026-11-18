import { Head, Link } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Props = {
    host: string;
    pages: {
        ulid: string;
        locale: string;
        slug: string;
        title: string;
        status: string;
        has_draft: boolean;
        published_at: string | null;
        twin: boolean;
    }[];
    posts: {
        ulid: string;
        locale: string;
        slug: string;
        title: string;
        status: string;
        published_at: string | null;
    }[];
};

/** SG1 (specs/marketing/01): RightAlly's own website, edited from HQ: pages (English and Spanish) and the blog. */
export default function HqWebsite({ host, pages, posts }: Props) {
    return (
        <>
            <Head title="RightAlly website" />
            <div className="mx-auto grid w-full max-w-5xl gap-8 p-4 md:p-6">
                <PageHeader
                    title="RightAlly website"
                    description={`The public website at ${host}. Pages are edited as private drafts, previewed, then published. Every page is checked for search and AI answers.`}
                />
                <section className="grid gap-3 text-base">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-lg font-semibold">Pages</h2>
                        <Button asChild>
                            <Link href="/hq/website/pages/new">New page</Link>
                        </Button>
                    </div>
                    {pages.length === 0 && (
                        <p className="text-muted-foreground">
                            No pages yet. Run{' '}
                            <code>php artisan marketing:starter</code> to add
                            the starter pages, or add one.
                        </p>
                    )}
                    <ul className="divide-y rounded-lg border">
                        {pages.map((p) => (
                            <li
                                key={p.ulid}
                                className="flex flex-wrap items-center gap-3 p-3"
                            >
                                <Link
                                    href={`/hq/website/pages/${p.ulid}`}
                                    className="grow font-medium underline-offset-4 hover:underline"
                                >
                                    {p.title}
                                    <span className="block text-sm font-normal text-muted-foreground">
                                        /{p.locale === 'es' ? 'es/' : ''}
                                        {p.slug}
                                    </span>
                                </Link>
                                <Badge variant="outline">
                                    {p.locale === 'es' ? 'Español' : 'English'}
                                </Badge>
                                <Badge
                                    variant={
                                        p.status === 'live'
                                            ? 'default'
                                            : 'outline'
                                    }
                                >
                                    {p.status === 'live' ? 'Live' : 'Draft'}
                                </Badge>
                                {p.has_draft && p.status === 'live' && (
                                    <Badge variant="secondary">
                                        Changes not published
                                    </Badge>
                                )}
                                {!p.twin && (
                                    <span className="text-sm text-amber-700 dark:text-amber-400">
                                        No{' '}
                                        {p.locale === 'en'
                                            ? 'Spanish'
                                            : 'English'}{' '}
                                        version
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
                <section className="grid gap-3 text-base">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-lg font-semibold">Blog</h2>
                        <Button asChild variant="outline">
                            <Link href="/hq/website/posts/new">New post</Link>
                        </Button>
                    </div>
                    {posts.length === 0 ? (
                        <p className="text-muted-foreground">No posts yet.</p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {posts.map((p) => (
                                <li
                                    key={p.ulid}
                                    className="flex flex-wrap items-center gap-3 p-3"
                                >
                                    <Link
                                        href={`/hq/website/posts/${p.ulid}`}
                                        className="grow font-medium underline-offset-4 hover:underline"
                                    >
                                        {p.title}
                                    </Link>
                                    <Badge variant="outline">
                                        {p.locale === 'es'
                                            ? 'Español'
                                            : 'English'}
                                    </Badge>
                                    <Badge
                                        variant={
                                            p.status === 'live'
                                                ? 'default'
                                                : 'outline'
                                        }
                                    >
                                        {p.status === 'live' ? 'Live' : 'Draft'}
                                    </Badge>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}
