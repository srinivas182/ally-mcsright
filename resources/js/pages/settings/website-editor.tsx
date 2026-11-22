import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlignLeft,
    ArrowLeft,
    BarChart3,
    ChevronDown,
    ChevronUp,
    Copy,
    Eye,
    EyeOff,
    GripVertical,
    HelpCircle,
    Home,
    Images,
    LayoutTemplate,
    type LucideIcon,
    Mail,
    MapPin,
    Monitor,
    MousePointerClick,
    Plus,
    Quote,
    Redo2,
    Smartphone,
    Sparkles,
    Tablet,
    Trash2,
    Undo2,
    Users,
    Video,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { confirmCredits } from '@/components/credit-consent';
import { MediaPicker, type MediaItem } from '@/components/sites/media-picker';
import { SeoScore, type SeoChecks } from '@/components/sites/seo-score';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    AiNotesPanel,
    type AiNotes,
    type AssistResult,
} from '@/components/sites/ai-notes';
import { getJson, postJson, sendJson } from '@/lib/http';

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
    site: { ulid: string; kind: string };
    back: string;
    types: { type: string; label: string }[];
    versions: { id: number; at: string; by: string | null; sections: number }[];
    check: SeoChecks;
    preview_url: string | null;
    live_url: string | null;
    places: { slug: string; name: string }[];
    media: MediaItem[];
    ai_notes: AiNotes;
};

