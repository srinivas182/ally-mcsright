import { router } from '@inertiajs/react';
import { PhoneCallIcon } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

export type CallRow = {
    ulid: string;
    kind: 'recorded' | 'ai_call';
    when: string;
    status: string;
    seconds: number | null;
    summary: string | null;
    transcript: string | null;
};

export type Calls = { blocker: string | null; list: CallRow[] };

const STATUS: Record<string, string> = {
    calling: 'Calling',
    recorded: 'Writing notes',
    writing: 'Writing notes',
    written: 'Notes ready',
    no_recording: 'Not recorded',
    failed: 'Could not write down',
    deleted: 'Deleted',
    talked: 'Talked',
    transferred: 'Passed to agent',
    no_answer: 'No answer',
};

/**
 * VO1 (specs/grow/14): one button for a recorded call with automatic notes, and the notes and transcripts of
 * recorded calls and AI assistant calls with this contact.
 */
export function CallNotesPanel({
    base,
    calls,
}: {
    base: string;
    calls: Calls;
}) {
    return (
        <section className="grid gap-3 rounded-lg border p-4">
            <p className="text-sm font-medium">Calls with notes</p>
            {calls.blocker === null ? (
                <div className="grid gap-1">
                    <Button
                        className="justify-self-start"
                        onClick={() =>
                            router.post(
                                `${base}/recorded-call`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <PhoneCallIcon aria-hidden="true" />
                        Call and take notes for me
                    </Button>
                    <p className="text-sm text-muted-foreground">
                        We ring your phone first, then them. They hear that the
                        call is recorded for notes. Afterwards the notes and a
                        follow-up task appear here, and the recording is
                        deleted.
                    </p>
                </div>
            ) : (
                <p className="text-sm text-muted-foreground">{calls.blocker}</p>
            )}
            {calls.list.length > 0 && (
                <ul className="grid gap-2">
                    {calls.list.map((c) => (
                        <li
                            key={c.ulid}
                            className="grid gap-1 rounded-md bg-muted/40 p-3"
                        >
                            <div className="flex flex-wrap items-center gap-2 text-sm">
                                <span className="font-medium">
                                    {c.kind === 'ai_call'
                                        ? 'AI assistant call'
                                        : 'Recorded call'}
                                </span>
                                <span className="text-muted-foreground">
                                    {new Date(c.when).toLocaleString()}
                                    {c.seconds !== null &&
                                        ` · ${Math.max(1, Math.round(c.seconds / 60))} min`}
                                </span>
                                <Badge variant="outline">
                                    {STATUS[c.status] ?? c.status}
                                </Badge>
                            </div>
                            {c.summary && <p>{c.summary}</p>}
                            {c.transcript && (
                                <details>
                                    <summary className="cursor-pointer text-sm text-muted-foreground">
                                        Read the whole call
                                    </summary>
                                    <p className="mt-1 text-sm whitespace-pre-line">
                                        {c.transcript}
                                    </p>
                                </details>
                            )}
                            {c.kind === 'recorded' && c.status === 'failed' && (
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                `${base}/recorded-call/${c.ulid}/retry`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Try again
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                `${base}/recorded-call/${c.ulid}/delete`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Delete the recording
                                    </Button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
