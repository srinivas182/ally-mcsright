import { Head, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

/** Write or change a knowledge base page (LN1). Markdown: **bold**, - lists, # headings, [links](https://…). */
export default function EditArticle({
    article,
    categories,
}: {
    article: {
        ulid: string;
        title: string;
        category: string;
        body: string;
        required: boolean;
        status: string;
    } | null;
    categories: string[];
}) {
    const form = useForm({
        title: article?.title ?? '',
        category: article?.category ?? '',
        body: article?.body ?? '',
        required: article?.required ?? false,
        status: article?.status ?? 'published',
    });

    return (
        <>
            <Head title={article ? `Edit ${article.title}` : 'New page'} />
            <form
                className="mx-auto grid w-full max-w-3xl gap-3 p-4 text-base md:p-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(
                        article
                            ? `/learn/manage/kb/${article.ulid}`
                            : '/learn/manage/kb',
                    );
                }}
            >
                <PageHeader
                    title={article ? 'Edit page' : 'New knowledge base page'}
                    description="Write it the way you would explain it to a new agent. Changing a required page asks everyone to read it again."
                />
                <label className="grid gap-1">
                    Title
                    <Input
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                    />
                </label>
                <label className="grid gap-1">
                    Category
                    <Input
                        list="kb-categories"
                        placeholder="e.g. Office, Vendors, Scripts"
                        value={form.data.category}
                        onChange={(e) =>
                            form.setData('category', e.target.value)
                        }
                    />
                    <datalist id="kb-categories">
                        {categories.map((c) => (
                            <option key={c} value={c} />
                        ))}
                    </datalist>
                </label>
                <label className="grid gap-1">
                    Page
                    <textarea
                        rows={16}
                        className="rounded border px-3 py-2 font-mono text-sm"
                        value={form.data.body}
                        onChange={(e) => form.setData('body', e.target.value)}
                    />
                </label>
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        className="size-5"
                        checked={form.data.required}
                        onChange={(e) =>
                            form.setData('required', e.target.checked)
                        }
                    />
                    Everyone must read and confirm this page
                </label>
                <label className="flex items-center gap-2">
                    Status
                    <select
                        className="rounded border px-2 py-1"
                        value={form.data.status}
                        onChange={(e) => form.setData('status', e.target.value)}
                    >
                        <option value="published">Published</option>
                        <option value="draft">Draft</option>
                        <option value="archived">Archived</option>
                    </select>
                </label>
                {Object.values(form.errors).map((e) => (
                    <p key={e} role="alert" className="text-red-700">
                        {e}
                    </p>
                ))}
                <Button
                    className="justify-self-start"
                    disabled={form.processing}
                >
                    Save page
                </Button>
            </form>
        </>
    );
}
