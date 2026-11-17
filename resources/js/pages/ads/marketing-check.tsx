import { Head, router, useForm, type InertiaFormProps } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { confirmCredits } from '@/components/credit-consent';

type Finding = {
    rule: string;
    severity: 'must_fix' | 'should_fix';
    text: string | null;
    why: string;
    fix: string;
    replace: string | null;
};
type Result = {
    findings: Finding[];
    fixed: string;
    ready: boolean;
    listing: Record<string, string | number | null> | null;
    rewritten?: boolean;
    input: { body: string; kind: string; mls_number: string; title: string };
};
type Review = {
    ulid: string;
    kind: string;
    title: string;
    body: string;
    mls_number: string | null;
    findings: Finding[];
    status: string;
    note: string | null;
    agent: string;
    has_file: boolean;
    created_at: string;
};
type FormShape = {
    body: string;
    kind: string;
    mls_number: string;
    title: string;
    rewrite: boolean;
    file: File | null;
};
type Props = {
    kinds: Record<string, string>;
    result: Result | null;
    mine: Review[];
    queue: Review[];
    reviewer: boolean;
    approval_required: boolean;
};

const statusLabel: Record<string, string> = {
    pending: 'Waiting for approval',
    approved: 'Approved',
    changes: 'Needs changes',
};

/** Marks each flagged phrase in place, so the problem is seen where it is. */
function Marked({ text, findings }: { text: string; findings: Finding[] }) {
    const phrases = findings
        .map((f) => f.text)
        .filter((t): t is string => !!t && t.length > 1);
    if (phrases.length === 0)
        return <p className="whitespace-pre-wrap">{text}</p>;
    const pattern = new RegExp(
        `(${phrases.map((p) => p.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|')})`,
        'gi',
    );

    return (
        <p className="whitespace-pre-wrap">
            {text.split(pattern).map((part, i) =>
                phrases.some((p) => p.toLowerCase() === part.toLowerCase()) ? (
                    <mark
                        key={i}
                        className="rounded bg-red-100 px-0.5 text-red-900 underline decoration-red-500 decoration-wavy dark:bg-red-950 dark:text-red-100"
                    >
                        {part}
                    </mark>
                ) : (
                    <span key={i}>{part}</span>
                ),
            )}
        </p>
    );
}

function Findings({ findings }: { findings: Finding[] }) {
    if (findings.length === 0)
        return (
            <p className="rounded-md bg-green-50 p-3 text-green-900 dark:bg-green-950 dark:text-green-100">
                Nothing to fix. It reads well.
            </p>
        );

    return (
        <ul className="grid gap-2">
            {findings.map((f, i) => (
                <li key={i} className="grid gap-1 rounded-md border p-3">
                    <span className="flex flex-wrap items-center gap-2">
                        <Badge
                            variant={
                                f.severity === 'must_fix'
                                    ? 'destructive'
                                    : 'outline'
                            }
                        >
                            {f.severity === 'must_fix'
                                ? 'Must fix'
                                : 'Should fix'}
                        </Badge>
                        {f.text && <strong>&ldquo;{f.text}&rdquo;</strong>}
                    </span>
                    <span>{f.why}</span>
                    <span className="text-muted-foreground">{f.fix}</span>
                </li>
            ))}
        </ul>
    );
}

