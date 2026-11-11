import { router } from '@inertiajs/react';
import { useState } from 'react';

import { VoiceButton } from '@/components/crm/voice-button';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { postJson } from '@/lib/http';

type Proposal = {
    note: string;
    call_outcome: string | null;
    lifecycle: string | null;
    tags: string[];
    task_title: string;
    task_due: string | null;
};

const outcomes: Record<string, string> = {
    connected: 'Connected',
    left_voicemail: 'Left voicemail',
    no_answer: 'No answer',
    wrong_number: 'Wrong number',
};

/** Say or type what happened; review the proposed update; Confirm. Nothing changes before Confirm. */
export function QuickUpdate({ contactUlid }: { contactUlid: string }) {
    const [text, setText] = useState('');
    const [proposal, setProposal] = useState<Proposal | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const preview = async (value = text) => {
        if (value.trim() === '') return;
        setBusy(true);
        setError(null);
        try {
            const { proposal: p } = await postJson<{ proposal: Proposal }>(
                `/contacts/${contactUlid}/voice-log/parse`,
                { text: value },
            );
            setProposal(p);
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    };

    return (
        <section
            aria-label="Quick update"
            className="grid gap-2 rounded-lg border p-3"
        >
            <div className="flex items-center justify-between gap-2">
                <p className="text-sm font-medium">Quick update</p>
                <VoiceButton
                    label="Speak update"
                    onText={(t) => {
                        setText(t);
                        void preview(t);
                    }}
                />
            </div>
            <Textarea
                aria-label="What happened?"
                placeholder="e.g. Talked to Pat, pre-approved for 340, wants to see homes Saturday"
                value={text}
                onChange={(e) => setText(e.target.value)}
            />
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
            {!proposal && (
                <Button
                    size="sm"
                    className="justify-self-end"
                    disabled={busy || text.trim() === ''}
                    onClick={() => void preview()}
                >
                    {busy ? 'Reading…' : 'Preview'}
                </Button>
            )}
            {proposal && (
                <div className="grid gap-2 rounded-md border bg-muted/30 p-3 text-sm">
                    <label className="grid gap-1">
                        Note
                        <Textarea
                            value={proposal.note}
                            onChange={(e) =>
                                setProposal({
                                    ...proposal,
                                    note: e.target.value,
                                })
                            }
                        />
                    </label>
                    {proposal.call_outcome && (
                        <p>Call: {outcomes[proposal.call_outcome]}</p>
                    )}
                    {proposal.lifecycle && (
                        <p>Stage: {proposal.lifecycle.replace('_', ' ')}</p>
                    )}
                    {proposal.tags.length > 0 && (
                        <p>Tags: {proposal.tags.join(', ')}</p>
                    )}
                    <label className="grid gap-1">
                        Follow-up task (optional)
                        <Input
                            value={proposal.task_title}
                            onChange={(e) =>
                                setProposal({
                                    ...proposal,
                                    task_title: e.target.value,
                                })
                            }
                        />
                    </label>
                    <div className="flex justify-end gap-2">
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setProposal(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            onClick={() =>
                                router.post(
                                    `/contacts/${contactUlid}/voice-log`,
                                    proposal,
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setProposal(null);
                                            setText('');
                                        },
                                    },
                                )
                            }
                        >
                            Confirm
                        </Button>
                    </div>
                </div>
            )}
        </section>
    );
}
