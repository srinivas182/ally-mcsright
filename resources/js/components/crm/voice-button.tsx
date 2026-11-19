import { MicIcon, SquareIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { postForm } from '@/lib/http';

/**
 * Tap to speak, tap to stop. The recording is turned into text (shown for editing before any
 * action) and never stored. Hidden on browsers that cannot record.
 */
export function VoiceButton({
    onText,
    label = 'Speak',
    listen = 0,
}: {
    onText: (text: string) => void;
    label?: string;
    /** SX4: change this number to start recording (the microphone button on every page). */
    listen?: number;
}) {
    const [state, setState] = useState<'idle' | 'recording' | 'working'>(
        'idle',
    );
    const [error, setError] = useState<string | null>(null);
    const [supported, setSupported] = useState(false);
    const recorder = useRef<MediaRecorder | null>(null);
    const startRef = useRef<(() => Promise<void>) | null>(null);
    const chunks = useRef<Blob[]>([]);

    useEffect(() => {
        setSupported(
            typeof window !== 'undefined' &&
                'MediaRecorder' in window &&
                !!navigator.mediaDevices?.getUserMedia,
        );
    }, []);

    useEffect(() => {
        if (
            listen > 0 &&
            supported &&
            recorder.current?.state !== 'recording'
        ) {
            void startRef.current?.();
        }
    }, [listen, supported]);

    if (!supported) {
        return null;
    }

    const start = async () => {
        setError(null);
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: true,
            });
            const rec = new MediaRecorder(stream);
            chunks.current = [];
            rec.ondataavailable = (e) => chunks.current.push(e.data);
            rec.onstop = async () => {
                stream.getTracks().forEach((t) => t.stop());
                setState('working');
                const blob = new Blob(chunks.current, {
                    type: rec.mimeType || 'audio/webm',
                });
                const form = new FormData();
                form.append('audio', blob, 'speech.webm');
                try {
                    const { text } = await postForm<{ text: string }>(
                        '/voice/transcribe',
                        form,
                    );
                    onText(text);
                } catch (e) {
                    setError((e as Error).message);
                } finally {
                    setState('idle');
                }
            };
            recorder.current = rec;
            rec.start();
            setState('recording');
            // SX4: stop by itself after a minute (the most that is written down at once).
            window.setTimeout(() => {
                if (rec.state === 'recording') {
                    rec.stop();
                }
            }, 60_000);
        } catch {
            setError('Allow microphone access to speak.');
        }
    };

    startRef.current = start;

    return (
        <span className="inline-flex flex-col gap-1">
            <Button
                type="button"
                variant={state === 'recording' ? 'destructive' : 'outline'}
                size="sm"
                disabled={state === 'working'}
                aria-label={state === 'recording' ? 'Stop recording' : label}
                onClick={() =>
                    state === 'recording'
                        ? recorder.current?.stop()
                        : void start()
                }
            >
                {state === 'recording' ? (
                    <SquareIcon aria-hidden="true" />
                ) : (
                    <MicIcon aria-hidden="true" />
                )}
                {state === 'recording'
                    ? 'Stop'
                    : state === 'working'
                      ? 'Listening…'
                      : label}
            </Button>
            {error && (
                <span role="alert" className="text-xs text-destructive">
                    {error}
                </span>
            )}
        </span>
    );
}
