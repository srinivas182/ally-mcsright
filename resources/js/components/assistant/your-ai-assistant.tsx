import { Link, usePage } from '@inertiajs/react';
import { SendIcon, SparklesIcon, XIcon } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { VoiceButton } from '@/components/crm/voice-button';
import { Button } from '@/components/ui/button';
import { getJson, postJson } from '@/lib/http';

type Turn = {
    ulid: string;
    role: 'person' | 'assistant' | 'card';
    text: string | null;
    status: string | null;
    at: string;
    card: {
        type: 'preview' | 'connect' | null;
        title: string | null;
        preview: Record<string, unknown> | null;
        page: string | null;
        question: string | null;
    } | null;
};
type Reply = { conversation: string | null; turns: Turn[] };

const label = (k: string) => k.replaceAll('_', ' ');
const show = (v: unknown): string =>
    Array.isArray(v)
        ? v.map(show).join(', ')
        : v !== null && typeof v === 'object'
          ? Object.entries(v as Record<string, unknown>)
                .map(([k, x]) => `${label(k)}: ${show(x)}`)
                .join('; ')
          : typeof v === 'string' ||
              typeof v === 'number' ||
              typeof v === 'boolean'
            ? String(v)
            : '';

/**
 * "Your AI Assistant" (specs/know/03): bottom-right on every back-office page; also Ctrl/⌘ J. Type or speak.
 * It answers from the person's own data, uses connected apps, asks when something is missing, offers to connect an
 * app, and shows a preview before changing anything (nothing happens until "Do it").
 */
