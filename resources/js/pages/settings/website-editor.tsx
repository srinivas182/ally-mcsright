import { Head, Link, router, useForm } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { MediaPicker, type MediaItem } from '@/components/sites/media-picker';
import { SeoScore, type SeoChecks } from '@/components/sites/seo-score';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { getJson, sendJson } from '@/lib/http';

type Section = {
    type: string;
    id: string;
    hidden: boolean;
    props: Record<string, unknown>;
};
type Props = {
    page: {
        ulid: string;
        path: string;
        title: string;
        meta_description: string | null;
        noindex: boolean;
        in_menu: boolean;
        status: string;
        has_draft: boolean;
    };
    sections: Section[];
    types: { type: string; label: string }[];
    versions: { id: number; at: string; by: string | null; sections: number }[];
    check: SeoChecks;
    preview_url: string | null;
    live_url: string | null;
    places: { slug: string; name: string }[];
    media: MediaItem[];
};

type Field =
    | {
          key: string;
          label: string;
          kind:
              | 'text'
              | 'textarea'
              | 'number'
              | 'checkbox'
              | 'image'
              | 'places'
              | 'images';
      }
    | {
          key: string;
          label: string;
          kind: 'select';
          options: [string, string][];
      }
    | {
          key: string;
          label: string;
          kind: 'items';
          fields: Field[];
          max: number;
      };

/** A field value as text (numbers included; anything else is blank). */
const text = (v: unknown): string =>
    typeof v === 'string' ? v : typeof v === 'number' ? String(v) : '';

/** What each section type can edit. */
const SCHEMA: Record<string, Field[]> = {
    hero: [
        { key: 'headline', label: 'Headline', kind: 'text' },
        {
            key: 'subhead',
            label: 'Supporting line (answer the main question here)',
            kind: 'textarea',
        },
        { key: 'image', label: 'Background photo', kind: 'image' },
        { key: 'search', label: 'Show the home search box', kind: 'checkbox' },
    ],
    listings: [
        { key: 'title', label: 'Heading', kind: 'text' },
        {
            key: 'q',
            label: 'Place (city, ZIP or neighborhood; empty for all)',
            kind: 'text',
        },
        { key: 'min_price', label: 'Minimum price', kind: 'number' },
        { key: 'max_price', label: 'Maximum price', kind: 'number' },
        {
            key: 'sort',
            label: 'Order',
            kind: 'select',
            options: [
                ['newest', 'Newest'],
                ['price_desc', 'Highest price'],
                ['reduced', 'Recently reduced'],
            ],
        },
        { key: 'limit', label: 'How many homes (3–12)', kind: 'number' },
    ],
    features: [
        { key: 'title', label: 'Heading', kind: 'text' },
        {
            key: 'items',
            label: 'Features',
            kind: 'items',
            max: 6,
            fields: [
                { key: 'title', label: 'Title', kind: 'text' },
                { key: 'body', label: 'Text', kind: 'textarea' },
            ],
        },
    ],
    text: [
        { key: 'title', label: 'Heading', kind: 'text' },
        { key: 'body', label: 'Text', kind: 'textarea' },
    ],
    faq: [
        { key: 'title', label: 'Heading', kind: 'text' },
        {
            key: 'items',
            label: 'Questions',
            kind: 'items',
            max: 20,
            fields: [
                { key: 'q', label: 'Question', kind: 'text' },
                { key: 'a', label: 'Answer', kind: 'textarea' },
            ],
        },
    ],
    cta: [
        { key: 'title', label: 'Heading', kind: 'text' },
        { key: 'body', label: 'Text', kind: 'textarea' },
        { key: 'button', label: 'Button', kind: 'text' },
        { key: 'link', label: 'Button link (/page or https)', kind: 'text' },
    ],
    contact: [
        { key: 'title', label: 'Heading', kind: 'text' },
        { key: 'body', label: 'Text', kind: 'textarea' },
    ],
    testimonials: [
        { key: 'title', label: 'Heading', kind: 'text' },
        {
            key: 'items',
            label: 'Testimonials',
            kind: 'items',
            max: 12,
            fields: [
                { key: 'quote', label: 'Their words', kind: 'textarea' },
                { key: 'name', label: 'Name', kind: 'text' },
                {
                    key: 'detail',
                    label: 'Detail (e.g. Sold in 78704)',
                    kind: 'text',
                },
                { key: 'rating', label: 'Stars (0–5)', kind: 'number' },
            ],
        },
    ],
    team: [
        { key: 'title', label: 'Heading', kind: 'text' },
        {
            key: 'items',
            label: 'People',
            kind: 'items',
            max: 24,
            fields: [
                { key: 'name', label: 'Name', kind: 'text' },
                { key: 'role', label: 'Role', kind: 'text' },
                { key: 'photo', label: 'Photo', kind: 'image' },
                { key: 'phone', label: 'Phone', kind: 'text' },
                { key: 'email', label: 'Email', kind: 'text' },
            ],
        },
    ],
    stats: [
        { key: 'title', label: 'Heading', kind: 'text' },
        {
            key: 'items',
            label: 'Numbers',
            kind: 'items',
            max: 6,
            fields: [
                { key: 'value', label: 'Number (e.g. 1,200+)', kind: 'text' },
                { key: 'label', label: 'What it counts', kind: 'text' },
            ],
        },
    ],
    gallery: [
        { key: 'title', label: 'Heading', kind: 'text' },
        { key: 'images', label: 'Photos', kind: 'images' },
    ],
    video: [
        { key: 'title', label: 'Heading', kind: 'text' },
        { key: 'url', label: 'YouTube or Vimeo link', kind: 'text' },
        { key: 'caption', label: 'Caption', kind: 'text' },
    ],
    neighbourhoods: [
        { key: 'title', label: 'Heading', kind: 'text' },
        {
            key: 'places',
            label: 'Areas (live figures from your market reports)',
            kind: 'places',
        },
    ],
};

