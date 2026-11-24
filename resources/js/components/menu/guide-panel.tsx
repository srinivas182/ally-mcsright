import { router, usePage } from '@inertiajs/react';
import { Check, ChevronDown, PlayCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

import { guideFor, type Guide } from '@/components/menu/guides';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';

const WATCHED_KEY = 'guide-lessons-opened';

/** Where HQ adds a lesson's video (Help videos): guide:<menu>:<lesson>, for example guide:website-idx:builder. */
export function videoKey(menuKey: string, lessonId: string): string {
    const menu = menuKey
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');

    return `guide:${menu}:${lessonId}`;
}

function readOpened(): string[] {
    try {
        const raw = window.localStorage.getItem(WATCHED_KEY);
        const list: unknown = raw ? JSON.parse(raw) : [];
        return Array.isArray(list)
            ? list.filter((v): v is string => typeof v === 'string')
            : [];
    } catch {
        return [];
    }
}

function saveOpened(list: string[]): void {
    try {
        window.localStorage.setItem(WATCHED_KEY, JSON.stringify(list));
    } catch {
        // Private windows can refuse storage; the guide still works.
    }
}

/** MN3: the guide for a main menu, and whether it should open by itself (the first time only). */
export function useGuide(menuKey: string | null): {
    guide: Guide | null;
    autoOpen: boolean;
    markSeen: () => void;
} {
    const { auth } = usePage().props as unknown as {
        auth: {
            user: { kind: string; ui_guides_seen?: string[] | null } | null;
        };
    };
    const kind = auth.user?.kind ?? 'owner';
    const guide = menuKey ? guideFor(menuKey, kind) : null;
    const seen = auth.user?.ui_guides_seen ?? [];
    const [marked, setMarked] = useState<string[]>([]);
    const autoOpen =
        guide !== null &&
        menuKey !== null &&
        !seen.includes(menuKey) &&
        !marked.includes(menuKey);

    return {
        guide,
        autoOpen,
        markSeen: () => {
            if (
                !menuKey ||
                seen.includes(menuKey) ||
                marked.includes(menuKey)
            ) {
                return;
            }
            setMarked([...marked, menuKey]);
            router.post(
                '/help/guides/seen',
                { key: menuKey },
                { preserveScroll: true, preserveState: true, only: [] },
            );
        },
    };
}

function Video({ url, title }: { url?: string; title: string }) {
    if (url) {
        // The server hands over player addresses (youtube-nocookie, Vimeo) or an https MP4.
        return url.endsWith('.mp4') ? (
            <video
                controls
                className="aspect-video w-full rounded-lg bg-black"
                src={url}
                title={title}
            >
                {/* Captions sit next to the video: same address, ending .vtt. */}
                <track
                    kind="captions"
                    src={url.replace(/\.mp4$/i, '.vtt')}
                    srcLang="en"
                    label="English"
                    default
                />
            </video>
        ) : (
            <iframe
                className="aspect-video w-full rounded-lg"
                src={url}
                title={title}
                allow="encrypted-media; picture-in-picture; fullscreen"
            />
        );
    }

    return (
        <div className="flex aspect-video w-full flex-col items-center justify-center gap-2 rounded-lg bg-gradient-to-br from-slate-800 to-primary/80 text-white">
            <PlayCircle className="size-10 opacity-90" aria-hidden="true" />
            <span className="font-semibold">{title}</span>
            <span className="text-xs opacity-80">
                60-second video coming soon
            </span>
        </div>
    );
}

/**
 * MN3: the help panel for a main menu: what it is for, three steps to start, a lesson for each part (why it helps,
 * how to do it, the AI that does the work for you, a 60-second video and a "Try it" link), good-to-know points, and
 * ways to get a person: the assistant or a call back from RightAlly.
 */
export function GuidePanel({
    guide,
    menuKey,
    open,
    onOpenChange,
    start = {},
}: {
    guide: Guide;
    menuKey: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Open at a lesson (from a page's AI marker) or straight at "Book a call". */
    start?: { lesson?: string; calling?: boolean };
}) {
    const page = usePage();
    const [lesson, setLesson] = useState<string | null>(null);
    const [opened, setOpened] = useState<string[]>([]);
    const [videos, setVideos] = useState<Record<string, string>>({});
    const [calling, setCalling] = useState(false);
    const [phone, setPhone] = useState('');
    const [when, setWhen] = useState('any');
    const [sent, setSent] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }
        if (start.lesson && guide.lessons.some((l) => l.id === start.lesson)) {
            setLesson(start.lesson);
        }
        setCalling(Boolean(start.calling));
        setOpened(readOpened());
        fetch('/help/guides/videos', {
            headers: { Accept: 'application/json' },
        })
            .then((r) => (r.ok ? r.json() : {}))
            .then((v: Record<string, string>) => setVideos(v ?? {}))
            .catch(() => setVideos({}));
    }, [open]);

    const toggle = (id: string) => {
        const key = `${menuKey}:${id}`;
        setLesson(lesson === id ? null : id);
        if (!opened.includes(key)) {
            const next = [...opened, key];
            setOpened(next);
            saveOpened(next);
        }
    };
    const done = guide.lessons.filter((l) =>
        opened.includes(`${menuKey}:${l.id}`),
    ).length;

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full overflow-y-auto sm:max-w-xl"
            >
                <SheetHeader className="gap-2 border-b pb-4">
                    <SheetTitle className="text-2xl">{guide.title}</SheetTitle>
                    <SheetDescription className="text-base leading-relaxed">
                        {guide.intro}
                    </SheetDescription>
                </SheetHeader>
                <div className="grid gap-6 px-4 pb-8 text-base leading-relaxed">
                    <section className="grid gap-2">
                        <h3 className="text-lg font-semibold">
                            Get started in 3 steps
                        </h3>
                        <ol className="grid gap-2">
                            {guide.start.map((s, i) => (
                                <li key={s} className="flex gap-3">
                                    <span className="grid size-7 shrink-0 place-items-center rounded-full bg-primary/10 text-sm font-bold text-primary">
                                        {i + 1}
                                    </span>
                                    <span>{s}</span>
                                </li>
                            ))}
                        </ol>
                    </section>

                    <section className="grid gap-2">
                        <div className="flex items-baseline justify-between">
                            <h3 className="text-lg font-semibold">Lessons</h3>
                            <span className="text-sm text-muted-foreground">
                                {done} of {guide.lessons.length} opened
                            </span>
                        </div>
                        <div className="grid gap-2">
                            {guide.lessons.map((l) => {
                                const isOpen = lesson === l.id;
                                const seen = opened.includes(
                                    `${menuKey}:${l.id}`,
                                );

                                return (
                                    <div
                                        key={l.id}
                                        className="rounded-xl border"
                                    >
                                        <button
                                            type="button"
                                            onClick={() => toggle(l.id)}
                                            aria-expanded={isOpen}
                                            className="flex w-full items-center gap-3 px-4 py-3 text-left"
                                        >
                                            <span
                                                className={`grid size-6 shrink-0 place-items-center rounded-full border ${seen ? 'border-primary bg-primary text-primary-foreground' : 'border-muted-foreground/40'}`}
                                            >
                                                {seen && (
                                                    <Check
                                                        className="size-4"
                                                        aria-hidden="true"
                                                    />
                                                )}
                                            </span>
                                            <span className="flex-1">
                                                <span className="block font-semibold">
                                                    {l.title}
                                                </span>
                                                <span className="block text-sm text-muted-foreground">
                                                    {l.why}
                                                </span>
                                            </span>
                                            <ChevronDown
                                                className={`size-5 shrink-0 transition-transform ${isOpen ? 'rotate-180' : ''}`}
                                                aria-hidden="true"
                                            />
                                        </button>
                                        {isOpen && (
                                            <div className="grid gap-4 border-t px-4 py-4">
                                                <Video
                                                    url={
                                                        videos[
                                                            `guide:${menuKey}:${l.id}`
                                                        ]
                                                    }
                                                    title={l.title}
                                                />
                                                <div className="grid gap-1">
                                                    <h4 className="font-semibold">
                                                        How to do it
                                                    </h4>
                                                    <ol className="grid list-decimal gap-1 pl-5">
                                                        {l.steps.map((s) => (
                                                            <li key={s}>{s}</li>
                                                        ))}
                                                    </ol>
                                                </div>
                                                {l.ai && (
                                                    <div className="grid gap-2 rounded-lg border border-primary/20 bg-primary/5 p-3">
                                                        <p className="font-semibold">
                                                            Let the AI do the
                                                            work{' '}
                                                            <span className="ml-1 rounded bg-primary/15 px-1.5 py-0.5 text-xs font-bold tracking-wide text-primary uppercase">
                                                                {l.ai.name}
                                                            </span>
                                                        </p>
                                                        <p>{l.ai.what}</p>
                                                        {l.ai.example && (
                                                            <p className="rounded-md border bg-background p-2 text-sm">
                                                                <span className="font-semibold">
                                                                    Try
                                                                    saying:{' '}
                                                                </span>
                                                                <i>
                                                                    {
                                                                        l.ai
                                                                            .example
                                                                    }
                                                                </i>
                                                            </p>
                                                        )}
                                                    </div>
                                                )}
                                                {l.tips &&
                                                    l.tips.length > 0 && (
                                                        <div className="grid gap-1">
                                                            <h4 className="font-semibold">
                                                                Get the most
                                                                from it
                                                            </h4>
                                                            <ul className="grid list-disc gap-1 pl-5">
                                                                {l.tips.map(
                                                                    (t) => (
                                                                        <li
                                                                            key={
                                                                                t
                                                                            }
                                                                        >
                                                                            {t}
                                                                        </li>
                                                                    ),
                                                                )}
                                                            </ul>
                                                        </div>
                                                    )}
                                                {l.try && (
                                                    <div>
                                                        <Button
                                                            onClick={() => {
                                                                onOpenChange(
                                                                    false,
                                                                );
                                                                router.visit(
                                                                    l.try!.href,
                                                                );
                                                            }}
                                                        >
                                                            {l.try.label}
                                                        </Button>
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </section>

                    {guide.goodToKnow && guide.goodToKnow.length > 0 && (
                        <section className="grid gap-2">
                            <h3 className="text-lg font-semibold">
                                Good to know
                            </h3>
                            <ul className="grid list-disc gap-1 pl-5">
                                {guide.goodToKnow.map((g) => (
                                    <li key={g}>{g}</li>
                                ))}
                            </ul>
                        </section>
                    )}

                    <section className="grid gap-3 rounded-xl bg-muted/50 p-4">
                        <h3 className="text-lg font-semibold">
                            Still unsure? We are here.
                        </h3>
                        {sent ? (
                            <p
                                role="status"
                                className="font-medium text-primary"
                            >
                                Thank you. A RightAlly specialist will call you{' '}
                                {when === 'any' ? 'soon' : `this ${when}`}.
                            </p>
                        ) : calling ? (
                            <form
                                className="grid gap-3"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    router.post(
                                        '/help/callback',
                                        {
                                            phone,
                                            when,
                                            page: page.url,
                                            note: `About: ${guide.title}`,
                                        },
                                        {
                                            preserveScroll: true,
                                            preserveState: true,
                                            onSuccess: () => setSent(true),
                                        },
                                    );
                                }}
                            >
                                <label className="grid gap-1">
                                    Your phone number
                                    <input
                                        className="h-11 rounded-md border bg-background px-3"
                                        inputMode="tel"
                                        autoComplete="tel"
                                        value={phone}
                                        onChange={(e) =>
                                            setPhone(e.target.value)
                                        }
                                        required
                                    />
                                </label>
                                <label className="grid gap-1">
                                    Best time
                                    <select
                                        className="h-11 rounded-md border bg-background px-3"
                                        value={when}
                                        onChange={(e) =>
                                            setWhen(e.target.value)
                                        }
                                    >
                                        <option value="any">Any time</option>
                                        <option value="morning">Morning</option>
                                        <option value="afternoon">
                                            Afternoon
                                        </option>
                                        <option value="evening">
                                            Early evening
                                        </option>
                                    </select>
                                </label>
                                <div className="flex gap-2">
                                    <Button type="submit">
                                        Call me for 15 minutes
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setCalling(false)}
                                    >
                                        Back
                                    </Button>
                                </div>
                            </form>
                        ) : (
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        onOpenChange(false);
                                        window.dispatchEvent(
                                            new CustomEvent('assistant:open'),
                                        );
                                    }}
                                >
                                    Ask the assistant
                                </Button>
                                <Button onClick={() => setCalling(true)}>
                                    Book a 15-minute call
                                </Button>
                            </div>
                        )}
                    </section>
                </div>
            </SheetContent>
        </Sheet>
    );
}
