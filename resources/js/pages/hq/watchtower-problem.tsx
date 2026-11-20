import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Analysis = {
    status: 'pending' | 'done' | 'failed';
    summary?: string;
    cause?: string;
    fix?: string;
    confidence?: string;
    error?: string;
};
type Props = {
    incident: {
        id: string;
        reference: string;
        kind: string;
        severity: string;
        status: string;
        title: string;
        location: string | null;
        occurrences: number;
        last_hour: number;
        brokerages: number;
        regressed: boolean;
        message: string | null;
        file: string | null;
        line: number | null;
        trace: string | null;
        first_release: string | null;
        last_release: string | null;
        fixed_in: string | null;
        first_seen_at: string;
        last_seen_at: string;
        analysis: Analysis | null;
    };
    days: { day: string; count: number }[];
    brokerages: { id: number; name: string }[];
    fixRequest: string;
    release: string;
    statuses: Record<string, string>;
    ai: boolean;
};

/** HQ › Watchtower › one problem (OB1): what broke, the AI's root cause, the fix request to paste, and what to do. */
export default function WatchtowerProblem({
    incident: i,
    days,
    brokerages,
    fixRequest,
    release,
    statuses,
    ai,
}: Props) {
    const [fixedIn, setFixedIn] = useState(release);
    const [copied, setCopied] = useState(false);
    const act = (action: string) =>
        router.post(
            `/hq/watchtower/${i.id}`,
            { action, fixed_in: fixedIn },
            { preserveScroll: true },
        );
    const a = i.analysis;

    return (
        <>
            <Head title={`${i.reference} · Watchtower`} />
            <div className="mx-auto grid w-full max-w-5xl gap-5 p-4 md:p-6">
                <Link href="/hq/watchtower" className="text-sm underline">
                    ← All problems
                </Link>
                <PageHeader
                    title={i.title}
                    description={`${i.reference} · ${statuses[i.status] ?? i.status} · ${i.occurrences} times (${i.last_hour} in the last hour) · first in ${i.first_release ?? '?'}, last in ${i.last_release ?? '?'}`}
                />
                <div className="flex flex-wrap gap-2">
                    <Badge
                        variant={
                            i.severity === 'critical'
                                ? 'destructive'
                                : 'secondary'
                        }
                    >
                        {i.severity}
                    </Badge>
                    {i.regressed && (
                        <Badge variant="destructive">
                            Came back after a fix in {i.fixed_in}
                        </Badge>
                    )}
                    {i.location && (
                        <Badge variant="outline">{i.location}</Badge>
                    )}
                </div>

                <section className="grid gap-2 rounded-lg border p-4">
                    <h2 className="text-lg font-medium">Root cause</h2>
                    {a?.status === 'done' && (
                        <>
                            <p>{a.summary}</p>
                            {a.cause && (
                                <p>
                                    <strong>Likely cause:</strong> {a.cause}
                                </p>
                            )}
                            {a.fix && (
                                <p className="whitespace-pre-wrap">
                                    <strong>Suggested fix:</strong> {a.fix}
                                </p>
                            )}
                            <p className="text-sm text-muted-foreground">
                                The AI is {a.confidence ?? 'not very'} sure.
                                Check before changing anything.
                            </p>
                        </>
                    )}
                    {a?.status === 'pending' && (
                        <p className="text-muted-foreground">
                            The AI is looking at it. Refresh in a minute.
                        </p>
                    )}
                    {a?.status === 'failed' && (
                        <p className="text-muted-foreground">{a.error}</p>
                    )}
                    {!a && (
                        <p className="text-muted-foreground">
                            Not explained yet.
                        </p>
                    )}
                    {ai && a?.status !== 'pending' && (
                        <Button
                            variant="outline"
                            className="justify-self-start"
                            onClick={() =>
                                router.post(
                                    `/hq/watchtower/${i.id}/explain`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {a ? 'Ask the AI again' : 'Ask the AI'}
                        </Button>
                    )}
                </section>

                <section className="grid gap-2">
                    <div className="flex items-center gap-2">
                        <h2 className="mr-auto text-lg font-medium">
                            Fix request
                        </h2>
                        <Button
                            variant="outline"
                            onClick={() => {
                                void navigator.clipboard
                                    .writeText(fixRequest)
                                    .then(() => setCopied(true));
                            }}
                        >
                            {copied ? 'Copied' : 'Copy to paste'}
                        </Button>
                    </div>
                    <pre className="max-h-96 overflow-auto rounded-md border bg-muted/40 p-3 text-xs whitespace-pre-wrap">
                        {fixRequest}
                    </pre>
                </section>

                <section className="grid gap-2 rounded-lg border p-4">
                    <h2 className="text-lg font-medium">What to do</h2>
                    <div className="flex flex-wrap items-end gap-2">
                        <Button
                            variant="outline"
                            onClick={() => act('acknowledge')}
                        >
                            Acknowledge (stop emails)
                        </Button>
                        <label className="grid gap-1 text-sm">
                            Fixed in release
                            <Input
                                value={fixedIn}
                                onChange={(e) => setFixedIn(e.target.value)}
                            />
                        </label>
                        <Button onClick={() => act('fixed')}>Mark fixed</Button>
                        <Button variant="outline" onClick={() => act('ignore')}>
                            Ignore
                        </Button>
                        {!['new', 'acknowledged'].includes(i.status) && (
                            <Button
                                variant="outline"
                                onClick={() => act('reopen')}
                            >
                                Reopen
                            </Button>
                        )}
                    </div>
                </section>

                <section className="grid gap-1 text-sm">
                    <h2 className="text-lg font-medium">Last 14 days</h2>
                    <p>
                        {days.length === 0
                            ? 'Nothing counted.'
                            : days
                                  .map((d) => `${d.day}: ${d.count}`)
                                  .join(' · ')}
                    </p>
                    {brokerages.length > 0 && (
                        <p>
                            Brokerages affected:{' '}
                            {brokerages.map((b) => b.name).join(', ')}
                            {i.brokerages > brokerages.length &&
                                ` and ${i.brokerages - brokerages.length} more`}
                        </p>
                    )}
                </section>
            </div>
        </>
    );
}
