import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Agent = {
    user_id: number;
    ulid: string;
    name: string;
    score: number;
    reasons: string[];
    suggestion: string;
    last_checkin: string | null;
};

function Row({ a }: { a: Agent }) {
    const [note, setNote] = useState('');

    return (
        <li className="grid gap-2 rounded-lg border p-4">
            <div className="flex flex-wrap items-center gap-2">
                <strong className="text-lg">{a.name}</strong>
                <span
                    className={`rounded px-2 py-0.5 text-sm ${a.score >= 50 ? 'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200' : 'bg-muted'}`}
                >
                    {a.score} out of 100
                </span>
                {a.last_checkin && (
                    <span className="text-sm text-muted-foreground">
                        last check-in {a.last_checkin}
                    </span>
                )}
            </div>
            <ul className="list-disc pl-5 text-sm">
                {a.reasons.map((r) => (
                    <li key={r}>{r}</li>
                ))}
            </ul>
            <p className="text-sm text-muted-foreground">{a.suggestion}</p>
            <form
                className="flex flex-wrap items-end gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    router.post(
                        `/recruiting/retention/${a.ulid}`,
                        { note },
                        { preserveScroll: true },
                    );
                }}
            >
                <label className="grid gap-1 text-sm">
                    How it went (optional, only you see it)
                    <Input
                        className="w-72"
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                    />
                </label>
                <Button>I checked in</Button>
            </form>
        </li>
    );
}

/** Keeping agents (RC3): the brokerage's own agents who may be thinking of leaving, with reasons and a suggested check-in. */
export default function Retention({ agents }: { agents: Agent[] }) {
    return (
        <>
            <Head title="Keeping agents" />
            <div className="grid max-w-4xl gap-5 p-4 text-base">
                <PageHeader
                    title="Keeping agents"
                    description="Agents worth a friendly call, with the reasons. Checking in takes them off the list for 60 days. Agents never see this page."
                />
                {agents.length === 0 ? (
                    <EmptyState
                        title="Nobody needs a check-in right now"
                        description="We'll show agents here when their numbers or activity suggest a call would help."
                    />
                ) : (
                    <ul className="grid gap-3">
                        {agents.map((a) => (
                            <Row key={a.ulid} a={a} />
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
