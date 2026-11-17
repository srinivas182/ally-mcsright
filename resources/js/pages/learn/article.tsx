import { Head, router } from '@inertiajs/react';

import { Button } from '@/components/ui/button';

/** One knowledge base page (LN1). Required pages ask each person to confirm they have read the current version. */
export default function LearnArticle({
    article,
    needs_ack,
    manages,
}: {
    article: {
        ulid: string;
        title: string;
        category: string;
        html: string;
        required: boolean;
        version: number;
        updated: string;
    };
    needs_ack: boolean;
    manages: boolean;
}) {
    return (
        <>
            <Head title={article.title} />
            <article className="mx-auto grid w-full max-w-3xl gap-4 p-4 text-base leading-relaxed md:p-6">
                <nav aria-label="Breadcrumb" className="text-sm">
                    <a href="/learn" className="text-primary underline">
                        Learn
                    </a>{' '}
                    › {article.category}
                </nav>
                <h1 className="text-2xl font-semibold">{article.title}</h1>
                <p className="text-sm text-muted-foreground">
                    Updated {article.updated} · version {article.version}
                    {manages && (
                        <>
                            {' · '}
                            <a
                                className="underline"
                                href={`/learn/manage/kb/${article.ulid}`}
                            >
                                Edit
                            </a>
                        </>
                    )}
                </p>
                <div
                    className="prose dark:prose-invert max-w-none"
                    dangerouslySetInnerHTML={{ __html: article.html }}
                />
                {article.required && (
                    <div className="rounded border p-3">
                        {needs_ack ? (
                            <Button
                                onClick={() =>
                                    router.post(
                                        `/learn/kb/${article.ulid}/acknowledge`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                I have read and understood this page
                            </Button>
                        ) : (
                            <p className="text-green-800">
                                You confirmed you read this version. Thank you.
                            </p>
                        )}
                    </div>
                )}
            </article>
        </>
    );
}
