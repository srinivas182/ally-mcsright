import { Head, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Video = { path: string; title: string; url: string };

/** HQ → Help videos (SX5): the 60-second "how this page works" video people see under Help on each page. */
export default function HelpVideos({
    videos,
    pages,
}: {
    videos: Video[];
    pages: string[];
}) {
    const form = useForm({ path: '/contacts', title: '', url: '' });
    const byPath = Object.fromEntries(videos.map((v) => [v.path, v]));

    return (
        <>
            <Head title="Help videos" />
            <div className="mx-auto grid w-full max-w-4xl gap-4 p-4 md:p-6">
                <PageHeader
                    title="Help videos"
                    description="One short video per page (about a minute). Use a YouTube or Vimeo link, or an https link to an MP4. Leave the link empty to remove one."
                />
                <form
                    className="grid gap-3 rounded-lg border p-4 md:grid-cols-[10rem_1fr_1fr_auto] md:items-end"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/hq/support/videos', {
                            preserveScroll: true,
                        });
                    }}
                >
                    <label className="grid gap-1 text-sm">
                        Page
                        <select
                            className="h-10 rounded-md border bg-background px-2"
                            value={form.data.path}
                            onChange={(e) => {
                                const v = byPath[e.target.value];
                                form.setData({
                                    path: e.target.value,
                                    title: v?.title ?? '',
                                    url: v?.url ?? '',
                                });
                            }}
                        >
                            {pages.map((p) => (
                                <option key={p} value={p}>
                                    {p}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1 text-sm">
                        Title
                        <input
                            className="h-10 rounded-md border bg-background px-3"
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                            placeholder="How this page works"
                        />
                    </label>
                    <label className="grid gap-1 text-sm">
                        Video link
                        <input
                            className="h-10 rounded-md border bg-background px-3"
                            value={form.data.url}
                            onChange={(e) =>
                                form.setData('url', e.target.value)
                            }
                            placeholder="https://www.youtube.com/watch?v=…"
                        />
                        {form.errors.url && (
                            <span className="text-destructive">
                                {form.errors.url}
                            </span>
                        )}
                    </label>
                    <Button type="submit" disabled={form.processing}>
                        Save
                    </Button>
                </form>
                <table className="w-full text-sm">
                    <thead>
                        <tr className="text-left text-muted-foreground">
                            <th className="py-2">Page</th>
                            <th>Video</th>
                        </tr>
                    </thead>
                    <tbody>
                        {pages.map((p) => (
                            <tr key={p} className="border-t">
                                <td className="py-2 font-mono">{p}</td>
                                <td>
                                    {byPath[p] ? (
                                        <a
                                            href={byPath[p].url}
                                            className="underline"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            {byPath[p].title}
                                        </a>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            No video yet
                                        </span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </>
    );
}
