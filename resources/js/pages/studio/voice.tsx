import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

/**
 * Studio → My voice (specs/grow/13, SA5b): the agent signs the consent with their name and reads it aloud with a
 * short sample; their own AI voice then narrates only the videos they approve. Deleting it deletes it everywhere.
 */
export default function StudioVoice({
    ready,
    on,
    name,
    consent,
    sample,
    own,
    usage,
}: {
    ready: boolean;
    on: boolean;
    name: string;
    consent: string;
    sample: string;
    own: { since: string; last_used: string | null } | null;
    usage: { used: number; extra: number; allowance: number };
}) {
    const form = useForm<{ name: string; agree: boolean; recordings: File[] }>({
        name: '',
        agree: false,
        recordings: [],
    });
    const recorder = useRef<MediaRecorder | null>(null);
    const chunks = useRef<Blob[]>([]);
    const [recording, setRecording] = useState(false);
    const [clip, setClip] = useState<string | null>(null);
    const [micError, setMicError] = useState<string | null>(null);

    useEffect(
        () => () =>
            recorder.current?.stream.getTracks().forEach((t) => t.stop()),
        [],
    );

    const start = async () => {
        setMicError(null);
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: true,
            });
            const r = new MediaRecorder(stream);
            chunks.current = [];
            r.ondataavailable = (e) => chunks.current.push(e.data);
            r.onstop = () => {
                stream.getTracks().forEach((t) => t.stop());
                const blob = new Blob(chunks.current, {
                    type: r.mimeType || 'audio/webm',
                });
                const ext = (r.mimeType || 'audio/webm').includes('mp4')
                    ? 'm4a'
                    : 'webm';
                form.setData('recordings', [
                    new File([blob], `voice.${ext}`, { type: blob.type }),
                ]);
                setClip(URL.createObjectURL(blob));
            };
            recorder.current = r;
            r.start();
            setRecording(true);
        } catch {
            setMicError('Allow the microphone for this page, then try again.');
        }
    };
    const stop = () => {
        recorder.current?.stop();
        setRecording(false);
    };

    if (!ready || !on) {
        return (
            <div className="p-4">
                <Head title="My voice" />
                <EmptyState
                    title={
                        on
                            ? 'Voice-overs come with Ally Studio'
                            : "AI voice-overs aren't switched on yet"
                    }
                    description={
                        on
                            ? 'Choose a Studio package to narrate your Reels and tours.'
                            : 'Please ask RightAlly support.'
                    }
                />
                {on && (
                    <div className="mt-4">
                        <Button asChild>
                            <Link href="/studio">See the packages</Link>
                        </Button>
                    </div>
                )}
            </div>
        );
    }

    return (
        <>
            <Head title="My voice" />
            <div className="grid max-w-2xl gap-6 p-4 text-base">
                <PageHeader
                    title="My own voice"
                    description="Narrate your Reels and tours in your own voice. Only you can make it, it's used only for videos you approve, and you can delete it at any time."
                />
                <p className="text-sm text-muted-foreground">
                    {usage.used} of {usage.allowance} voice-overs used this
                    month. Professional AI voices are always there too.
                </p>
                {own ? (
                    <section className="grid gap-3 rounded-xl border p-4">
                        <h2 className="font-medium">Your voice is ready</h2>
                        <p className="text-sm">
                            Made on {own.since}
                            {own.last_used
                                ? `, last used on ${own.last_used}`
                                : ''}
                            . Choose "My own voice" when you add a voice-over in
                            Reels. It's deleted automatically if you leave the
                            brokerage or don't use it for 12 months.
                        </p>
                        <div>
                            <Button
                                variant="destructive"
                                onClick={() => router.delete('/studio/voice')}
                            >
                                Delete my voice
                            </Button>
                        </div>
                    </section>
                ) : (
                    <form
                        className="grid gap-4 rounded-xl border p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/studio/voice', { forceFormData: true });
                        }}
                    >
                        <h2 className="font-medium">1. Read this agreement</h2>
                        <blockquote className="rounded-lg bg-muted p-3 text-sm">
                            {consent}
                        </blockquote>
                        <h2 className="font-medium">
                            2. Record yourself reading it aloud, then this
                        </h2>
                        <blockquote className="rounded-lg bg-muted p-3 text-sm">
                            {sample}
                        </blockquote>
                        <p className="text-sm text-muted-foreground">
                            In a quiet room, about a minute in all. The
                            recording is used once to make your voice and is not
                            kept.
                        </p>
                        <div className="flex flex-wrap items-center gap-3">
                            {recording ? (
                                <Button
                                    type="button"
                                    variant="destructive"
                                    onClick={stop}
                                >
                                    Stop recording
                                </Button>
                            ) : (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={start}
                                >
                                    {clip ? 'Record again' : 'Start recording'}
                                </Button>
                            )}
                            {clip && !recording && (
                                <audio
                                    controls
                                    src={clip}
                                    aria-label="Your recording"
                                />
                            )}
                        </div>
                        {micError && (
                            <p className="text-destructive">{micError}</p>
                        )}
                        {form.errors.recordings && (
                            <p className="text-destructive">
                                {form.errors.recordings}
                            </p>
                        )}
                        <h2 className="font-medium">3. Sign</h2>
                        <label className="grid gap-1">
                            Type your full name ({name})
                            <Input
                                autoComplete="name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                            {form.errors.name && (
                                <span className="text-destructive">
                                    {form.errors.name}
                                </span>
                            )}
                        </label>
                        <label className="flex items-start gap-3">
                            <input
                                type="checkbox"
                                className="mt-1 size-5"
                                checked={form.data.agree}
                                onChange={(e) =>
                                    form.setData('agree', e.target.checked)
                                }
                            />
                            <span>
                                I agree to the statement above, and the voice in
                                the recording is mine.
                            </span>
                        </label>
                        {form.errors.agree && (
                            <span className="text-destructive">
                                {form.errors.agree}
                            </span>
                        )}
                        {(form.errors as Record<string, string>).voice && (
                            <p className="text-destructive">
                                {(form.errors as Record<string, string>).voice}
                            </p>
                        )}
                        <div>
                            <Button
                                disabled={
                                    form.processing ||
                                    recording ||
                                    form.data.recordings.length === 0 ||
                                    !form.data.agree
                                }
                            >
                                Make my voice
                            </Button>
                        </div>
                    </form>
                )}
            </div>
        </>
    );
}