export function YourAiAssistant() {
    const page = usePage();
    const [open, setOpen] = useState(false);
    const [turns, setTurns] = useState<Turn[]>([]);
    const [conversation, setConversation] = useState<string | null>(null);
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [people, setPeople] = useState<
        { key: string; label: string }[] | null
    >(null);
    const [handedOff, setHandedOff] = useState<string | null>(null);
    const loaded = useRef(false);
    const end = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key.toLowerCase() === 'j' && (e.metaKey || e.ctrlKey)) {
                e.preventDefault();
                setOpen((v) => !v);
            }
        };
        // Simple mode's "Talk to RightAlly" button (SM1) opens the assistant from anywhere.
        const onOpen = () => setOpen(true);
        window.addEventListener('keydown', onKey);
        window.addEventListener('assistant:open', onOpen);
        return () => {
            window.removeEventListener('keydown', onKey);
            window.removeEventListener('assistant:open', onOpen);
        };
    }, []);

    useEffect(() => {
        if (open && !loaded.current) {
            loaded.current = true;
            getJson<Reply>('/assistant')
                .then((r) => {
                    setConversation(r.conversation);
                    setTurns(r.turns);
                })
                .catch(() => undefined);
        }
    }, [open]);

    useEffect(() => end.current?.scrollIntoView({ block: 'end' }), [turns]);

    const merge = (r: Reply) => {
        setConversation(r.conversation);
        setTurns((old) => {
            const seen = new Map(old.map((t) => [t.ulid, t]));
            r.turns.forEach((t) => seen.set(t.ulid, t));
            return [...seen.values()].sort((a, b) =>
                a.ulid.localeCompare(b.ulid),
            );
        });
    };

    const ask = useCallback(
        (question: string) => {
            const q = question.trim();
            if (q === '' || busy) {
                return;
            }
            setBusy(true);
            setError(null);
            setText('');
            postJson<Reply>('/assistant/ask', {
                text: q,
                conversation,
                path: page.url,
                title: document.title,
            })
                .then(merge)
                .catch((e: Error) => setError(e.message))
                .finally(() => setBusy(false));
        },
        [busy, conversation, page.url],
    );

    // AS1: hand the conversation to a person (the broker, or RightAlly support).
    const showPeople = () =>
        getJson<{ targets: { key: string; label: string }[] }>(
            '/assistant/handoffs',
        )
            .then((r) => setPeople(r.targets))
            .catch(() => setPeople([]));
    const handOff = (target: string) => {
        if (!conversation) {
            return;
        }
        setBusy(true);
        postJson<{ message: string }>('/assistant/handoff', {
            target,
            conversation,
        })
            .then((r) => {
                setHandedOff(r.message);
                setPeople(null);
            })
            .catch((e: Error) => setError(e.message))
            .finally(() => setBusy(false));
    };

    const doIt = (t: Turn) => {
        setBusy(true);
        postJson<Reply>(`/assistant/turns/${t.ulid}/do`, {})
            .then(merge)
            .catch((e: Error) => setError(e.message))
            .finally(() => setBusy(false));
    };
    const cancel = (t: Turn) =>
        postJson(`/assistant/turns/${t.ulid}/cancel`, {}).then(() =>
            setTurns((old) =>
                old.map((x) =>
                    x.ulid === t.ulid ? { ...x, status: 'cancelled' } : x,
                ),
            ),
        );

    if (!open) {
        return (
            <Button
                className="fixed right-4 bottom-20 z-40 gap-2 rounded-full shadow-lg md:bottom-4"
                onClick={() => setOpen(true)}
                aria-label="Open Your AI Assistant (Ctrl J)"
            >
                <SparklesIcon aria-hidden="true" />
                <span className="hidden sm:inline">Your AI Assistant</span>
            </Button>
        );
    }

    return (
        <section
            aria-label="Your AI Assistant"
            className="fixed right-0 bottom-0 z-50 flex h-[85vh] w-full flex-col border bg-background shadow-xl sm:right-4 sm:bottom-4 sm:h-[36rem] sm:w-[26rem] sm:rounded-xl"
        >
            <header className="flex items-center justify-between border-b px-4 py-2">
                <p className="flex items-center gap-2 font-medium">
                    <SparklesIcon aria-hidden="true" className="size-4" />
                    Your AI Assistant
                </p>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Close"
                    onClick={() => setOpen(false)}
                >
                    <XIcon />
                </Button>
            </header>
            <div className="flex-1 space-y-3 overflow-y-auto p-4 text-sm">
                {turns.length === 0 && (
                    <p className="text-muted-foreground">
                        Ask about your contacts, deals, tasks or money, or ask
                        me to do something in RightAlly or a connected app. For
                        example: “Who should I call today?” or “What's due on my
                        deals this week?”. I'm an AI and I only see what you can
                        see.
                    </p>
                )}
                {turns.map((t) => (
                    <div
                        key={t.ulid}
                        className={
                            t.role === 'person'
                                ? 'ml-8 rounded-lg bg-primary p-2 text-primary-foreground'
                                : 'mr-8 rounded-lg bg-muted p-2'
                        }
                    >
                        {t.text && (
                            <p className="whitespace-pre-wrap">{t.text}</p>
                        )}
                        {t.card?.type === 'preview' && (
                            <div className="mt-2 grid gap-2 rounded-md border bg-background p-2">
                                <ul className="grid gap-1">
                                    {Object.entries(t.card.preview ?? {}).map(
                                        ([k, v]) => (
                                            <li key={k}>
                                                <span className="text-muted-foreground">
                                                    {label(k)}:
                                                </span>{' '}
                                                {show(v)}
                                            </li>
                                        ),
                                    )}
                                </ul>
                                {t.status === 'waiting' ? (
                                    <div className="flex gap-2">
                                        <Button
                                            size="sm"
                                            disabled={busy}
                                            onClick={() => doIt(t)}
                                        >
                                            Do it
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => cancel(t)}
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                ) : (
                                    <p className="text-muted-foreground">
                                        {t.status === 'done'
                                            ? 'Done.'
                                            : 'Cancelled.'}
                                    </p>
                                )}
                            </div>
                        )}
                        {t.card?.type === 'connect' && (
                            <div className="mt-2 flex flex-wrap gap-2">
                                <Button size="sm" asChild>
                                    <Link href={t.card.page ?? '/apps'}>
                                        Connect {t.card.title}
                                    </Link>
                                </Button>
                                {t.card.question && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={busy}
                                        onClick={() =>
                                            ask(t.card?.question ?? '')
                                        }
                                    >
                                        Carry on
                                    </Button>
                                )}
                            </div>
                        )}
                    </div>
                ))}
                {conversation &&
                    turns.length > 0 &&
                    people === null &&
                    !handedOff && (
                        <Button
                            size="sm"
                            variant="ghost"
                            className="justify-self-start"
                            onClick={showPeople}
                        >
                            Talk to a person instead
                        </Button>
                    )}
                {people !== null && (
                    <div
                        className="grid gap-2 rounded-md border p-2"
                        role="group"
                        aria-label="Who to ask"
                    >
                        <p>Who should I pass this conversation to?</p>
                        {people.length === 0 && (
                            <p className="text-muted-foreground">
                                There is nobody to pass it to here.
                            </p>
                        )}
                        <div className="flex flex-wrap gap-2">
                            {people.map((p) => (
                                <Button
                                    key={p.key}
                                    size="sm"
                                    disabled={busy}
                                    onClick={() => handOff(p.key)}
                                >
                                    {p.label}
                                </Button>
                            ))}
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => setPeople(null)}
                            >
                                Not now
                            </Button>
                        </div>
                    </div>
                )}
                {handedOff && (
                    <p role="status" className="rounded-md bg-muted/50 p-2">
                        {handedOff}
                    </p>
                )}
                {busy && <p className="text-muted-foreground">Thinking…</p>}
                {error && <p className="text-destructive">{error}</p>}
                <div ref={end} />
            </div>
            <form
                className="flex items-center gap-2 border-t p-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    ask(text);
                }}
            >
                <textarea
                    aria-label="Ask Your AI Assistant"
                    className="max-h-28 min-h-10 flex-1 resize-none rounded-md border bg-background px-3 py-2 text-sm"
                    rows={1}
                    maxLength={2000}
                    value={text}
                    placeholder="Ask or tell me what to do…"
                    onChange={(e) => setText(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && !e.shiftKey) {
                            e.preventDefault();
                            ask(text);
                        }
                    }}
                />
                <VoiceButton onText={(t) => ask(t)} label="Speak" />
                <Button
                    type="submit"
                    size="icon"
                    aria-label="Send"
                    disabled={busy || text.trim() === ''}
                >
                    <SendIcon />
                </Button>
            </form>
        </section>
    );
}