/** Rewrites a piece of text with AI (improve, shorter, longer); null when unavailable. */
type Rewrite = (
    text: string,
    mode: 'improve' | 'shorter' | 'longer',
) => Promise<string | null>;

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
    const [width, setWidth] = useState<'desktop' | 'tablet' | 'phone'>(
        'desktop',
    );
    const first = useRef(true);
    const [aiNotes, setAiNotes] = useState<AiNotes>(props.ai_notes);
    const [aiError, setAiError] = useState<string | null>(null);
    const [aiBusy, setAiBusy] = useState(false);
    const assist = async <T,>(
        action: string,
        body: Record<string, unknown>,
    ): Promise<T | null> => {
        if (!(await confirmCredits('ai.site_assist'))) {
            return null;
        }
        setAiBusy(true);
        setAiError(null);
        try {
            const res = await postJson<AssistResult<T>>(
                '/settings/website/ai/assist',
                { action, site: props.site.ulid, title: page.title, ...body },
            );
            setAiNotes({ score: check.score, warnings: res.warnings });
            return res.result;
        } catch (e) {
            setAiError(
                e instanceof Error
                    ? e.message
                    : 'AI help is not available right now.',
            );
            return null;
        } finally {
            setAiBusy(false);
        }
    };
    const rewrite: Rewrite = (text, mode) =>
        assist<string>('rewrite', { content: text, mode });
    const pageText = () =>
        sections
            .filter((s) => !s.hidden)
            .map((s) =>
                [s.props.title, s.props.headline, s.props.subhead, s.props.body]
                    .filter((x) => typeof x === 'string')
                    .join('\n'),
            )
            .join('\n\n');
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

    // WB1: undo and redo for every change to the sections (Ctrl+Z, Ctrl+Shift+Z).
    const past = useRef<Section[][]>([]);
    const future = useRef<Section[][]>([]);
    const [, bump] = useState(0);
    const change = (next: Section[]) => {
        past.current = [...past.current.slice(-49), sections];
        future.current = [];
        setSections(next);
        bump((n) => n + 1);
    };
    const undo = () => {
        const prev = past.current.pop();
        if (prev) {
            future.current.push(sections);
            setSections(prev);
            bump((n) => n + 1);
        }
    };
    const redo = () => {
        const next = future.current.pop();
        if (next) {
            past.current.push(sections);
            setSections(next);
            bump((n) => n + 1);
        }
    };
    useEffect(() => {
        const keys = (e: KeyboardEvent) => {
            const target = e.target as HTMLElement | null;
            if (target && /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName)) {
                return;
            }
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') {
                e.preventDefault();
                if (e.shiftKey) {
                    redo();
                } else {
                    undo();
                }
            }
        };
        window.addEventListener('keydown', keys);
        return () => window.removeEventListener('keydown', keys);
    });

    const update = (i: number, s: Section) =>
        change(sections.map((x, n) => (n === i ? s : x)));
    const move = (from: number, to: number) => {
        if (to < 0 || to >= sections.length || from === to) {
            return;
        }
        const next = [...sections];
        const [s] = next.splice(from, 1);
        next.splice(to, 0, s);
        change(next);
    };
    const [gallery, setGallery] = useState(false);
    const add = async (type: string) => {
        const res = await getJson<{ section: Section }>(
            `/settings/website/pages/blank?type=${type}`,
        );
        const at = sections.findIndex((x) => x.id === open);
        const next = [...sections];
        next.splice(at === -1 ? next.length : at + 1, 0, res.section);
        change(next);
        setOpen(res.section.id);
        setGallery(false);
    };
    const label = (type: string) =>
        types.find((t) => t.type === type)?.label ?? type;
    const opts = { preserveScroll: true };

    // WB1: clicking a section in the preview opens it here, and opening one here outlines it there.
    const frame = useRef<HTMLIFrameElement>(null);
    const listRef = useRef<HTMLDivElement>(null);
    useEffect(() => {
        const onMessage = (e: MessageEvent) => {
            const data = e.data as { source?: string; block?: string };
            if (
                e.source !== frame.current?.contentWindow ||
                data?.source !== 'rightally-builder' ||
                typeof data.block !== 'string'
            ) {
                return;
            }
            setTab('sections');
            setOpen(data.block);
            window.setTimeout(
                () =>
                    listRef.current
                        ?.querySelector(`[data-section="${data.block}"]`)
                        ?.scrollIntoView({
                            block: 'start',
                            behavior: 'smooth',
                        }),
                50,
            );
        };
        window.addEventListener('message', onMessage);
        return () => window.removeEventListener('message', onMessage);
    }, []);
    const tellPreview = (id: string | null) => {
        if (id) {
            frame.current?.contentWindow?.postMessage(
                { source: 'rightally-editor', block: id },
                '*',
            );
        }
    };
    const select = (id: string | null) => {
        setOpen(id);
        tellPreview(id);
    };
    const [tab, setTab] = useState<'sections' | 'page' | 'seo' | 'versions'>(
        'sections',
    );
    const [dragging, setDragging] = useState<number | null>(null);
    const frameWidth =
        width === 'phone'
            ? 'w-[390px]'
            : width === 'tablet'
              ? 'w-[820px]'
              : 'w-full';

    return (
        <>
            <Head title={`Edit ${page.title}`} />
            <header className="sticky top-0 z-20 flex flex-wrap items-center gap-2 border-b bg-background px-3 py-2 shadow-xs">
                <Button asChild variant="ghost" size="sm">
                    <Link href={props.back}>
                        <ArrowLeft aria-hidden="true" /> Website
                    </Link>
                </Button>
                <span className="h-5 w-px bg-border" aria-hidden="true" />
                <h1 className="truncate text-base font-semibold">
                    {page.title}
                </h1>
                <code className="hidden text-xs text-muted-foreground sm:inline">
                    {page.path}
                </code>
                <Badge
                    variant={
                        page.status === 'published' ? 'secondary' : 'outline'
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
                        ? 'Saving…'
                        : state === 'error'
                          ? 'Could not save. Check your connection.'
                          : 'All changes saved'}
                </span>
                <div className="mx-auto flex items-center gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Undo (Ctrl+Z)"
                        title="Undo (Ctrl+Z)"
                        disabled={past.current.length === 0}
                        onClick={undo}
                    >
                        <Undo2 aria-hidden="true" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Redo (Ctrl+Shift+Z)"
                        title="Redo (Ctrl+Shift+Z)"
                        disabled={future.current.length === 0}
                        onClick={redo}
                    >
                        <Redo2 aria-hidden="true" />
                    </Button>
                    <span
                        className="mx-1 h-5 w-px bg-border"
                        aria-hidden="true"
                    />
                    <div
                        role="radiogroup"
                        aria-label="Preview size"
                        className="flex rounded-md border p-0.5"
                    >
                        {(
                            [
                                ['desktop', 'Desktop', Monitor],
                                ['tablet', 'Tablet', Tablet],
                                ['phone', 'Phone', Smartphone],
                            ] as const
                        ).map(([key, name, Icon]) => (
                            <Button
                                key={key}
                                role="radio"
                                aria-checked={width === key}
                                aria-label={name}
                                title={name}
                                size="icon"
                                variant={width === key ? 'secondary' : 'ghost'}
                                className="size-8"
                                onClick={() => setWidth(key)}
                            >
                                <Icon aria-hidden="true" />
                            </Button>
                        ))}
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
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
                    {page.has_draft && (
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
                            Discard changes
                        </Button>
                    )}
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
            </header>

            <div className="grid flex-1 lg:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">
                <aside className="flex max-h-[calc(100svh-3.25rem)] min-w-0 flex-col border-r bg-background lg:sticky lg:top-[3.25rem]">
                    <div
                        role="tablist"
                        aria-label="Editor panels"
                        className="flex gap-1 border-b p-2"
                    >
                        {(
                            [
                                ['sections', 'Sections'],
                                ['page', 'Page'],
                                ['seo', 'Search and AI'],
                                ['versions', 'History'],
                            ] as const
                        ).map(([key, name]) => (
                            <Button
                                key={key}
                                role="tab"
                                aria-selected={tab === key}
                                size="sm"
                                variant={tab === key ? 'secondary' : 'ghost'}
                                onClick={() => setTab(key)}
                            >
                                {name}
                            </Button>
                        ))}
                    </div>
                    <div
                        ref={listRef}
                        className="min-w-0 flex-1 overflow-x-hidden overflow-y-auto p-3"
                    >
                        {tab === 'sections' && (
                            <div className="grid grid-cols-[minmax(0,1fr)] gap-2">
                                <p className="text-xs text-muted-foreground">
                                    Click a section here or on the page to edit
                                    it. Drag to reorder.
                                </p>
                                {sections.map((s, i) => (
                                    <section
                                        key={s.id}
                                        data-section={s.id}
                                        draggable
                                        onDragStart={() => setDragging(i)}
                                        onDragOver={(e) => e.preventDefault()}
                                        onDrop={() => {
                                            if (dragging !== null) {
                                                move(dragging, i);
                                            }
                                            setDragging(null);
                                        }}
                                        onDragEnd={() => setDragging(null)}
                                        className={`min-w-0 rounded-lg border bg-card text-sm transition ${open === s.id ? 'border-primary ring-2 ring-primary/20' : ''} ${s.hidden ? 'opacity-60' : ''} ${dragging === i ? 'opacity-40' : ''}`}
                                    >
                                        <div className="flex items-center gap-1 p-1.5">
                                            <GripVertical
                                                aria-hidden="true"
                                                className="size-4 shrink-0 cursor-grab text-muted-foreground"
                                            />
                                            <button
                                                type="button"
                                                className="min-w-0 flex-1 truncate px-1 text-left"
                                                aria-expanded={open === s.id}
                                                onClick={() =>
                                                    select(
                                                        open === s.id
                                                            ? null
                                                            : s.id,
                                                    )
                                                }
                                            >
                                                <span className="font-medium">
                                                    {label(s.type)}
                                                </span>
                                                <span className="ml-2 text-muted-foreground">
                                                    {text(
                                                        s.props.title ??
                                                            s.props.headline,
                                                    )}
                                                </span>
                                            </button>
                                            <SectionMenu
                                                first={i === 0}
                                                last={i === sections.length - 1}
                                                hidden={s.hidden}
                                                onUp={() => move(i, i - 1)}
                                                onDown={() => move(i, i + 1)}
                                                onHide={() =>
                                                    update(i, {
                                                        ...s,
                                                        hidden: !s.hidden,
                                                    })
                                                }
                                                onCopy={() =>
                                                    change([
                                                        ...sections.slice(
                                                            0,
                                                            i + 1,
                                                        ),
                                                        {
                                                            ...s,
                                                            id: `${s.type}-${Math.random().toString(36).slice(2, 8)}`,
                                                        },
                                                        ...sections.slice(
                                                            i + 1,
                                                        ),
                                                    ])
                                                }
                                                onDelete={() =>
                                                    change(
                                                        sections.filter(
                                                            (_, n) => n !== i,
                                                        ),
                                                    )
                                                }
                                            />
                                        </div>
                                        {open === s.id && (
                                            <div className="grid grid-cols-[minmax(0,1fr)] gap-3 border-t p-3">
                                                <Fields
                                                    site={props.site.ulid}
                                                    fields={
                                                        SCHEMA[s.type] ?? []
                                                    }
                                                    value={s.props}
                                                    onChange={(p) =>
                                                        update(i, {
                                                            ...s,
                                                            props: p,
                                                        })
                                                    }
                                                    media={media}
                                                    onUploaded={(m) =>
                                                        setMedia([m, ...media])
                                                    }
                                                    places={props.places}
                                                    rewrite={rewrite}
                                                />
                                            </div>
                                        )}
                                    </section>
                                ))}
                                <Button
                                    variant="outline"
                                    className="border-dashed"
                                    onClick={() => setGallery(true)}
                                    disabled={sections.length >= 40}
                                >
                                    <Plus aria-hidden="true" /> Add a section
                                    {open ? ' below the open one' : ''}
                                </Button>
                            </div>
                        )}

                        {tab === 'page' && (
                            <form
                                className="grid gap-3 text-sm"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    meta.patch(
                                        `/settings/website/pages/${page.ulid}`,
                                        opts,
                                    );
                                }}
                            >
                                <label className="grid gap-1">
                                    <span className="font-medium">
                                        Page title
                                    </span>
                                    <Input
                                        value={meta.data.title}
                                        onChange={(e) =>
                                            meta.setData(
                                                'title',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </label>
                                <label className="grid gap-1">
                                    <span className="font-medium">
                                        Search description
                                    </span>
                                    <Textarea
                                        rows={3}
                                        maxLength={300}
                                        placeholder="70 to 160 characters: what this page answers"
                                        value={meta.data.meta_description}
                                        onChange={(e) =>
                                            meta.setData(
                                                'meta_description',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <span className="text-xs text-muted-foreground">
                                        {meta.data.meta_description.length}{' '}
                                        characters
                                    </span>
                                </label>
                                <label className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        className="size-4"
                                        checked={meta.data.in_menu}
                                        onChange={(e) =>
                                            meta.setData(
                                                'in_menu',
                                                e.target.checked,
                                            )
                                        }
                                    />
                                    Show in the menu
                                </label>
                                {page.path !== '/' && (
                                    <label className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            className="size-4"
                                            checked={meta.data.noindex}
                                            onChange={(e) =>
                                                meta.setData(
                                                    'noindex',
                                                    e.target.checked,
                                                )
                                            }
                                        />
                                        Hide from search engines
                                    </label>
                                )}
                                <Button
                                    variant="outline"
                                    className="justify-self-start"
                                    disabled={meta.processing || !meta.isDirty}
                                >
                                    Save page settings
                                </Button>
                                {page.path !== '/' && (
                                    <Button
                                        type="button"
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
                            </form>
                        )}

                        {tab === 'seo' && (
                            <div className="grid gap-3">
                                <SeoScore check={check} />
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={aiBusy}
                                        onClick={async () => {
                                            const items = await assist<
                                                { q: string; a: string }[]
                                            >('faq', { content: pageText() });
                                            if (items && items.length > 0) {
                                                change([
                                                    ...sections,
                                                    {
                                                        type: 'faq',
                                                        id: `faq-${Math.random().toString(36).slice(2, 8)}`,
                                                        hidden: false,
                                                        props: {
                                                            title: 'Questions and answers',
                                                            items,
                                                        },
                                                    },
                                                ]);
                                            }
                                        }}
                                    >
                                        AI: add questions and answers
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={aiBusy}
                                        onClick={async () => {
                                            const m = await assist<{
                                                seo_title: string;
                                                meta_description: string;
                                            }>('meta', { content: pageText() });
                                            if (m) {
                                                meta.setData(
                                                    'meta_description',
                                                    m.meta_description,
                                                );
                                                setTab('page');
                                            }
                                        }}
                                    >
                                        AI: write the search description
                                    </Button>
                                    {aiBusy && (
                                        <span className="text-xs text-muted-foreground">
                                            Working…
                                        </span>
                                    )}
                                </div>
                                {aiError && (
                                    <p className="text-xs text-destructive">
                                        {aiError}
                                    </p>
                                )}
                                <AiNotesPanel notes={aiNotes} />
                            </div>
                        )}

                        {tab === 'versions' && (
                            <div className="grid gap-2 text-sm">
                                {versions.length === 0 ? (
                                    <p className="text-muted-foreground">
                                        Each time you publish, the earlier
                                        version is kept here so you can put it
                                        back.
                                    </p>
                                ) : (
                                    <ul className="grid gap-1">
                                        {versions.slice(0, 20).map((v) => (
                                            <li
                                                key={v.id}
                                                className="flex items-center gap-2 rounded-md border p-2"
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
                                                    Put back
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        )}
                    </div>
                </aside>

                <section
                    aria-label="Preview of your changes"
                    className="flex min-h-[70vh] justify-center p-4"
                >
                    {preview ? (
                        <iframe
                            ref={frame}
                            key={stamp}
                            title="Preview of your changes"
                            src={preview}
                            onLoad={() => tellPreview(open)}
                            className={`h-[calc(100svh-5.5rem)] rounded-lg border bg-white shadow-sm transition-[width] ${frameWidth}`}
                            sandbox="allow-same-origin allow-scripts allow-popups"
                        />
                    ) : (
                        <p className="self-center text-sm text-muted-foreground">
                            The preview appears once the website has an address.
                        </p>
                    )}
                </section>
            </div>

            <Dialog open={gallery} onOpenChange={setGallery}>
                <DialogContent className="sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>Add a section</DialogTitle>
                        <DialogDescription>
                            It goes{' '}
                            {open
                                ? 'below the section you have open'
                                : 'at the end of the page'}
                            , in your design's style. You can move it after.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2 sm:grid-cols-3">
                        {types.map((t) => {
                            const Icon =
                                SECTION_ICONS[t.type] ?? LayoutTemplate;

                            return (
                                <button
                                    key={t.type}
                                    type="button"
                                    onClick={() => add(t.type)}
                                    className="grid gap-1 rounded-lg border p-3 text-left text-sm transition hover:border-primary hover:bg-primary/5 focus-visible:ring-2 focus-visible:ring-primary"
                                >
                                    <Icon
                                        aria-hidden="true"
                                        className="size-5 text-primary"
                                    />
                                    <span className="font-medium">
                                        {t.label}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {SECTION_HINTS[t.type] ?? ''}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}

/** What each kind of section is for, in the "Add a section" gallery. */
const SECTION_HINTS: Record<string, string> = {
    hero: 'A big opening with your headline and the home search.',
    listings: 'Homes for sale from your MLS, filtered by place or price.',
    features: 'Up to six reasons to choose you, side by side.',
    text: 'A heading and paragraphs of your own words.',
    faq: 'Questions and answers; good for Google and AI answers.',
    cta: 'A strong line and one button, e.g. "Get my home value".',
    contact: 'A contact form that sends leads straight to your CRM.',
    testimonials: 'What clients say, or your Google reviews.',
    team: 'Your agents with photo, role, phone and email.',
    stats: 'A row of numbers, e.g. homes sold and years serving.',
    gallery: 'A grid of photos.',
    video: 'A YouTube or Vimeo video with a caption.',
    neighbourhoods: 'Areas you serve, with live market figures.',
};

const SECTION_ICONS: Record<string, LucideIcon> = {
    hero: LayoutTemplate,
    listings: Home,
    features: Sparkles,
    text: AlignLeft,
    faq: HelpCircle,
    cta: MousePointerClick,
    contact: Mail,
    testimonials: Quote,
    team: Users,
    stats: BarChart3,
    gallery: Images,
    video: Video,
    neighbourhoods: MapPin,
};

/** The small menu on each section: move, hide, copy, delete. */
function SectionMenu({
    first,
    last,
    hidden,
    onUp,
    onDown,
    onHide,
    onCopy,
    onDelete,
}: {
    first: boolean;
    last: boolean;
    hidden: boolean;
    onUp: () => void;
    onDown: () => void;
    onHide: () => void;
    onCopy: () => void;
    onDelete: () => void;
}) {
    return (
        <div className="flex shrink-0 items-center">
            <Button
                size="icon"
                variant="ghost"
                className="size-7"
                aria-label="Move up"
                title="Move up"
                disabled={first}
                onClick={onUp}
            >
                <ChevronUp aria-hidden="true" />
            </Button>
            <Button
                size="icon"
                variant="ghost"
                className="size-7"
                aria-label="Move down"
                title="Move down"
                disabled={last}
                onClick={onDown}
            >
                <ChevronDown aria-hidden="true" />
            </Button>
            <Button
                size="icon"
                variant="ghost"
                className="size-7"
                aria-label={hidden ? 'Show section' : 'Hide section'}
                title={hidden ? 'Show' : 'Hide'}
                onClick={onHide}
            >
                {hidden ? (
                    <EyeOff aria-hidden="true" />
                ) : (
                    <Eye aria-hidden="true" />
                )}
            </Button>
            <Button
                size="icon"
                variant="ghost"
                className="size-7"
                aria-label="Duplicate section"
                title="Duplicate"
                onClick={onCopy}
            >
                <Copy aria-hidden="true" />
            </Button>
            <Button
                size="icon"
                variant="ghost"
                className="size-7 text-destructive"
                aria-label="Delete section"
                title="Delete"
                onClick={onDelete}
            >
                <Trash2 aria-hidden="true" />
            </Button>
        </div>
    );
}

function Fields({
    site,
    fields,
    value,
    onChange,
    media,
    onUploaded,
    places,
    rewrite,
}: {
    rewrite: Rewrite;
    fields: Field[];
    value: Record<string, unknown>;
    onChange: (v: Record<string, unknown>) => void;
    media: MediaItem[];
    onUploaded: (m: MediaItem) => void;
    places: Props['places'];
    site: string;
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
                                {text(v).trim().length > 20 && (
                                    <span className="flex gap-1 font-normal">
                                        {(
                                            [
                                                'improve',
                                                'shorter',
                                                'longer',
                                            ] as const
                                        ).map((mode) => (
                                            <Button
                                                key={mode}
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                className="h-6 px-2 text-xs"
                                                onClick={async () => {
                                                    const out = await rewrite(
                                                        text(v),
                                                        mode,
                                                    );
                                                    if (out) {
                                                        set(f.key, out);
                                                    }
                                                }}
                                            >
                                                {mode === 'improve'
                                                    ? 'AI: improve'
                                                    : mode === 'shorter'
                                                      ? 'Shorter'
                                                      : 'Longer'}
                                            </Button>
                                        ))}
                                    </span>
                                )}
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
                                site={site}
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
                                            site={site}
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
                                        site={site}
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
                                            rewrite={rewrite}
                                            site={site}
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
