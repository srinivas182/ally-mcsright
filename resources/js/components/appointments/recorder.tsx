import { router } from '@inertiajs/react';
import { CircleIcon, SquareIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { postForm, postJson } from '@/lib/http';

type Notes = {
    summary: string;
    client_wants: string[];
    objections: { topic: string; said: string; handled: string }[];
    follow_ups: string[];
    strengths?: string[];
    try_next?: string[];
    fair_housing?: string[];
};

export type RecordingRow = {
    ulid: string;
    status: string;
    when: string;
    seconds: number | null;
    consent: { names: string; at: string };
    notes: Notes | null;
    talk_share: number | null;
    shared: boolean;
    own: boolean;
    reason: string | null;
    transcript: string | null;
};

export type RecordingProps = {
    blocker: string | null;
    wording: string;
    list: RecordingRow[];
};

const HANDLED: Record<string, string> = {
    well: 'answered well',
    partly: 'partly answered',
    not: 'not answered yet',
};

const clock = (s: number) =>
    `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;

/**
 * VO2 (specs/listings/05): record an in-person appointment after everyone agrees, in 30-second pieces, then show
 * the notes and the agent's private coaching.
 */
export function AppointmentRecorder({
    consultation,
    recording,
}: {
    consultation: string;
    recording: RecordingProps;
}) {
    const [names, setNames] = useState('');
    const [agreed, setAgreed] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [active, setActive] = useState<string | null>(null);
    const [seconds, setSeconds] = useState(0);
    const recorder = useRef<MediaRecorder | null>(null);
    const queue = useRef<Promise<void>>(Promise.resolve());
    const seq = useRef(0);

    useEffect(() => {
        if (active === null) {
            return;
        }
        const t = window.setInterval(() => setSeconds((s) => s + 1), 1000);
        return () => window.clearInterval(t);
    }, [active]);

    const send = (id: string, blob: Blob) => {
        const n = seq.current++;
        queue.current = queue.current.then(async () => {
            const form = new FormData();
            form.append('seq', String(n));
            form.append(
                'audio',
                new File([blob], `${n}.webm`, {
                    type: blob.type || 'audio/webm',
                }),
            );
            for (let attempt = 0; attempt < 3; attempt++) {
                try {
                    await postForm(
                        `/appointments/recordings/${id}/piece`,
                        form,
                    );
                    return;
                } catch (e) {
                    if (attempt === 2) {
                        setError(
                            e instanceof Error
                                ? e.message
                                : 'A piece could not be sent.',
                        );
                    }
                    await new Promise((r) => setTimeout(r, 2000));
                }
            }
        });
    };

    const start = async () => {
        setError(null);
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: true,
            });
            const { recording: id } = await postJson<{ recording: string }>(
                `/appointments/${consultation}/recording`,
                { names, agreed },
            );
            const r = new MediaRecorder(stream);
            r.ondataavailable = (e) => {
                if (e.data.size > 0) {
                    send(id, e.data);
                }
            };
            r.onstop = () => stream.getTracks().forEach((t) => t.stop());
            r.start(30_000);
            recorder.current = r;
            seq.current = 0;
            setSeconds(0);
            setActive(id);
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : 'The microphone could not be opened.',
            );
        }
    };

    const stop = () => {
        const id = active;
        if (id === null || recorder.current === null) {
            return;
        }
        recorder.current.stop();
        setActive(null);
        // Wait for the last piece to be sent, then stop.
        window.setTimeout(() => {
            void queue.current.then(() =>
                router.post(
                    `/appointments/recordings/${id}/stop`,
                    { seconds },
                    { preserveScroll: true },
                ),
            );
        }, 500);
    };

    return (
        <section className="grid gap-4 rounded-lg border p-5 text-base">
            <h2 className="text-xl font-semibold">
                Record this appointment (optional)
            </h2>
            {recording.blocker !== null ? (
                <p className="text-muted-foreground">{recording.blocker}</p>
            ) : active !== null ? (
                <div className="flex flex-wrap items-center gap-3">
                    <Badge variant="destructive" className="gap-1 text-base">
                        <CircleIcon
                            className="size-3 fill-current"
                            aria-hidden="true"
                        />
                        Recording {clock(seconds)}
                    </Badge>
                    <Button onClick={stop}>
                        <SquareIcon aria-hidden="true" />
                        Stop and make my notes
                    </Button>
                </div>
            ) : (
                <div className="grid gap-3">
                    <p className="text-muted-foreground">
                        Get notes for the file and private coaching afterwards.
                        Read this aloud first, and record only if everyone
                        agrees:
                    </p>
                    <blockquote className="rounded-md border-l-4 bg-muted/50 p-3">
                        {recording.wording}
                    </blockquote>
                    <label className="grid gap-1">
                        Names of everyone here who agreed
                        <Input
                            value={names}
                            onChange={(e) => setNames(e.target.value)}
                            placeholder="Pat Lee, Chris Lee"
                        />
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            className="size-5"
                            checked={agreed}
                            onChange={(e) => setAgreed(e.target.checked)}
                        />
                        I read it aloud and everyone here agreed
                    </label>
                    <Button
                        className="justify-self-start"
                        disabled={!agreed || names.trim().length < 2}
                        onClick={() => void start()}
                    >
                        <CircleIcon aria-hidden="true" />
                        Start recording
                    </Button>
                </div>
            )}
            {error && (
                <p role="alert" className="text-destructive">
                    {error}
                </p>
            )}
            {recording.list.map((r) => (
                <RecordingNotes key={r.ulid} r={r} />
            ))}
        </section>
    );
}

function RecordingNotes({ r }: { r: RecordingRow }) {
    const base = `/appointments/recordings/${r.ulid}`;
    const post = (path: string, data: Record<string, boolean> = {}) =>
        router.post(`${base}/${path}`, data, { preserveScroll: true });

    return (
        <article className="grid gap-2 rounded-md bg-muted/40 p-4">
            <p className="text-sm text-muted-foreground">
                Recorded {new Date(r.when).toLocaleString()}
                {r.seconds !== null &&
                    ` · ${Math.max(1, Math.round(r.seconds / 60))} min`}{' '}
                · agreed by {r.consent.names}
            </p>
            {['recording', 'uploaded', 'writing'].includes(r.status) && (
                <p>Writing your notes. This takes a few minutes.</p>
            )}
            {r.status === 'failed' && (
                <div className="flex flex-wrap items-center gap-2">
                    <p>{r.reason}</p>
                    {r.own && (
                        <Button size="sm" onClick={() => post('retry')}>
                            Try again
                        </Button>
                    )}
                </div>
            )}
            {r.status === 'deleted' && <p>Deleted.</p>}
            {r.notes && (
                <div className="grid gap-2">
                    <p>{r.notes.summary}</p>
                    {r.notes.client_wants.length > 0 && (
                        <div>
                            <h3 className="font-medium">What they want</h3>
                            <ul className="list-disc pl-6">
                                {r.notes.client_wants.map((w) => (
                                    <li key={w}>{w}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {r.notes.objections.length > 0 && (
                        <div>
                            <h3 className="font-medium">Concerns raised</h3>
                            <ul className="list-disc pl-6">
                                {r.notes.objections.map((o) => (
                                    <li key={o.topic + o.said}>
                                        <strong>{o.topic}</strong>: "{o.said}" (
                                        {HANDLED[o.handled] ?? o.handled})
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {r.notes.follow_ups.length > 0 && (
                        <div>
                            <h3 className="font-medium">You said you would</h3>
                            <ul className="list-disc pl-6">
                                {r.notes.follow_ups.map((f) => (
                                    <li key={f}>{f}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {r.notes.strengths !== undefined && (
                        <div className="grid gap-2 rounded-md border bg-background p-3">
                            <h3 className="font-medium">
                                Your coaching{' '}
                                {r.own && !r.shared && (
                                    <span className="text-sm font-normal text-muted-foreground">
                                        (only you see this)
                                    </span>
                                )}
                            </h3>
                            {r.talk_share !== null && (
                                <p>
                                    You talked {r.talk_share}% of the time.
                                    {r.talk_share > 60
                                        ? ' Top agents often listen more than they talk; try more questions.'
                                        : ' Good balance of talking and listening.'}
                                </p>
                            )}
                            {(r.notes.strengths ?? []).length > 0 && (
                                <p>
                                    <strong>Went well:</strong>{' '}
                                    {(r.notes.strengths ?? []).join(' · ')}
                                </p>
                            )}
                            {(r.notes.try_next ?? []).length > 0 && (
                                <p>
                                    <strong>Try next time:</strong>{' '}
                                    {(r.notes.try_next ?? []).join(' · ')}
                                </p>
                            )}
                            {(r.notes.fair_housing ?? []).length > 0 && (
                                <p role="note" className="text-destructive">
                                    <strong>Fair Housing reminder:</strong>{' '}
                                    {(r.notes.fair_housing ?? []).join(' · ')}
                                </p>
                            )}
                        </div>
                    )}
                </div>
            )}
            {r.transcript && (
                <details>
                    <summary className="cursor-pointer text-sm text-muted-foreground">
                        Read the whole conversation
                    </summary>
                    <p className="mt-1 text-sm whitespace-pre-line">
                        {r.transcript}
                    </p>
                </details>
            )}
            {r.own && r.status !== 'deleted' && (
                <div className="flex flex-wrap gap-2">
                    {r.status === 'done' && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => post('share', { shared: !r.shared })}
                        >
                            {r.shared
                                ? 'Stop sharing coaching with my broker'
                                : 'Share coaching with my broker'}
                        </Button>
                    )}
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => post('delete')}
                    >
                        Delete recording and notes
                    </Button>
                </div>
            )}
        </article>
    );
}
