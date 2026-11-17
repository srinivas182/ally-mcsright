import { Head } from '@inertiajs/react';

/** A recognition message from the broker (EN1). AI-voiced audio is always labelled. */
export default function RecognitionPage({
    recognition,
}: {
    recognition: {
        ulid: string;
        from: string;
        occasion: string;
        text: string | null;
        audio: string;
        at: string;
    };
}) {
    return (
        <>
            <Head title={recognition.occasion} />
            <div className="mx-auto grid w-full max-w-2xl gap-4 p-4 text-lg leading-relaxed md:p-6">
                <h1 className="text-2xl font-semibold">
                    {recognition.occasion}: a message from {recognition.from}
                </h1>
                {recognition.text && <p>“{recognition.text}”</p>}
                {recognition.audio !== 'none' && (
                    <audio
                        controls
                        src={`/engage/recognitions/${recognition.ulid}/audio`}
                        className="w-full"
                    >
                        <track kind="captions" />
                    </audio>
                )}
                {recognition.audio === 'ai_voice' && (
                    <p className="text-sm text-muted-foreground">
                        Spoken by an AI copy of {recognition.from}'s voice, made
                        with their consent. The words are theirs.
                    </p>
                )}
                <p className="text-sm text-muted-foreground">
                    {recognition.at}
                </p>
            </div>
        </>
    );
}
