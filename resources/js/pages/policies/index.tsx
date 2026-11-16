import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { postJson } from '@/lib/http';

type Source = { title: string; page: number; quote: string };
type Question = {
    ulid: string;
    question: string;
    answer: string | null;
    status: string;
    reply: string | null;
    sources: Source[];
    at: string;
    who?: string;
};
type Doc = {
    ulid: string;
    title: string;
    version: number;
    pages: number;
    summary: string | null;
    at: string;
    acknowledged: number;
    waiting: string[];
};
type Props = {
    broker: boolean;
    toAcknowledge: {
        ulid: string;
        title: string;
        version: number;
        summary: string;
    }[];
    mine: Question[];
    documents: Doc[];
    gaps: Question[];
};

function Answer({ q }: { q: Question }) {
    return (
        <div className="grid gap-1 rounded-md border p-3">
            <p className="font-medium">{q.question}</p>
            {q.answer && <p>{q.answer}</p>}
            {q.sources.map((s, i) => (
                <blockquote
                    key={i}
                    className="border-l-2 pl-2 text-xs text-muted-foreground"
                >
                    “{s.quote}” — {s.title}, page {s.page}
                </blockquote>
            ))}
            {q.status === 'to_broker' && (
                <Badge variant="outline" className="justify-self-start">
                    Waiting for your broker
                </Badge>
            )}
            {q.reply && (
                <p>
                    <strong>Your broker:</strong> {q.reply}
                </p>
            )}
        </div>
    );
}

function Reply({ q }: { q: Question }) {
    const form = useForm({ reply: '' });

    return (
        <div className="grid gap-2 rounded-md border p-3">
            <p>
                <strong>{q.who}</strong> ({q.at}): “{q.question}”
            </p>
            {q.reply ? (
                <p>You replied: {q.reply}</p>
            ) : (
                <form
                    className="flex flex-wrap gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/policies/questions/${q.ulid}/reply`, {
                            preserveScroll: true,
                        });
                    }}
                >
                    <Input
                        aria-label="Your reply"
                        className="min-w-64 flex-1"
                        value={form.data.reply}
                        onChange={(e) => form.setData('reply', e.target.value)}
                    />
                    <Button
                        size="sm"
                        type="submit"
                        disabled={
                            form.processing || form.data.reply.trim() === ''
                        }
                    >
                        Reply
                    </Button>
                </form>
            )}
            <Button
                size="sm"
                variant="ghost"
                className="justify-self-start"
                onClick={() =>
                    router.post(
                        `/policies/questions/${q.ulid}/close`,
                        {},
                        { preserveScroll: true },
                    )
                }
            >
                Added to the manual: close this gap
            </Button>
        </div>
    );
}