/** Settings → Website → page builder: sections on the left, the live draft on the right, the score below. */
export default function WebsiteEditor(props: Props) {
    const { page, types, versions } = props;
    const [sections, setSections] = useState<Section[]>(props.sections);
    const [open, setOpen] = useState<string | null>(
        props.sections[0]?.id ?? null,
    );
    const [check, setCheck] = useState<SeoChecks>(props.check);
    const [preview, setPreview] = useState<string | null>(props.preview_url);
    const [stamp, setStamp] = useState(0);
    const [state, setState] = useState<'saved' | 'saving' | 'error'>('saved');
    const [media, setMedia] = useState<MediaItem[]>(props.media);
    const [width, setWidth] = useState<'desktop' | 'phone'>('desktop');
    const [addType, setAddType] = useState(types[0]?.type ?? 'text');
    const first = useRef(true);
    const meta = useForm({
        title: page.title,
        meta_description: page.meta_description ?? '',
        noindex: page.noindex,
        in_menu: page.in_menu,
    });

    const save = useCallback(
        async (next: Section[]) => {
            setState('saving');
            try {
                const res = await sendJson<{
                    sections: Section[];
                    check: SeoChecks;
                    preview_url: string | null;
                }>('PUT', `/settings/website/pages/${page.ulid}/draft`, {
                    sections: next,
                });
                setCheck(res.check);
                setPreview(res.preview_url);
                setStamp(Date.now());
                setState('saved');
            } catch {
                setState('error');
            }
        },
        [page.ulid],
    );

    // Autosave the draft shortly after each change; the preview then reloads.
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const t = setTimeout(() => save(sections), 700);
        return () => clearTimeout(t);
    }, [sections, save]);

    const update = (i: number, s: Section) =>
        setSections(sections.map((x, n) => (n === i ? s : x)));
    const move = (i: number, d: number) => {
        const next = [...sections];
        const [s] = next.splice(i, 1);
        next.splice(i + d, 0, s);
        setSections(next);
    };
    const add = async () => {
        const res = await getJson<{ section: Section }>(
            `/settings/website/pages/blank?type=${addType}`,
        );
        setSections([...sections, res.section]);
        setOpen(res.section.id);
    };
    const label = (type: string) =>
        types.find((t) => t.type === type)?.label ?? type;
    const opts = { preserveScroll: true };

    return (
        <>
            <Head title={`Edit ${page.title}`} />
            <div className="grid w-full gap-4 p-4 md:p-6">
                <div className="flex flex-wrap items-center gap-2">
                    <Link
                        href="/settings/website"
                        className="text-sm text-muted-foreground"
                    >
                        ← Website
                    </Link>
                    <h1 className="text-xl font-semibold">{page.title}</h1>
                    <code className="text-xs text-muted-foreground">
                        {page.path}
                    </code>
                    <Badge
                        variant={
                            page.status === 'published'
                                ? 'secondary'
                                : 'outline'
                        }
                    >
                        {page.status === 'published'
                            ? 'Published'
                            : 'Not published yet'}
                    </Badge>
                    <span
                        className="text-xs text-muted-foreground"
                        aria-live="polite"
                    >
                        {state === 'saving'
                            ? 'Saving draft…'
                            : state === 'error'
                              ? 'Could not save. Check your connection.'
                              : 'Draft saved'}
                    </span>
                    <div className="ml-auto flex flex-wrap gap-2">
                        {props.live_url && (
                            <Button asChild size="sm" variant="ghost">
                                <a
                                    href={props.live_url}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    View live
                                </a>
                            </Button>
                        )}
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    `/settings/website/pages/${page.ulid}/discard`,
                                    {},
                                    {
                                        ...opts,
                                        onSuccess: () =>
                                            window.location.reload(),
                                    },
                                )
                            }
                        >
                            Discard draft
                        </Button>
                        <Button
                            size="sm"
                            disabled={state !== 'saved'}
                            onClick={() =>
                                router.post(
                                    `/settings/website/pages/${page.ulid}/publish`,
                                    {},
                                    opts,
                                )
                            }
                        >
                            Publish
                        </Button>
                    </div>
                </div>

                <div className="grid gap-4 xl:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
                    <div className="grid content-start gap-3">
                        {sections.map((s, i) => (
                            <section
                                key={s.id}
                                className={`rounded-lg border text-sm ${s.hidden ? 'opacity-60' : ''}`}
                            >
                                <div className="flex items-center gap-1 p-2">
                                    <button
                                        type="button"
                                        className="flex-1 text-left font-medium"
                                        aria-expanded={open === s.id}
                                        onClick={() =>
                                            setOpen(open === s.id ? null : s.id)
                                        }
                                    >
                                        {label(s.type)}
                                        <span className="ml-2 font-normal text-muted-foreground">
                                            {text(
                                                s.props.title ??
                                                    s.props.headline,
                                            )}
                                        </span>
                                    </button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        aria-label="Move up"
                                        disabled={i === 0}
                                        onClick={() => move(i, -1)}
                                    >
                                        ↑
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        aria-label="Move down"
                                        disabled={i === sections.length - 1}
                                        onClick={() => move(i, 1)}
                                    >
                                        ↓
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        aria-label={
                                            s.hidden
                                                ? 'Show section'
                                                : 'Hide section'
                                        }
                                        onClick={() =>
                                            update(i, {
                                                ...s,
                                                hidden: !s.hidden,
                                            })
                                        }
                                    >
                                        {s.hidden ? 'Show' : 'Hide'}
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        aria-label="Duplicate section"
                                        onClick={() =>
                                            setSections([
                                                ...sections.slice(0, i + 1),
                                                {
                                                    ...s,
                                                    id: `${s.type}-${Math.random().toString(36).slice(2, 8)}`,
                                                },
                                                ...sections.slice(i + 1),
                                            ])
                                        }
                                    >
                                        ⧉
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        aria-label="Delete section"
                                        onClick={() =>
                                            setSections(
                                                sections.filter(
                                                    (_, n) => n !== i,
                                                ),
                                            )
                                        }
                                    >
                                        ×
                                    </Button>
                                </div>
                                {open === s.id && (
                                    <div className="grid gap-3 border-t p-3">
                                        <Fields
                                            fields={SCHEMA[s.type] ?? []}
                                            value={s.props}
                                            onChange={(p) =>
                                                update(i, { ...s, props: p })
                                            }
                                            media={media}
                                            onUploaded={(m) =>
                                                setMedia([m, ...media])
                                            }
                                            places={props.places}
                                        />
                                    </div>
                                )}
                            </section>
                        ))}
                        <div className="flex gap-2">
                            <select
                                aria-label="Section type"
                                className="rounded-md border bg-background px-2 text-sm"
                                value={addType}
                                onChange={(e) => setAddType(e.target.value)}
                            >
                                {types.map((t) => (
                                    <option key={t.type} value={t.type}>
                                        {t.label}
                                    </option>
                                ))}
                            </select>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={add}
                                disabled={sections.length >= 40}
                            >
                                Add section
                            </Button>
                        </div>

                        <form
                            className="grid gap-2 rounded-lg border p-3 text-sm"
                            onSubmit={(e) => {
                                e.preventDefault();
                                meta.patch(
                                    `/settings/website/pages/${page.ulid}`,
                                    opts,
                                );
                            }}
                        >
                            <h2 className="font-semibold">Page settings</h2>
                            <Input
                                aria-label="Page title"
                                value={meta.data.title}
                                onChange={(e) =>
                                    meta.setData('title', e.target.value)
                                }
                            />
                            <Textarea
                                aria-label="Search description"
                                rows={2}
                                maxLength={300}
                                placeholder="Search description, 70–160 characters"
                                value={meta.data.meta_description}
                                onChange={(e) =>
                                    meta.setData(
                                        'meta_description',
                                        e.target.value,
                                    )
                                }
                            />
                            <span className="text-xs text-muted-foreground">
                                {meta.data.meta_description.length} characters
                            </span>
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={meta.data.in_menu}
                                    onChange={(e) =>
                                        meta.setData(
                                            'in_menu',
                                            e.target.checked,
                                        )
                                    }
                                />{' '}
                                In the automatic menu
                            </label>
                            {page.path !== '/' && (
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={meta.data.noindex}
                                        onChange={(e) =>
                                            meta.setData(
                                                'noindex',
                                                e.target.checked,
                                            )
                                        }
                                    />{' '}
                                    Hide from search engines
                                </label>
                            )}
                            <div>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={meta.processing || !meta.isDirty}
                                >
                                    Save settings
                                </Button>
                            </div>
                        </form>

                        <div className="rounded-lg border p-3">
                            <SeoScore check={check} />
                        </div>

                        {versions.length > 0 && (
                            <div className="grid gap-2 rounded-lg border p-3 text-sm">
                                <h2 className="font-semibold">
                                    Published versions
                                </h2>
                                <ul className="grid gap-1">
                                    {versions.slice(0, 10).map((v) => (
                                        <li
                                            key={v.id}
                                            className="flex items-center gap-2"
                                        >
                                            <span>
                                                {new Date(
                                                    v.at.replace(' ', 'T') +
                                                        'Z',
                                                ).toLocaleString()}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {v.by ?? ''}
                                            </span>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                className="ml-auto"
                                                onClick={() =>
                                                    router.post(
                                                        `/settings/website/pages/${page.ulid}/restore`,
                                                        { version: v.id },
                                                        {
                                                            ...opts,
                                                            onSuccess: () =>
                                                                window.location.reload(),
                                                        },
                                                    )
                                                }
                                            >
                                                Restore
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        {page.path !== '/' && (
                            <Button
                                variant="ghost"
                                className="justify-self-start text-destructive"
                                onClick={() => {
                                    if (
                                        window.confirm(
                                            'Delete this page? Visitors who open its address will see "not found".',
                                        )
                                    ) {
                                        router.delete(
                                            `/settings/website/pages/${page.ulid}`,
                                        );
                                    }
                                }}
                            >
                                Delete page
                            </Button>
                        )}
                    </div>

                    <div className="grid content-start gap-2">
                        <div className="flex items-center gap-2 text-sm">
                            <span className="font-medium">
                                Live preview of your draft
                            </span>
                            <div className="ml-auto flex gap-1">
                                <Button
                                    size="sm"
                                    variant={
                                        width === 'desktop'
                                            ? 'secondary'
                                            : 'ghost'
                                    }
                                    onClick={() => setWidth('desktop')}
                                >
                                    Desktop
                                </Button>
                                <Button
                                    size="sm"
                                    variant={
                                        width === 'phone'
                                            ? 'secondary'
                                            : 'ghost'
                                    }
                                    onClick={() => setWidth('phone')}
                                >
                                    Phone
                                </Button>
                                {preview && (
                                    <Button asChild size="sm" variant="ghost">
                                        <a
                                            href={preview}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            Open
                                        </a>
                                    </Button>
                                )}
                            </div>
                        </div>
                        {preview ? (
                            <div className="flex justify-center rounded-lg border bg-muted/40 p-2">
                                <iframe
                                    key={stamp}
                                    title="Preview of your draft"
                                    src={preview}
                                    className={`h-[78vh] rounded-md border bg-white ${width === 'phone' ? 'w-[390px]' : 'w-full'}`}
                                    sandbox="allow-same-origin allow-scripts allow-popups"
                                />
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                The preview appears once the website has an
                                address.
                            </p>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

function Fields({
    fields,
    value,
    onChange,
    media,
    onUploaded,
    places,
}: {
    fields: Field[];
    value: Record<string, unknown>;
    onChange: (v: Record<string, unknown>) => void;
    media: MediaItem[];
    onUploaded: (m: MediaItem) => void;
    places: Props['places'];
}) {
    const set = (k: string, v: unknown) => onChange({ ...value, [k]: v });

    return (
        <>
            {fields.map((f) => {
                const v = value[f.key];
                switch (f.kind) {
                    case 'textarea':
                        return (
                            <label
                                key={f.key}
                                className="grid gap-1 text-xs font-medium"
                            >
                                {f.label}
                                <Textarea
                                    rows={4}
                                    value={text(v)}
                                    onChange={(e) => set(f.key, e.target.value)}
                                />
                            </label>
                        );
                    case 'number':
                        return (
                            <label
                                key={f.key}
                                className="grid gap-1 text-xs font-medium"
                            >
                                {f.label}
                                <Input
                                    type="number"
                                    value={
                                        v === null || v === undefined
                                            ? ''
                                            : text(v)
                                    }
                                    onChange={(e) =>
                                        set(
                                            f.key,
                                            e.target.value === ''
                                                ? null
                                                : Number(e.target.value),
                                        )
                                    }
                                />
                            </label>
                        );
                    case 'checkbox':
                        return (
                            <label
                                key={f.key}
                                className="flex items-center gap-2 text-xs font-medium"
                            >
                                <input
                                    type="checkbox"
                                    checked={Boolean(v)}
                                    onChange={(e) =>
                                        set(f.key, e.target.checked)
                                    }
                                />{' '}
                                {f.label}
                            </label>
                        );
                    case 'select':
                        return (
                            <label
                                key={f.key}
                                className="grid gap-1 text-xs font-medium"
                            >
                                {f.label}
                                <select
                                    className="rounded-md border bg-background px-2 py-1.5"
                                    value={text(v)}
                                    onChange={(e) => set(f.key, e.target.value)}
                                >
                                    {f.options.map(([k, l]) => (
                                        <option key={k} value={k}>
                                            {l}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        );
                    case 'image':
                        return (
                            <MediaPicker
                                key={f.key}
                                label={f.label}
                                value={(v as string | null) ?? null}
                                media={media}
                                onChange={(x) => set(f.key, x)}
                                onUploaded={onUploaded}
                            />
                        );
                    case 'images': {
                        const list = (v as string[] | undefined) ?? [];
                        return (
                            <div key={f.key} className="grid gap-2">
                                <span className="text-xs font-medium">
                                    {f.label}
                                </span>
                                {list.map((img, n) => (
                                    <div
                                        key={n}
                                        className="flex items-end gap-2"
                                    >
                                        <MediaPicker
                                            label={`Photo ${n + 1}`}
                                            value={img}
                                            media={media}
                                            onChange={(x) =>
                                                set(
                                                    f.key,
                                                    x === null
                                                        ? list.filter(
                                                              (_, i) => i !== n,
                                                          )
                                                        : list.map((y, i) =>
                                                              i === n ? x : y,
                                                          ),
                                                )
                                            }
                                            onUploaded={onUploaded}
                                        />
                                    </div>
                                ))}
                                {list.length < 24 && (
                                    <MediaPicker
                                        label="Add a photo"
                                        value={null}
                                        media={media}
                                        onChange={(x) =>
                                            x !== null &&
                                            set(f.key, [...list, x])
                                        }
                                        onUploaded={onUploaded}
                                    />
                                )}
                            </div>
                        );
                    }
                    case 'places': {
                        const chosen = (v as string[] | undefined) ?? [];
                        return (
                            <fieldset
                                key={f.key}
                                className="grid gap-1 text-xs"
                            >
                                <legend className="font-medium">
                                    {f.label}
                                </legend>
                                {places.length === 0 && (
                                    <p className="text-muted-foreground">
                                        Market reports appear once your MLS
                                        listings are connected.
                                    </p>
                                )}
                                <div className="flex max-h-40 flex-wrap gap-2 overflow-y-auto">
                                    {places.map((p) => (
                                        <label
                                            key={p.slug}
                                            className="flex items-center gap-1"
                                        >
                                            <input
                                                type="checkbox"
                                                checked={chosen.includes(
                                                    p.slug,
                                                )}
                                                onChange={(e) =>
                                                    set(
                                                        f.key,
                                                        e.target.checked
                                                            ? [
                                                                  ...chosen,
                                                                  p.slug,
                                                              ].slice(0, 12)
                                                            : chosen.filter(
                                                                  (x) =>
                                                                      x !==
                                                                      p.slug,
                                                              ),
                                                    )
                                                }
                                            />
                                            {p.name}
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                        );
                    }
                    case 'items': {
                        const items =
                            (v as Record<string, unknown>[] | undefined) ?? [];
                        return (
                            <div key={f.key} className="grid gap-2">
                                <span className="text-xs font-medium">
                                    {f.label}
                                </span>
                                {items.map((it, n) => (
                                    <div
                                        key={n}
                                        className="grid gap-2 rounded-md border p-2"
                                    >
                                        <Fields
                                            fields={f.fields}
                                            value={it}
                                            onChange={(x) =>
                                                set(
                                                    f.key,
                                                    items.map((y, i) =>
                                                        i === n ? x : y,
                                                    ),
                                                )
                                            }
                                            media={media}
                                            onUploaded={onUploaded}
                                            places={places}
                                        />
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            className="justify-self-start"
                                            onClick={() =>
                                                set(
                                                    f.key,
                                                    items.filter(
                                                        (_, i) => i !== n,
                                                    ),
                                                )
                                            }
                                        >
                                            Remove
                                        </Button>
                                    </div>
                                ))}
                                {items.length < f.max && (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        className="justify-self-start"
                                        onClick={() =>
                                            set(f.key, [...items, {}])
                                        }
                                    >
                                        Add
                                    </Button>
                                )}
                            </div>
                        );
                    }
                    default:
                        return (
                            <label
                                key={f.key}
                                className="grid gap-1 text-xs font-medium"
                            >
                                {f.label}
                                <Input
                                    value={text(v)}
                                    onChange={(e) => set(f.key, e.target.value)}
                                />
                            </label>
                        );
                }
            })}
        </>
    );
}
