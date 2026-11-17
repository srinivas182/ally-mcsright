import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Agent = {
    id: number;
    name: string;
    last_active: string | null;
    outreach_7d: number;
    tasks_7d: number;
    deals_30d: number;
    courses_90d: number;
    practice_30d: number;
    risk: number;
    reasons: string[];
};
type Milestone = {
    user_id: number;
    name: string;
    occasion: string;
    label: string;
    when: string;
    suggestion: string;
};

/** Records a short clip in the browser (MediaRecorder); nothing is uploaded until the form is sent. */
function useRecorder() {
    const [blob, setBlob] = useState<Blob | null>(null);
    const [recording, setRecording] = useState(false);
    const rec = useRef<MediaRecorder | null>(null);
    const start = async () => {
        const stream = await navigator.mediaDevices.getUserMedia({
            audio: true,
        });
        const r = new MediaRecorder(stream);
        const chunks: Blob[] = [];
        r.ondataavailable = (e) => chunks.push(e.data);
        r.onstop = () => {
            setBlob(new Blob(chunks, { type: r.mimeType || 'audio/webm' }));
            stream.getTracks().forEach((t) => t.stop());
        };
        rec.current = r;
        r.start();
        setRecording(true);
    };
    const stop = () => {
        rec.current?.stop();
        setRecording(false);
    };
    return { blob, recording, start, stop, clear: () => setBlob(null) };
}

function Send({ m, voice }: { m: Milestone; voice: boolean }) {
    const [text, setText] = useState(m.suggestion);
    const [ai, setAi] = useState(false);
    const r = useRecorder();
    return (
        <div className="grid gap-2 rounded border p-3">
            <p className="flex flex-wrap items-center gap-2">
                <strong>{m.name}</strong>{' '}
                <Badge variant="secondary">{m.label}</Badge>
                <span className="text-sm text-muted-foreground">{m.when}</span>
            </p>
            <textarea
                aria-label={`Message to ${m.name}`}
                rows={3}
                className="rounded border px-3 py-2"
                value={text}
                onChange={(e) => setText(e.target.value)}
            />
            <span className="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => (r.recording ? r.stop() : void r.start())}
                >
                    {r.recording
                        ? 'Stop recording'
                        : r.blob
                          ? 'Record again'
                          : 'Record it in my voice'}
                </Button>
                {r.blob && <span className="text-sm">Recording ready</span>}
                {voice && !r.blob && (
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="size-4"
                            checked={ai}
                            onChange={(e) => setAi(e.target.checked)}
                        />
                        Speak it with my AI voice (labelled)
                    </label>
                )}
                <Button
                    onClick={() =>
                        router.post(
                            '/engage/recognitions',
                            {
                                agent: m.user_id,
                                occasion: m.occasion,
                                text,
                                ai_voice: ai,
                                recording: r.blob
                                    ? new File([r.blob], 'message.webm', {
                                          type: r.blob.type,
                                      })
                                    : null,
                            },
                            { forceFormData: true, preserveScroll: true },
                        )
                    }
                >
                    Send
                </Button>
            </span>
        </div>
    );
}

