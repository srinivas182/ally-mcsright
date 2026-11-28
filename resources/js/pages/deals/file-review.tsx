import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Finding = {
    id: number;
    code: string;
    severity: string;
    message: string;
    dismiss_reason: string | null;
    check: string;
    accuracy: number | null;
    evidence: { document: string; page: number; quote: string } | null;
};
type Props = {
    deal: { ulid: string; street: string; status: string; agent: string };
    score: number | null;
    open: Finding[];
    dismissed: Finding[];
    brief: { summary: string; note: string; ai: boolean } | null;
    file: {
        submitted_at: string | null;
        approved_at: string | null;
        returned_at: string | null;
        return_note: string | null;
    };
};

function Row({ f }: { f: Finding }) {
    return (
        <li className="grid gap-1 p-2">
            <span className="flex flex-wrap items-center gap-2">
                <Badge
                    variant={
                        f.severity === 'blocker'
                            ? 'destructive'
                            : f.severity === 'warning'
                              ? 'default'
                              : 'secondary'
                    }
                >
                    {f.severity}
                </Badge>
                {f.message}
            </span>
            <span className="text-xs text-muted-foreground">
                {f.check}
                {f.accuracy !== null &&
                    ` · this check has been right ${f.accuracy}% of the time`}
                {f.dismiss_reason && ` · dismissed: “${f.dismiss_reason}”`}
                {f.evidence && (
                    <>
                        {' · '}
                        <a
                            className="underline"
                            href={`/deals/documents/${f.evidence.document}/evidence?page=${f.evidence.page}`}
                        >
                            page {f.evidence.page}
                        </a>
                    </>
                )}
            </span>
        </li>
    );
}

/** The broker's review copilot for one file (specs/ai/02, DQ6). The broker decides; Deal-IQ never approves. */
export default function FileReview({
    deal,
    score,
    open,
    dismissed,
    brief,
    file,
}: Props) {
    const back = useForm({ note: brief?.note ?? '' });
    // A new brief arrives as new props on the same page: put its suggested note in the box (unless one is typed).
    useEffect(() => {
        if (brief?.note && back.data.note.trim() === '') {
            back.setData('note', brief.note);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [brief?.note]);
    const base = `/deals/${deal.ulid}/file-review`;

    return (
        <>
            <Head title={`Review: ${deal.street}`} />
            <div className="flex max-w-4xl flex-col gap-4 p-4 text-sm md:p-6">
                <PageHeader
                    title={`Review: ${deal.street}`}
                    description={`${deal.agent} · ${deal.status.replace('_', ' ')} · broker-ready ${score ?? 0}%`}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={`/deals/${deal.ulid}`}>
                                Open the deal
                            </Link>
                        </Button>
                    }
                />
                <p className="text-muted-foreground">
                    {file.approved_at
                        ? `Approved ${file.approved_at}.`
                        : file.submitted_at
                          ? `Sent for review ${file.submitted_at}.`
                          : 'Not sent for review yet.'}
                    {file.returned_at &&
                        !file.submitted_at &&
                        ` Sent back ${file.returned_at}: “${file.return_note}”`}
                </p>
                <section className="grid gap-2 rounded-lg border p-4">
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="font-medium">Review brief</p>
                        {brief?.ai && (
                            <Badge variant="outline">
                                Written by Deal-IQ from the file: check it
                            </Badge>
                        )}
                        <Button
                            size="sm"
                            variant="outline"
                            className="ml-auto"
                            onClick={() =>
                                router.post(
                                    `${base}/brief`,
                                    {},
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => router.reload(),
                                    },
                                )
                            }
                        >
                            {brief ? 'Write it again' : 'Write the brief'}
                        </Button>
                    </div>
                    {brief ? (
                        <p>{brief.summary}</p>
                    ) : (
                        <p className="text-muted-foreground">
                            One paragraph on what is in the file, what was
                            waived or dismissed and why, and what deserves a
                            second look.
                        </p>
                    )}
                </section>
                <section className="grid gap-2">
                    <p className="font-medium">
                        Findings ({open.length} open, {dismissed.length}{' '}
                        dismissed by the agent)
                    </p>
                    {open.length + dismissed.length === 0 ? (
                        <p className="text-muted-foreground">Nothing found.</p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {[...open, ...dismissed].map((f) => (
                                <Row key={f.id} f={f} />
                            ))}
                        </ul>
                    )}
                </section>
                {file.submitted_at && !file.approved_at && (
                    <section className="grid gap-2 rounded-lg border p-4">
                        <Button
                            className="justify-self-start"
                            onClick={() =>
                                router.post(
                                    `${base}/approve`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Approve the file
                        </Button>
                        <form
                            className="grid gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                back.post(`${base}/send-back`, {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <label className="grid gap-1">
                                <span className="text-muted-foreground">
                                    Or send it back with a note
                                    {brief
                                        ? ' (suggested by Deal-IQ; edit it)'
                                        : ''}
                                </span>
                                <textarea
                                    className="min-h-20 rounded border bg-background p-2"
                                    value={back.data.note}
                                    onChange={(e) =>
                                        back.setData('note', e.target.value)
                                    }
                                />
                            </label>
                            {back.errors.note && (
                                <span className="text-destructive">
                                    {back.errors.note}
                                </span>
                            )}
                            <Button
                                type="submit"
                                variant="outline"
                                className="justify-self-start"
                                disabled={back.processing}
                            >
                                Send it back
                            </Button>
                        </form>
                    </section>
                )}
            </div>
        </>
    );
}

FileReview.layout = { breadcrumbs: [{ title: 'Deals', href: '/deals' }] };
