import { Head, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Props = {
    automation: { ulid: string; name: string };
    runs: {
        ulid: string;
        subject: string;
        status: string;
        error: string | null;
        started_at: string;
        version: number;
        resume_at: string | null;
        steps: { type: string; status: string; note: string }[];
    }[];
};

const label: Record<string, string> = {
    running: 'Running',
    waiting: 'Waiting',
    approval: 'Waiting for approval',
    done: 'Done',
    failed: 'Failed',
    cancelled: 'Stopped',
};

/** What an automation did, for whom and why (specs/core/06, W1). */
export default function Runs({ automation, runs }: Props) {
    const opts = { preserveScroll: true };

    return (
        <>
            <Head title={`Runs: ${automation.name}`} />
            <div className="flex flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title={automation.name}
                    description="Every run, with each step and what it did."
                />
                {runs.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        It hasn't run yet.
                    </p>
                )}
                <ul className="grid gap-3">
                    {runs.map((r) => (
                        <li
                            key={r.ulid}
                            className="grid gap-2 rounded-lg border p-4 text-sm"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-medium">{r.subject}</span>
                                <Badge
                                    variant={
                                        r.status === 'failed'
                                            ? 'destructive'
                                            : 'secondary'
                                    }
                                >
                                    {label[r.status] ?? r.status}
                                </Badge>
                                <span className="text-muted-foreground">
                                    {new Date(r.started_at).toLocaleString()} ·
                                    version {r.version}
                                    {r.resume_at &&
                                        ` · carries on ${new Date(r.resume_at).toLocaleString()}`}
                                </span>
                                <span className="ml-auto flex gap-2">
                                    {r.status === 'failed' && (
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    `/automations/runs/${r.ulid}/retry`,
                                                    {},
                                                    opts,
                                                )
                                            }
                                        >
                                            Retry
                                        </Button>
                                    )}
                                    {[
                                        'running',
                                        'waiting',
                                        'approval',
                                        'failed',
                                    ].includes(r.status) && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    `/automations/runs/${r.ulid}/cancel`,
                                                    {},
                                                    opts,
                                                )
                                            }
                                        >
                                            Stop
                                        </Button>
                                    )}
                                </span>
                            </div>
                            {r.error && (
                                <p className="text-destructive">{r.error}</p>
                            )}
                            <ol className="list-decimal pl-5 text-muted-foreground">
                                {r.steps.map((s, i) => (
                                    <li key={i}>
                                        {s.note}{' '}
                                        {s.status !== 'done' && `(${s.status})`}
                                    </li>
                                ))}
                            </ol>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

Runs.layout = {
    breadcrumbs: [{ title: 'Automations', href: '/automations' }],
};
