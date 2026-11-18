import { Head, Link, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { Textarea } from '@/components/ui/textarea';

type Props = {
    post: {
        ulid: string;
        locale: string;
        slug: string;
        title: string;
        description: string;
        body: string;
        author: string;
        status: string;
        published_at: string | null;
    } | null;
    locales: Record<string, string>;
};

/** SG1: write a RightAlly blog post in Markdown, save it as a draft or publish it (now or at a set time). */
export default function HqWebsitePost({ post, locales }: Props) {
    const form = useForm({
        locale: post?.locale ?? 'en',
        slug: post?.slug ?? '',
        title: post?.title ?? '',
        description: post?.description ?? '',
        body: post?.body ?? '',
        author: post?.author ?? '',
        publish_at: post?.published_at?.slice(0, 16) ?? '',
        live: post?.status === 'live',
    });
    const save = (live: boolean) => {
        form.transform((d) => ({ ...d, live }));
        if (post) {
            form.put(`/hq/website/posts/${post.ulid}`, {
                preserveScroll: true,
            });
        } else {
            form.post('/hq/website/posts', { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title={post ? `Edit: ${post.title}` : 'New post'} />
            <div className="mx-auto grid w-full max-w-3xl gap-4 p-4 text-base md:p-6">
                <p>
                    <Link href="/hq/website" className="underline">
                        ← RightAlly website
                    </Link>
                </p>
                <PageHeader
                    title={post ? post.title : 'New post'}
                    description="Write in Markdown: ## for headings, - for lists, [text](https://…) for links."
                />
                <div className="grid gap-3 md:grid-cols-2">
                    <label className="grid gap-1">
                        Language
                        <select
                            className="h-10 rounded-md border bg-background px-3"
                            value={form.data.locale}
                            onChange={(e) =>
                                form.setData('locale', e.target.value)
                            }
                        >
                            {Object.entries(locales).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1">
                        Address
                        <Input
                            value={form.data.slug}
                            placeholder="how-to-set-a-commission-cap"
                            onChange={(e) =>
                                form.setData('slug', e.target.value)
                            }
                        />
                    </label>
                </div>
                <label className="grid gap-1">
                    Title
                    <Input
                        value={form.data.title}
                        maxLength={110}
                        onChange={(e) => form.setData('title', e.target.value)}
                    />
                </label>
                <label className="grid gap-1">
                    Description ({form.data.description.length}/160)
                    <Textarea
                        value={form.data.description}
                        maxLength={170}
                        onChange={(e) =>
                            form.setData('description', e.target.value)
                        }
                    />
                </label>
                <label className="grid gap-1">
                    Author
                    <Input
                        value={form.data.author}
                        onChange={(e) => form.setData('author', e.target.value)}
                    />
                </label>
                <label className="grid gap-1">
                    Post
                    <Textarea
                        className="min-h-80 font-mono"
                        value={form.data.body}
                        onChange={(e) => form.setData('body', e.target.value)}
                    />
                </label>
                <label className="grid max-w-xs gap-1">
                    Publish at (leave empty for now)
                    <Input
                        type="datetime-local"
                        value={form.data.publish_at}
                        onChange={(e) =>
                            form.setData('publish_at', e.target.value)
                        }
                    />
                </label>
                <div className="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        disabled={form.processing}
                        onClick={() => save(false)}
                    >
                        Save draft
                    </Button>
                    <Button
                        disabled={form.processing}
                        onClick={() => save(true)}
                    >
                        Publish
                    </Button>
                </div>
                {Object.values(form.errors)[0] && (
                    <p role="alert" className="text-destructive">
                        {Object.values(form.errors)[0]}
                    </p>
                )}
            </div>
        </>
    );
}