/** MK3: the marketing check. Paste a piece, see problems marked, fix in one click, send for approval. */
export default function MarketingCheck({
    kinds,
    result,
    mine,
    queue,
    reviewer,
    approval_required,
}: Props) {
    const form = useForm<FormShape>({
        body: result?.input.body ?? '',
        kind: result?.input.kind ?? 'flyer',
        mls_number: result?.input.mls_number ?? '',
        title: result?.input.title ?? '',
        rewrite: false,
        file: null,
    });
    const check = (rewrite: boolean) => {
        form.transform((d) => ({ ...d, rewrite, file: null }));
        form.post('/marketing-check', { preserveScroll: true });
    };

    return (
        <>
            <Head title="Marketing check" />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 md:p-6">
                <PageHeader
                    title="Marketing check"
                    description="Paste the words of an ad, flyer, post or email. We mark anything that could get you in trouble (Fair Housing, missing brokerage name, facts that don't match the MLS, promises, financing terms) and how to fix it."
                />
                <form
                    className="grid gap-3 rounded-lg border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        check(false);
                    }}
                >
                    <div className="grid gap-3 sm:grid-cols-3">
                        <label className="grid gap-1">
                            What is it?
                            <select
                                className="rounded-md border bg-background p-2"
                                value={form.data.kind}
                                onChange={(e) =>
                                    form.setData('kind', e.target.value)
                                }
                            >
                                {Object.entries(kinds).map(([k, l]) => (
                                    <option key={k} value={k}>
                                        {l}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1">
                            MLS number (to check the facts)
                            <Input
                                value={form.data.mls_number}
                                onChange={(e) =>
                                    form.setData('mls_number', e.target.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1">
                            Name (optional)
                            <Input
                                value={form.data.title}
                                onChange={(e) =>
                                    form.setData('title', e.target.value)
                                }
                                placeholder="Oak St open house flyer"
                            />
                        </label>
                    </div>
                    <label className="grid gap-1">
                        The words
                        <textarea
                            className="min-h-40 rounded-md border bg-background p-3 text-base"
                            value={form.data.body}
                            onChange={(e) =>
                                form.setData('body', e.target.value)
                            }
                        />
                        {form.errors.body && (
                            <span className="text-destructive">
                                {form.errors.body}
                            </span>
                        )}
                    </label>
                    <div>
                        <Button disabled={form.processing}>Check it</Button>
                    </div>
                </form>

                {result && (
                    <section className="grid gap-3 rounded-lg border p-4">
                        <h2 className="flex flex-wrap items-center gap-2 font-semibold">
                            {result.ready
                                ? 'Ready to go'
                                : 'Fix these before it goes out'}
                            {result.rewritten && (
                                <Badge variant="secondary">Rewritten</Badge>
                            )}
                        </h2>
                        <div className="rounded-md bg-muted/40 p-3">
                            <Marked
                                text={result.input.body}
                                findings={result.findings}
                            />
                        </div>
                        {result.listing && (
                            <p className="text-sm text-muted-foreground">
                                Checked against MLS# {result.listing.mls_number}
                                : {result.listing.address}
                            </p>
                        )}
                        <Findings findings={result.findings} />
                        {result.findings.length > 0 && (
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        form.setData('body', result.fixed)
                                    }
                                >
                                    Apply the fixes
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        void confirmCredits(
                                            'ai.ad_compliance',
                                        ).then((ok) => ok && check(true));
                                    }}
                                >
                                    Rewrite it for me (AI)
                                </Button>
                            </div>
                        )}
                        <SubmitBox form={form} required={approval_required} />
                    </section>
                )}

                {reviewer && (
                    <section className="grid gap-3">
                        <div className="flex flex-wrap items-center gap-3">
                            <h2 className="font-semibold">
                                Waiting for your approval ({queue.length})
                            </h2>
                            <label className="ml-auto flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={approval_required}
                                    onChange={(e) =>
                                        router.post(
                                            '/marketing-check/settings',
                                            {
                                                approval_required:
                                                    e.target.checked,
                                            },
                                            { preserveScroll: true },
                                        )
                                    }
                                />
                                Agents must send marketing for approval
                            </label>
                        </div>
                        {queue.map((r) => (
                            <ReviewCard key={r.ulid} review={r} decide />
                        ))}
                    </section>
                )}

                {mine.length > 0 && (
                    <section className="grid gap-3">
                        <h2 className="font-semibold">Sent for approval</h2>
                        {mine.map((r) => (
                            <ReviewCard key={r.ulid} review={r} />
                        ))}
                    </section>
                )}
            </div>
        </>
    );
}

function SubmitBox({
    form,
    required,
}: {
    form: InertiaFormProps<FormShape>;
    required: boolean;
}) {
    return (
        <div className="grid gap-2 border-t pt-3">
            <p className="text-sm text-muted-foreground">
                {required
                    ? 'Your broker approves marketing before it goes out. Attach the flyer or ad if you have it.'
                    : 'Want your broker to look at it? Send it for approval.'}
            </p>
            <div className="flex flex-wrap items-center gap-2">
                <input
                    type="file"
                    accept="application/pdf,image/png,image/jpeg"
                    onChange={(e) =>
                        form.setData('file', e.target.files?.[0] ?? null)
                    }
                />
                <Button
                    onClick={() => {
                        form.transform((d) => ({ ...d, rewrite: false }));
                        form.post('/marketing-check/submit', {
                            forceFormData: true,
                            preserveScroll: true,
                        });
                    }}
                    disabled={form.processing || form.data.body.trim() === ''}
                >
                    Send for approval
                </Button>
            </div>
            {form.errors.file && (
                <p className="text-destructive">{form.errors.file}</p>
            )}
        </div>
    );
}

function ReviewCard({ review, decide }: { review: Review; decide?: boolean }) {
    const [note, setNote] = useState('');

    return (
        <article className="grid gap-2 rounded-lg border p-4">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">{review.title}</span>
                <span className="text-muted-foreground">
                    {review.kind}
                    {decide ? ` · ${review.agent}` : ''} · {review.created_at}
                </span>
                <Badge
                    className="ml-auto"
                    variant={
                        review.status === 'approved'
                            ? 'default'
                            : review.status === 'changes'
                              ? 'destructive'
                              : 'outline'
                    }
                >
                    {statusLabel[review.status] ?? review.status}
                </Badge>
            </div>
            <div className="rounded-md bg-muted/40 p-3">
                <Marked text={review.body} findings={review.findings} />
            </div>
            {review.findings.length > 0 && (
                <Findings findings={review.findings} />
            )}
            {review.has_file && (
                <a
                    className="w-fit underline"
                    href={`/marketing-check/${review.ulid}/file`}
                    target="_blank"
                    rel="noreferrer"
                >
                    Open the attached file
                </a>
            )}
            {review.note && (
                <p className="rounded-md border p-2">
                    Broker&rsquo;s note: {review.note}
                </p>
            )}
            {decide && (
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        onClick={() =>
                            router.post(
                                `/marketing-check/${review.ulid}/decide`,
                                { status: 'approved', note },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Approve
                    </Button>
                    <Input
                        className="max-w-sm"
                        aria-label="What to change"
                        placeholder="What to change"
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                    />
                    <Button
                        variant="outline"
                        onClick={() =>
                            router.post(
                                `/marketing-check/${review.ulid}/decide`,
                                { status: 'changes', note },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Send back
                    </Button>
                </div>
            )}
        </article>
    );
}