/** Compliance Knowledge (specs/ai/05, DQ6): ask, acknowledge changes, and (for brokers) the library and its gaps. */
export default function Policies({
    broker,
    toAcknowledge,
    mine,
    documents,
    gaps,
}: Props) {
    const [question, setQuestion] = useState('');
    const [busy, setBusy] = useState(false);
    const [asked, setAsked] = useState<Question[]>([]);
    const add = useForm<{
        title: string;
        file: File | null;
        text: string;
        replaces: string;
    }>({ title: '', file: null, text: '', replaces: '' });

    const ask = () => {
        setBusy(true);
        void postJson<{
            ulid: string;
            status: string;
            answer: string;
            sources: Source[];
        }>('/policies/ask', { question })
            .then((r) => {
                setAsked([
                    {
                        ulid: r.ulid,
                        question,
                        answer: r.answer,
                        status: r.status,
                        reply: null,
                        sources: r.sources,
                        at: '',
                    },
                    ...asked,
                ]);
                setQuestion('');
            })
            .finally(() => setBusy(false));
    };

    return (
        <>
            <Head title="Policies" />
            <div className="flex max-w-4xl flex-col gap-4 p-4 text-sm md:p-6">
                <PageHeader
                    title="Policies"
                    description="Ask about your brokerage's policies and your state's rules. Answers come only from your brokerage's own documents, with the passage quoted; anything else goes to your broker."
                />
                {toAcknowledge.map((d) => (
                    <section
                        key={d.ulid}
                        className="grid gap-2 rounded-lg border border-amber-500 p-4"
                        aria-label={`Policy update: ${d.title}`}
                    >
                        <p className="font-medium">
                            Policy update: {d.title} (version {d.version})
                        </p>
                        <p className="whitespace-pre-line">{d.summary}</p>
                        <Button
                            size="sm"
                            className="justify-self-start"
                            onClick={() =>
                                router.post(
                                    `/policies/documents/${d.ulid}/acknowledge`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            I have read this
                        </Button>
                    </section>
                ))}
                <section className="grid gap-2">
                    <form
                        className="flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            ask();
                        }}
                    >
                        <Input
                            aria-label="Your question"
                            className="min-w-64 flex-1"
                            placeholder="e.g. Can I represent both sides? ¿Cuándo debo entregar el depósito?"
                            value={question}
                            onChange={(e) => setQuestion(e.target.value)}
                        />
                        <Button
                            type="submit"
                            disabled={busy || question.trim().length < 5}
                        >
                            Ask
                        </Button>
                    </form>
                    {[...asked, ...mine].map((q) => (
                        <Answer key={q.ulid} q={q} />
                    ))}
                </section>
                {broker && (
                    <>
                        <section className="grid gap-2">
                            <h2 className="font-semibold">
                                Questions your documents could not answer (
                                {gaps.length})
                            </h2>
                            <p className="text-muted-foreground">
                                These show what your manual is missing. Reply,
                                then add the answer to your manual and close the
                                gap.
                            </p>
                            {gaps.map((q) => (
                                <Reply key={q.ulid} q={q} />
                            ))}
                        </section>
                        <section className="grid gap-2 rounded-lg border p-4">
                            <h2 className="font-semibold">
                                Your policy documents
                            </h2>
                            {documents.length === 0 && (
                                <p className="text-muted-foreground">
                                    None yet. Add your policy manual to start.
                                </p>
                            )}
                            <ul className="divide-y">
                                {documents.map((d) => (
                                    <li
                                        key={d.ulid}
                                        className="grid gap-1 py-2"
                                    >
                                        <span className="flex flex-wrap items-center gap-2">
                                            <strong>{d.title}</strong>
                                            <Badge variant="outline">
                                                v{d.version}
                                            </Badge>
                                            <span className="text-muted-foreground">
                                                {d.pages} page(s) · {d.at} ·{' '}
                                                {d.acknowledged} acknowledged
                                            </span>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    add.setData(
                                                        'replaces',
                                                        d.ulid,
                                                    )
                                                }
                                            >
                                                Upload a new version
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    router.delete(
                                                        `/policies/documents/${d.ulid}`,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Remove
                                            </Button>
                                        </span>
                                        {d.waiting.length > 0 && (
                                            <span className="text-xs text-muted-foreground">
                                                Not acknowledged yet:{' '}
                                                {d.waiting.join(', ')}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                            <form
                                className="grid gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    add.post('/policies/documents', {
                                        forceFormData: true,
                                        preserveScroll: true,
                                        onSuccess: () => add.reset(),
                                    });
                                }}
                            >
                                <p className="font-medium">
                                    {add.data.replaces
                                        ? 'New version of ' +
                                          (documents.find(
                                              (d) =>
                                                  d.ulid === add.data.replaces,
                                          )?.title ?? '')
                                        : 'Add a document'}
                                </p>
                                <Input
                                    aria-label="Title"
                                    placeholder="Title, e.g. Policy and procedures manual"
                                    value={add.data.title}
                                    onChange={(e) =>
                                        add.setData('title', e.target.value)
                                    }
                                />
                                <input
                                    type="file"
                                    accept="application/pdf"
                                    aria-label="PDF"
                                    onChange={(e) =>
                                        add.setData(
                                            'file',
                                            e.target.files?.[0] ?? null,
                                        )
                                    }
                                />
                                <textarea
                                    className="min-h-24 rounded border bg-background p-2"
                                    aria-label="Or paste the text"
                                    placeholder="Or paste the text"
                                    value={add.data.text}
                                    onChange={(e) =>
                                        add.setData('text', e.target.value)
                                    }
                                />
                                {Object.values(add.errors)[0] && (
                                    <span className="text-destructive">
                                        {Object.values(add.errors)[0]}
                                    </span>
                                )}
                                <span className="flex gap-2">
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={add.processing}
                                    >
                                        Publish and tell the agents
                                    </Button>
                                    {add.data.replaces && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                add.setData('replaces', '')
                                            }
                                        >
                                            Cancel
                                        </Button>
                                    )}
                                </span>
                            </form>
                        </section>
                    </>
                )}
            </div>
        </>
    );
}
