import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { MediaPicker, type MediaItem } from '@/components/sites/media-picker';
import { SeoScore, type SeoChecks } from '@/components/sites/seo-score';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { postJson } from '@/lib/http';

type Post = {
    ulid: string;
    title: string;
    slug: string;
    excerpt: string | null;
    body: string;
    category: string | null;
    cover_media: string | null;
    status: 'draft' | 'published';
    published_at: string | null;
    seo_title: string | null;
    meta_description: string | null;
    noindex: boolean;
    live: boolean;
};
type Props = {
    post: Post | null;
    check: SeoChecks | null;
    preview_url: string | null;
    live_url: string | null;
    categories: string[];
    media: MediaItem[];
};

const local = (iso: string | null) =>
    iso
        ? new Date(
              new Date(iso).getTime() - new Date().getTimezoneOffset() * 60000,
          )
              .toISOString()
              .slice(0, 16)
        : '';

/** Settings → Website → blog post: Markdown with a live search and AI-answer check, scheduling, cover image. */
export default function WebsitePost({
    post,
    check: initial,
    preview_url,
    live_url,
    categories,
    media: initialMedia,
}: Props) {
    const form = useForm({
        title: post?.title ?? '',
        slug: post?.slug ?? '',
        excerpt: post?.excerpt ?? '',
        body:
            post?.body ??
            '## The short answer\n\nStart with a direct answer in two or three sentences.\n\n## The details\n\n',
        category: post?.category ?? '',
        cover_media: post?.cover_media ?? null,
        status: post?.status ?? 'draft',
        published_at: local(post?.published_at ?? null),
        seo_title: post?.seo_title ?? '',
        meta_description: post?.meta_description ?? '',
        noindex: post?.noindex ?? false,
    });
    const [check, setCheck] = useState<SeoChecks | null>(initial);
    const [media, setMedia] = useState<MediaItem[]>(initialMedia);

    // Re-check as the post is written.
    useEffect(() => {
        if (form.data.title.trim() === '') {
            return;
        }
        const t = setTimeout(async () => {
            try {
                const res = await postJson<{ check: SeoChecks }>(
                    '/settings/website/blog/check',
                    {
                        ...form.data,
                        cover_media: form.data.cover_media ?? undefined,
                        published_at: undefined,
                    },
                );
                setCheck(res.check);
            } catch {
                // the next keystroke tries again
            }
        }, 900);
        return () => clearTimeout(t);
    }, [form.data]);

    const submit = (status: 'draft' | 'published') => {
        form.transform((d) => ({
            ...d,
            status,
            published_at: d.published_at
                ? new Date(d.published_at).toISOString()
                : null,
        }));
        if (post) {
            form.put(`/settings/website/blog/${post.ulid}`, {
                preserveScroll: true,
            });
        } else {
            form.post('/settings/website/blog');
        }
    };
    const scheduled =
        form.data.published_at !== '' &&
        new Date(form.data.published_at) > new Date();

    return (
        <>
            <Head title={post ? `Edit: ${post.title}` : 'New post'} />
            <div className="grid w-full gap-4 p-4 md:p-6">
                <div className="flex flex-wrap items-center gap-2">
                    <Link
                        href="/settings/website/blog"
                        className="text-sm text-muted-foreground"
                    >
                        ← Blog
                    </Link>
                    <div className="ml-auto flex flex-wrap gap-2">
                        {live_url && (
                            <Button asChild size="sm" variant="ghost">
                                <a
                                    href={live_url}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    View live
                                </a>
                            </Button>
                        )}
                        {preview_url && (
                            <Button asChild size="sm" variant="ghost">
                                <a
                                    href={preview_url}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Preview
                                </a>
                            </Button>
                        )}
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={form.processing}
                            onClick={() => submit('draft')}
                        >
                            Save draft
                        </Button>
                        <Button
                            size="sm"
                            disabled={form.processing}
                            onClick={() => submit('published')}
                        >
                            {scheduled
                                ? 'Schedule'
                                : post?.live
                                  ? 'Update'
                                  : 'Publish'}
                        </Button>
                    </div>
                </div>
                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="grid content-start gap-3 text-sm">
                        <Input
                            aria-label="Title"
                            className="text-lg font-semibold"
                            placeholder="Title: the question your reader is asking"
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                        />
                        <Textarea
                            aria-label="Post"
                            rows={24}
                            className="font-mono text-sm"
                            value={form.data.body}
                            onChange={(e) =>
                                form.setData('body', e.target.value)
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            Write in Markdown: ## for headings (phrase some as
                            questions), **bold**, - lists, [link text](/homes),
                            ![describe the image](https://…).
                        </p>
                        {Object.values(form.errors).map((m) => (
                            <p key={m} className="text-destructive">
                                {m}
                            </p>
                        ))}
                        {post && (
                            <Button
                                variant="ghost"
                                className="justify-self-start text-destructive"
                                onClick={() =>
                                    window.confirm('Delete this post?') &&
                                    router.delete(
                                        `/settings/website/blog/${post.ulid}`,
                                    )
                                }
                            >
                                Delete post
                            </Button>
                        )}
                    </div>
                    <div className="grid content-start gap-3 text-sm">
                        <div className="rounded-lg border p-3">
                            <SeoScore check={check} />
                        </div>
                        <div className="grid gap-2 rounded-lg border p-3">
                            <MediaPicker
                                label="Cover image"
                                value={form.data.cover_media}
                                media={media}
                                onChange={(v) => form.setData('cover_media', v)}
                                onUploaded={(m) => setMedia([m, ...media])}
                            />
                            <label className="grid gap-1 text-xs font-medium">
                                Category
                                <Input
                                    list="categories"
                                    value={form.data.category}
                                    onChange={(e) =>
                                        form.setData('category', e.target.value)
                                    }
                                />
                                <datalist id="categories">
                                    {categories.map((c) => (
                                        <option key={c} value={c} />
                                    ))}
                                </datalist>
                            </label>
                            <label className="grid gap-1 text-xs font-medium">
                                Summary (shown on the blog and in search
                                results)
                                <Textarea
                                    rows={3}
                                    maxLength={300}
                                    value={form.data.excerpt}
                                    onChange={(e) =>
                                        form.setData('excerpt', e.target.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-xs font-medium">
                                Publish date and time (later to schedule)
                                <Input
                                    type="datetime-local"
                                    value={form.data.published_at}
                                    onChange={(e) =>
                                        form.setData(
                                            'published_at',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                        </div>
                        <div className="grid gap-2 rounded-lg border p-3">
                            <h2 className="font-semibold">Search</h2>
                            <label className="grid gap-1 text-xs font-medium">
                                Address
                                <Input
                                    value={form.data.slug}
                                    placeholder="from the title"
                                    onChange={(e) =>
                                        form.setData('slug', e.target.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-xs font-medium">
                                Search title (optional)
                                <Input
                                    maxLength={70}
                                    value={form.data.seo_title}
                                    onChange={(e) =>
                                        form.setData(
                                            'seo_title',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-xs font-medium">
                                Search description (optional)
                                <Textarea
                                    rows={2}
                                    maxLength={300}
                                    value={form.data.meta_description}
                                    onChange={(e) =>
                                        form.setData(
                                            'meta_description',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="flex items-center gap-2 text-xs">
                                <input
                                    type="checkbox"
                                    checked={form.data.noindex}
                                    onChange={(e) =>
                                        form.setData(
                                            'noindex',
                                            e.target.checked,
                                        )
                                    }
                                />{' '}
                                Hide from search engines
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