/** Agent activity (EN1): engagement counts, retention signals, milestones to recognise, and the broker's AI voice. */
export default function EngageActivity({
    agents,
    milestones,
    voice,
    consent,
    full_name,
}: {
    agents: Agent[];
    milestones: Milestone[];
    occasions: Record<string, string>;
    voice: boolean;
    consent: string;
    full_name: string;
}) {
    const r = useRecorder();
    const [name, setName] = useState('');
    const [agree, setAgree] = useState(false);

    return (
        <>
            <Head title="Agent activity" />
            <div className="mx-auto grid w-full max-w-6xl gap-6 p-4 text-base md:p-6">
                <PageHeader
                    title="Agent activity"
                    description="Who is busy, who has gone quiet, and who deserves a word from you. Counts only: you never see anyone's messages or practice here."
                />
                <section className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead>
                            <tr className="border-b">
                                <th className="p-2">Agent</th>
                                <th className="p-2">Last used</th>
                                <th className="p-2">
                                    Calls, texts, emails (7 days)
                                </th>
                                <th className="p-2">Tasks done (7 days)</th>
                                <th className="p-2">Deals opened (30 days)</th>
                                <th className="p-2">Courses (90 days)</th>
                                <th className="p-2">Practice (30 days)</th>
                                <th className="p-2">Check in?</th>
                            </tr>
                        </thead>
                        <tbody>
                            {agents.map((a) => (
                                <tr key={a.id} className="border-b align-top">
                                    <td className="p-2 font-medium">
                                        {a.name}
                                    </td>
                                    <td className="p-2">
                                        {a.last_active ?? 'Never'}
                                    </td>
                                    <td className="p-2">{a.outreach_7d}</td>
                                    <td className="p-2">{a.tasks_7d}</td>
                                    <td className="p-2">{a.deals_30d}</td>
                                    <td className="p-2">{a.courses_90d}</td>
                                    <td className="p-2">{a.practice_30d}</td>
                                    <td className="p-2">
                                        {a.risk >= 30 ? (
                                            <span title={a.reasons.join(' ')}>
                                                <Badge variant="destructive">
                                                    Yes
                                                </Badge>
                                                <span className="block text-xs text-muted-foreground">
                                                    {a.reasons[0]}
                                                </span>
                                            </span>
                                        ) : (
                                            '—'
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>
                <section className="grid gap-2">
                    <h2 className="text-lg font-semibold">
                        Milestones to recognise
                    </h2>
                    {milestones.length === 0 && (
                        <p className="text-muted-foreground">
                            Nothing in the last two weeks.
                        </p>
                    )}
                    {milestones.map((m) => (
                        <Send
                            key={`${m.user_id}-${m.occasion}`}
                            m={m}
                            voice={voice}
                        />
                    ))}
                </section>
                <section className="grid gap-2 rounded-lg border p-4">
                    <h2 className="text-lg font-semibold">
                        Your AI voice for recognition messages
                    </h2>
                    {voice ? (
                        <>
                            <p>
                                Your AI voice is ready. Messages spoken with it
                                are always labelled for the agent.
                            </p>
                            <Button
                                variant="outline"
                                className="justify-self-start"
                                onClick={() =>
                                    router.delete('/engage/voice', {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                Delete my AI voice
                            </Button>
                        </>
                    ) : (
                        <>
                            <p className="text-sm text-muted-foreground">
                                Optional. Read the statement below aloud while
                                recording (about a minute). Your voice is used
                                only for recognition messages you write to your
                                own agents.
                            </p>
                            <blockquote className="rounded bg-muted/40 p-3 text-sm">
                                {consent}
                            </blockquote>
                            <label className="grid gap-1 text-sm">
                                Type your full name to sign ({full_name})
                                <input
                                    className="rounded border px-3 py-2"
                                    value={name}
                                    onChange={(e) => setName(e.target.value)}
                                />
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    className="size-5"
                                    checked={agree}
                                    onChange={(e) => setAgree(e.target.checked)}
                                />
                                I agree to the statement above
                            </label>
                            <span className="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        r.recording ? r.stop() : void r.start()
                                    }
                                >
                                    {r.recording
                                        ? 'Stop recording'
                                        : r.blob
                                          ? 'Record again'
                                          : 'Record the statement'}
                                </Button>
                                <Button
                                    disabled={!r.blob || !agree || !name}
                                    onClick={() =>
                                        router.post(
                                            '/engage/voice',
                                            {
                                                name,
                                                agree,
                                                recording: r.blob
                                                    ? new File(
                                                          [r.blob],
                                                          'consent.webm',
                                                          { type: r.blob.type },
                                                      )
                                                    : null,
                                            },
                                            {
                                                forceFormData: true,
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                >
                                    Make my AI voice
                                </Button>
                            </span>
                        </>
                    )}
                </section>
            </div>
        </>
    );
}
