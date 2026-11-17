import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Line = { who: string; text: string };
type Feedback = {
    rubric?: { item: string; label: string; met: boolean; note: string }[];
    went_well?: string[];
    try_next?: string[];
    better_line?: string;
    next_step?: string;
    lesson?: string;
} | null;

type SpeechResultEvent = {
    results: ArrayLike<ArrayLike<{ transcript: string }>>;
};
type Recognizer = {
    lang: string;
    interimResults: boolean;
    onresult: ((e: SpeechResultEvent) => void) | null;
    onend: (() => void) | null;
    start: () => void;
    stop: () => void;
};

/** One role-play (LN3). Speaking uses the browser's own speech recognition: only the words are sent, never audio. */
export default function RolePlay({
    session,
}: {
    session: {
        ulid: string;
        kind: string;
        title: string;
        goal: string | null;
        transcript: Line[];
        turns: number;
        max_turns: number;
        score: number | null;
        feedback: Feedback;
        finished: boolean;
    };
}) {
    const form = useForm({ text: '' });
    const [voice, setVoice] = useState(false);
    const [listening, setListening] = useState(false);
    const rec = useRef<Recognizer | null>(null);
    const last = session.transcript.at(-1);
    const coaching = session.kind === 'call' || session.kind === 'debrief';
    const base = `/learn/practice/${session.ulid}`;

    const w = window as unknown as {
        SpeechRecognition?: new () => Recognizer;
        webkitSpeechRecognition?: new () => Recognizer;
    };
    const Speech = w.SpeechRecognition ?? w.webkitSpeechRecognition;

    useEffect(() => {
        if (voice && last?.who === 'client' && 'speechSynthesis' in window) {
            window.speechSynthesis.cancel();
            window.speechSynthesis.speak(
                new SpeechSynthesisUtterance(last.text),
            );
        }
    }, [voice, last]);

    const listen = () => {
        if (!Speech) return;
        const r = new Speech();
        r.lang = 'en-US';
        r.interimResults = false;
        r.onresult = (e) => {
            const said = Array.from(e.results)
                .map((x) => x[0]?.transcript ?? '')
                .join(' ');
            form.setData('text', `${form.data.text} ${said}`.trim());
        };
        r.onend = () => setListening(false);
        rec.current = r;
        setListening(true);
        r.start();
    };

    return (
        <>
            <Head title={session.title} />
            <div className="mx-auto grid w-full max-w-3xl gap-4 p-4 text-base leading-relaxed md:p-6">
                <nav aria-label="Breadcrumb" className="text-sm">
                    <a
                        href="/learn/practice"
                        className="text-primary underline"
                    >
                        Practice
                    </a>{' '}
                    › {session.title}
                </nav>
                <h1 className="text-2xl font-semibold">{session.title}</h1>
                {session.goal && !coaching && (
                    <p className="text-muted-foreground">
                        Your goal: {session.goal}
                    </p>
                )}
                {!coaching && (
                    <section
                        aria-label="Conversation"
                        aria-live="polite"
                        className="grid gap-2 rounded-lg border p-4"
                    >
                        {session.transcript.map((l, i) => (
                            <p
                                key={i}
                                className={`max-w-[85%] rounded-lg px-3 py-2 ${l.who === 'agent' ? 'justify-self-end bg-primary text-primary-foreground' : 'bg-muted'}`}
                            >
                                <span className="sr-only">
                                    {l.who === 'agent' ? 'You: ' : 'Client: '}
                                </span>
                                {l.text}
                            </p>
                        ))}
                    </section>
                )}
                {!session.finished && !coaching && (
                    <form
                        className="grid gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`${base}/reply`, {
                                preserveScroll: true,
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="size-5"
                                checked={voice}
                                onChange={(e) => setVoice(e.target.checked)}
                            />
                            Speak and listen instead of typing
                            {!Speech &&
                                voice &&
                                ' (your browser cannot listen; type instead)'}
                        </label>
                        <textarea
                            aria-label="What you say"
                            rows={3}
                            className="rounded border px-3 py-2"
                            value={form.data.text}
                            onChange={(e) =>
                                form.setData('text', e.target.value)
                            }
                        />
                        {form.errors.text && (
                            <p role="alert" className="text-red-700">
                                {form.errors.text}
                            </p>
                        )}
                        <span className="flex flex-wrap items-center gap-2">
                            {voice && Speech && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        listening
                                            ? rec.current?.stop()
                                            : listen()
                                    }
                                >
                                    {listening
                                        ? 'Stop listening'
                                        : 'Hold a thought — tap to speak'}
                                </Button>
                            )}
                            <Button
                                disabled={form.processing || !form.data.text}
                            >
                                {form.processing
                                    ? 'Client is answering…'
                                    : 'Say it'}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                disabled={session.turns < 2}
                                onClick={() =>
                                    router.post(
                                        `${base}/finish`,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Finish and get feedback
                            </Button>
                            <span className="text-xs text-muted-foreground">
                                {session.turns} of {session.max_turns} turns
                            </span>
                        </span>
                    </form>
                )}
                {session.finished && session.feedback && (
                    <section
                        className="grid gap-3 rounded-lg border p-4"
                        aria-label="Feedback"
                    >
                        {session.score !== null && (
                            <p className="text-lg font-semibold">
                                Checklist: {session.score}%{' '}
                                <Badge variant="secondary">practice only</Badge>
                            </p>
                        )}
                        {session.feedback.rubric && (
                            <ul className="grid gap-1">
                                {session.feedback.rubric.map((r) => (
                                    <li key={r.item}>
                                        {r.met ? '✓' : '○'}{' '}
                                        <strong>{r.label}</strong>
                                        {r.note && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                — {r.note}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                        {(session.feedback.went_well ?? []).length > 0 && (
                            <div>
                                <h2 className="font-semibold">
                                    What went well
                                </h2>
                                <ul className="list-disc pl-5">
                                    {session.feedback.went_well!.map((l) => (
                                        <li key={l}>{l}</li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        {(session.feedback.try_next ?? []).length > 0 && (
                            <div>
                                <h2 className="font-semibold">Try next time</h2>
                                <ul className="list-disc pl-5">
                                    {session.feedback.try_next!.map((l) => (
                                        <li key={l}>{l}</li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        {session.feedback.better_line && (
                            <p>
                                <strong>A line to try:</strong> “
                                {session.feedback.better_line}”
                            </p>
                        )}
                        {session.feedback.next_step && (
                            <p>
                                <strong>Next 48 hours:</strong>{' '}
                                {session.feedback.next_step}
                            </p>
                        )}
                        {session.feedback.lesson && (
                            <p>
                                <strong>Lesson:</strong>{' '}
                                {session.feedback.lesson}
                            </p>
                        )}
                        {session.kind === 'debrief' &&
                            session.feedback.lesson && (
                                <Button
                                    variant="outline"
                                    className="justify-self-start"
                                    onClick={() =>
                                        router.post(
                                            `${base}/suggest`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Suggest this for the office playbook
                                </Button>
                            )}
                        <Button asChild className="justify-self-start">
                            <a href="/learn/practice">Back to practice</a>
                        </Button>
                    </section>
                )}
            </div>
        </>
    );
}
