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
}: {
    onText: (text: string) => void;
    label?: string;
}) {
    const [state, setState] = useState<'idle' | 'recording' | 'working'>(
        'idle',
    );
    const [error, setError] = useState<string | null>(null);
    const [supported, setSupported] = useState(false);
    const recorder = useRef<MediaRecorder | null>(null);
    const chunks = useRef<Blob[]>([]);

    useEffect(() => {
        setSupported(
            typeof window !== 'undefined' &&
                'MediaRecorder' in window &&
                !!navigator.mediaDevices?.getUserMedia,
        );
    }, []);

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
        } catch {
            setError('Allow microphone access to speak.');
        }
    };

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
