import { Head, router } from '@inertiajs/react';

import { Button } from '@/components/ui/button';

type Hit = { slug: string; title: string; summary: string };

/** One help article. The HTML is made on the server from our own Markdown, with any raw HTML escaped. */
export default function HelpArticle({
    article,
    voted,
    related,
}: {
    article: {
        slug: string;
        title: string;
        section: string;
        summary: string;
        html: string;
    };
    voted: boolean | null;
    related: Hit[];
}) {
    const vote = (helpful: boolean) =>
        router.post(
            `/help/${article.slug}/feedback`,
            { helpful },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title={article.title} />
            <article className="mx-auto grid w-full max-w-3xl gap-4 p-4 text-base leading-relaxed md:p-6">
                <nav aria-label="Breadcrumb" className="text-sm">
                    <a href="/help" className="text-primary underline">
                        Help
                    </a>{' '}
                    › {article.section}
                </nav>
                <h1 className="text-2xl font-semibold">{article.title}</h1>
                <p className="text-muted-foreground">{article.summary}</p>
                <div
                    className="prose dark:prose-invert max-w-none"
                    dangerouslySetInnerHTML={{ __html: article.html }}
                />
                <div className="flex flex-wrap items-center gap-2 border-t pt-3">
                    <span>Did this help?</span>
                    <Button
                        size="sm"
                        variant={voted === true ? 'default' : 'outline'}
                        onClick={() => vote(true)}
                    >
                        Yes
                    </Button>
                    <Button
                        size="sm"
                        variant={voted === false ? 'default' : 'outline'}
                        onClick={() => vote(false)}
                    >
                        No
                    </Button>
                    {voted === false && (
                        <a href="/help#ask" className="text-primary underline">
                            Ask a person instead
                        </a>
                    )}
                </div>
                {related.length > 0 && (
                    <section className="grid gap-1">
                        <h2 className="font-semibold">Related</h2>
                        {related.map((r) => (
                            <a
                                key={r.slug}
                                href={`/help/${r.slug}`}
                                className="text-primary underline"
                            >
                                {r.title}
                            </a>
                        ))}
                    </section>
                )}
            </article>
        </>
    );
}
