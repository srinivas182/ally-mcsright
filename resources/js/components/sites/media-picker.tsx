import { Fragment, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { postForm } from '@/lib/http';

export type MediaItem = {
    ulid: string;
    url: string;
    alt: string;
    /** TP2: a licensed demo photo from the design, to be replaced with your own. */
    demo?: boolean;
};

/**
 * Pick an image from the media library, upload a new one (alt text required), or paste an https link.
 * The value is a media id or an https link.
 */
export function MediaPicker({
    value,
    media,
    onChange,
    onUploaded,
    label = 'Image',
    site,
}: {
    value: string | null;
    media: MediaItem[];
    onChange: (v: string | null) => void;
    onUploaded: (m: MediaItem) => void;
    label?: string;
    site?: string;
}) {
    const [open, setOpen] = useState(false);
    const [alt, setAlt] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const file = useRef<HTMLInputElement>(null);
    const current = value
        ? (media.find((m) => m.ulid === value)?.url ??
          (value.startsWith('https://') ? value : null))
        : null;

    const upload = async () => {
        const f = file.current?.files?.[0];
        if (!f || alt.trim() === '') {
            setError('Choose an image and describe it.');
            return;
        }
        setBusy(true);
        setError(null);
        try {
            const data = new FormData();
            data.append('file', f);
            data.append('alt', alt);
            if (site) {
                data.append('site', site);
            }
            const res = await postForm<{ media: MediaItem }>(
                '/settings/website/media',
                data,
            );
            onUploaded(res.media);
            onChange(res.media.ulid);
            setAlt('');
            setOpen(false);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Upload failed.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="grid gap-2">
            <span className="text-xs font-medium">{label}</span>
            <div className="flex flex-wrap items-center gap-2">
                {current ? (
                    <span className="relative">
                        <img
                            src={current}
                            alt=""
                            className="h-14 w-20 rounded object-cover"
                        />
                        {value?.startsWith('demo:') && (
                            <span className="absolute -top-2 -left-2 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-900 shadow-sm">
                                Demo
                            </span>
                        )}
                    </span>
                ) : (
                    <span className="text-xs text-muted-foreground">
                        No image
                    </span>
                )}
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => setOpen(!open)}
                >
                    {open ? 'Close' : 'Choose'}
                </Button>
                {value && (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => onChange(null)}
                    >
                        Remove
                    </Button>
                )}
            </div>
            {value?.startsWith('demo:') && (
                <p className="text-xs text-muted-foreground">
                    A demo photo from your design. Replace it with one of your
                    own when you can.
                </p>
            )}
            {open && (
                <div className="grid gap-2 rounded-md border p-2">
                    <div className="grid max-h-48 grid-cols-4 gap-1 overflow-y-auto sm:grid-cols-6">
                        {media.map((m, i) => (
                            <Fragment key={m.ulid}>
                                {m.demo && !media[i - 1]?.demo && (
                                    <p className="col-span-full pt-1 text-xs font-medium text-muted-foreground">
                                        Demo photos (free to use)
                                    </p>
                                )}
                                <button
                                    type="button"
                                    title={m.alt}
                                    onClick={() => {
                                        onChange(m.ulid);
                                        setOpen(false);
                                    }}
                                    className={`overflow-hidden rounded border-2 ${value === m.ulid ? 'border-primary' : 'border-transparent'}`}
                                >
                                    <img
                                        src={m.url}
                                        alt={m.alt}
                                        loading="lazy"
                                        className="aspect-[4/3] w-full object-cover"
                                    />
                                </button>
                            </Fragment>
                        ))}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <input
                            ref={file}
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            aria-label="Image file"
                            className="text-xs"
                        />
                        <Input
                            aria-label="Describe the image"
                            className="max-w-xs"
                            placeholder="Describe the image (alt text)"
                            value={alt}
                            onChange={(e) => setAlt(e.target.value)}
                        />
                        <Button
                            type="button"
                            size="sm"
                            disabled={busy}
                            onClick={upload}
                        >
                            {busy ? 'Uploading…' : 'Upload'}
                        </Button>
                    </div>
                    <Input
                        aria-label="Or paste an image link"
                        placeholder="Or paste an https image link"
                        onBlur={(e) => {
                            if (e.target.value.startsWith('https://')) {
                                onChange(e.target.value);
                                setOpen(false);
                            }
                        }}
                    />
                    {error && (
                        <p className="text-xs text-destructive">{error}</p>
                    )}
                </div>
            )}
        </div>
    );
}
