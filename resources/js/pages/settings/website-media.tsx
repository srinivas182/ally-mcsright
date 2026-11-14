import { Head, Link, router } from '@inertiajs/react';
import { useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { postForm, sendJson } from '@/lib/http';

type Media = {
    ulid: string;
    thumb: string;
    url: string;
    alt: string;
    name: string | null;
    width: number;
    height: number;
    kb: number;
    used: number;
    uploaded: string | null;
};

/** Settings → Website → media library: every image as WebP in three sizes, with alt text and where it is used. */
export default function WebsiteMedia({ media }: { media: Media[] }) {
    const file = useRef<HTMLInputElement>(null);
    const [alt, setAlt] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const reload = () => router.reload({ only: ['media'] });

    const upload = async (e: React.FormEvent) => {
        e.preventDefault();
        const f = file.current?.files?.[0];
        if (!f || alt.trim() === '') {
            setError('Choose an image and describe what it shows.');
            return;
        }
        setBusy(true);
        setError(null);
        try {
            const data = new FormData();
            data.append('file', f);
            data.append('alt', alt);
            await postForm('/settings/website/media', data);
            setAlt('');
            if (file.current) {
                file.current.value = '';
            }
            reload();
        } catch (x) {
            setError(x instanceof Error ? x.message : 'Upload failed.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <Head title="Media library" />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Media library"
                    description="Images for your website. Each upload is saved as a fast WebP image in three sizes, with location data removed. Describe each image: it helps people using screen readers and search engines."
                />
                <Link
                    href="/settings/website"
                    className="text-sm text-muted-foreground"
                >
                    ← Website
                </Link>
                <form
                    className="flex flex-wrap items-center gap-2 rounded-lg border p-4 text-sm"
                    onSubmit={upload}
                >
                    <input
                        ref={file}
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        aria-label="Image file"
                    />
                    <Input
                        aria-label="Describe the image"
                        className="max-w-sm"
                        placeholder="Describe the image, e.g. Kitchen with marble island"
                        value={alt}
                        onChange={(e) => setAlt(e.target.value)}
                    />
                    <Button disabled={busy}>
                        {busy ? 'Uploading…' : 'Upload'}
                    </Button>
                    {error && (
                        <p className="w-full text-destructive">{error}</p>
                    )}
                </form>
                {media.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No images yet.
                    </p>
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {media.map((m) => (
                            <MediaCard key={m.ulid} m={m} onChanged={reload} />
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

function MediaCard({ m, onChanged }: { m: Media; onChanged: () => void }) {
    const [alt, setAlt] = useState(m.alt);
    const [error, setError] = useState<string | null>(null);

    return (
        <li className="grid gap-2 rounded-lg border p-2 text-sm">
            <a href={m.url} target="_blank" rel="noreferrer">
                <img
                    src={m.thumb}
                    alt={m.alt}
                    className="aspect-[4/3] w-full rounded object-cover"
                    loading="lazy"
                />
            </a>
            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                <span>
                    {m.width}×{m.height}
                </span>
                <span>{m.kb} KB</span>
                {m.used > 0 ? (
                    <Badge variant="secondary">In use</Badge>
                ) : (
                    <Badge variant="outline">Not used</Badge>
                )}
            </div>
            <Input
                aria-label="Alt text"
                value={alt}
                onChange={(e) => setAlt(e.target.value)}
                onBlur={() =>
                    alt !== m.alt &&
                    alt.trim() !== '' &&
                    sendJson('PATCH', `/settings/website/media/${m.ulid}`, {
                        alt,
                    })
                }
            />
            <Button
                size="sm"
                variant="ghost"
                className="justify-self-start"
                disabled={m.used > 0}
                title={
                    m.used > 0 ? 'Remove it from your pages first' : undefined
                }
                onClick={async () => {
                    try {
                        await sendJson(
                            'DELETE',
                            `/settings/website/media/${m.ulid}`,
                        );
                        onChanged();
                    } catch (x) {
                        setError(
                            x instanceof Error
                                ? x.message
                                : 'Could not delete.',
                        );
                    }
                }}
            >
                Delete
            </Button>
            {error && <p className="text-xs text-destructive">{error}</p>}
        </li>
    );
}
