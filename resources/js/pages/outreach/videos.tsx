import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Video = {
    ulid: string;
    title: string;
    message: string | null;
    contact: string | null;
    url: string | null;
    views: number;
    first_viewed_at: string | null;
    last_viewed_at: string | null;
    created_at: string;
};
type Props = {
    videos: Video[];
    website: boolean;
    contact: { ulid: string; name: string } | null;
};

const MAX_SECONDS = 180;

/** MK1: record a short video in the browser (or upload one), share a private link, see when it is watched. */
export default function Videos({ videos, website, contact }: Props) {
    const form = useForm<{
        video: File | null;
        title: string;
        message: string;
        contact: string;
    }>({
        video: null,
        title: contact ? `A quick video for ${contact.name}` : '',
        message: '',
        contact: contact?.ulid ?? '',
    });
    const preview = useRef<HTMLVideoElement>(null);
    const recorder = useRef<MediaRecorder | null>(null);
    const [recording, setRecording] = useState(false);
    const [seconds, setSeconds] = useState(0);
    const [problem, setProblem] = useState<string | null>(null);
    const [copied, setCopied] = useState<string | null>(null);

    useEffect(() => {
        if (!recording) return;
        const id = window.setInterval(
            () =>
                setSeconds((s) => {
                    if (s + 1 >= MAX_SECONDS) recorder.current?.stop();
                    return s + 1;
                }),
            1000,
        );
        return () => window.clearInterval(id);
    }, [recording]);

    const start = async () => {
        setProblem(null);
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: true,
                audio: true,
            });
            if (preview.current) {
                preview.current.srcObject = stream;
                preview.current.muted = true;
                void preview.current.play();
            }
            const chunks: Blob[] = [];
            const rec = new MediaRecorder(stream, {
                mimeType: MediaRecorder.isTypeSupported('video/webm')
                    ? 'video/webm'
                    : 'video/mp4',
            });
            rec.ondataavailable = (e) => chunks.push(e.data);
            rec.onstop = () => {
                stream.getTracks().forEach((t) => t.stop());
                const type = rec.mimeType.startsWith('video/mp4')
                    ? 'video/mp4'
                    : 'video/webm';
                const file = new File(
                    chunks,
                    `video.${type === 'video/mp4' ? 'mp4' : 'webm'}`,
                    { type },
                );
                form.setData('video', file);
                if (preview.current) {
                    preview.current.srcObject = null;
                    preview.current.src = URL.createObjectURL(file);
                    preview.current.muted = false;
                }
                setRecording(false);
            };
            recorder.current = rec;
            setSeconds(0);
            rec.start();
            setRecording(true);
        } catch {
            setProblem(
                'We could not use your camera. Allow camera and microphone in your browser, or upload a video instead.',
            );
        }
    };

    return (
        <>
            <Head title="Video messages" />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Video messages"
                    description="A short video says more than an email. Record one here, send the link, and see when it is watched."
                />
                {!website && (
                    <p className="rounded-lg border p-3 text-muted-foreground">
                        Links work once your brokerage website is live.
                    </p>
                )}
                <form
                    className="grid gap-3 rounded-lg border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/outreach/videos', {
                            forceFormData: true,
                            preserveScroll: true,
                            onSuccess: () => form.reset('video', 'message'),
                        });
                    }}
                >
                    <h2 className="font-semibold">
                        New video{contact ? ` for ${contact.name}` : ''}
                    </h2>
                    {/* oxlint-disable-next-line jsx-a11y/media-has-caption -- the agent's own camera preview while recording */}
                    <video
                        ref={preview}
                        className="aspect-video w-full max-w-xl rounded-md bg-black"
                        controls={!recording && form.data.video !== null}
                        playsInline
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        {recording ? (
                            <Button
                                type="button"
                                variant="destructive"
                                onClick={() => recorder.current?.stop()}
                            >
                                Stop ({Math.floor(seconds / 60)}:
                                {String(seconds % 60).padStart(2, '0')})
                            </Button>
                        ) : (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => void start()}
                            >
                                {form.data.video ? 'Record again' : 'Record'}
                            </Button>
                        )}
                        <label className="text-sm">
                            or upload a video{' '}
                            <input
                                type="file"
                                accept="video/mp4,video/webm,video/quicktime"
                                onChange={(e) =>
                                    form.setData(
                                        'video',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                        </label>
                    </div>
                    {problem && <p className="text-destructive">{problem}</p>}
                    <label className="grid gap-1">
                        Title
                        <Input
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                            placeholder="Your new home search"
                        />
                    </label>
                    <label className="grid gap-1">
                        A line under the video (optional)
                        <Input
                            value={form.data.message}
                            onChange={(e) =>
                                form.setData('message', e.target.value)
                            }
                        />
                    </label>
                    {(form.errors.video || form.errors.title) && (
                        <p className="text-destructive">
                            {form.errors.video ?? form.errors.title}
                        </p>
                    )}
                    <div>
                        <Button
                            disabled={
                                form.processing ||
                                form.data.video === null ||
                                recording
                            }
                        >
                            Save video
                        </Button>
                    </div>
                </form>

                <section className="grid gap-2">
                    <h2 className="font-semibold">Your videos</h2>
                    {videos.length === 0 && (
                        <p className="text-muted-foreground">None yet.</p>
                    )}
                    <ul className="grid gap-2">
                        {videos.map((v) => (
                            <li
                                key={v.ulid}
                                className="flex flex-wrap items-center gap-2 rounded-lg border p-3"
                            >
                                <span className="font-medium">{v.title}</span>
                                {v.contact && (
                                    <span className="text-muted-foreground">
                                        for {v.contact}
                                    </span>
                                )}
                                <Badge
                                    variant={
                                        v.views > 0 ? 'default' : 'outline'
                                    }
                                >
                                    {v.views > 0
                                        ? `Watched ${v.views} time${v.views === 1 ? '' : 's'}`
                                        : 'Not watched yet'}
                                </Badge>
                                <span className="ml-auto flex gap-2">
                                    {v.url && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => {
                                                void navigator.clipboard
                                                    ?.writeText(v.url ?? '')
                                                    .then(() =>
                                                        setCopied(v.ulid),
                                                    );
                                            }}
                                        >
                                            {copied === v.ulid
                                                ? 'Copied'
                                                : 'Copy link'}
                                        </Button>
                                    )}
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            router.delete(
                                                `/outreach/videos/${v.ulid}`,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Remove
                                    </Button>
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}
