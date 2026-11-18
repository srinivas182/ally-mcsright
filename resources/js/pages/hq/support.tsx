import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Ticket = {
    ulid: string;
    topic: string;
    subject: string;
    body: string;
    status: string;
    reply: string | null;
    created_at: string;
    brokerage: string;
    name: string;
    email: string;
    page: string | null;
    file: string | null;
};

/** HQ → Support: every brokerage's help requests, oldest open first, answered by email. */
export default function HqSupport({
    status,
    tickets,
}: {
    status: string;
    tickets: Ticket[];
}) {
    const [drafts, setDrafts] = useState<Record<string, string>>({});

    return (
        <>
            <Head title="Support" />
            <div className="mx-auto grid w-full max-w-5xl gap-4 p-4 md:p-6">
                <PageHeader
                    title="Support"
                    description="Help requests from every brokerage. Your answer is emailed to the person and shown on their Help page."
                />
                <nav aria-label="Filter" className="flex gap-2">
                    {['open', 'answered', 'closed', 'all'].map((s) => (
                        <Button
                            key={s}
                            size="sm"
                            variant={s === status ? 'default' : 'outline'}
                            onClick={() =>
                                router.get('/hq/support', { status: s })
                            }
                        >
                            {s[0].toUpperCase() + s.slice(1)}
                        </Button>
                    ))}
                </nav>
                {tickets.length === 0 && (
                    <p className="text-muted-foreground">Nothing here.</p>
                )}
                {tickets.map((t) => (
                    <section
                        key={t.ulid}
                        className="grid gap-2 rounded-lg border p-4"
                    >
                        <p className="flex flex-wrap items-center gap-2">
                            <strong>{t.subject}</strong>
                            <Badge variant="outline">{t.topic}</Badge>
                            <Badge>{t.status}</Badge>
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {t.name} · {t.brokerage} · {t.created_at}
                            {t.page ? ` · from ${t.page}` : ''}
                        </p>
                        <p className="whitespace-pre-wrap">{t.body}</p>
                        {t.file && (
                            <a
                                className="text-sm underline"
                                href={`/hq/support/${t.ulid}/file`}
                            >
                                Download {t.file} (kept 30 days)
                            </a>
                        )}
                        {t.reply && (
                            <p className="rounded bg-muted/40 p-2 whitespace-pre-wrap">
                                {t.reply}
                            </p>
                        )}
                        {t.status !== 'closed' && (
                            <form
                                className="grid gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    router.post(
                                        `/hq/support/${t.ulid}`,
                                        { reply: drafts[t.ulid] ?? '' },
                                        { preserveScroll: true },
                                    );
                                }}
                            >
                                <textarea
                                    aria-label={`Answer to ${t.subject}`}
                                    rows={3}
                                    className="rounded border px-3 py-2"
                                    value={drafts[t.ulid] ?? ''}
                                    onChange={(e) =>
                                        setDrafts({
                                            ...drafts,
                                            [t.ulid]: e.target.value,
                                        })
                                    }
                                />
                                <Button
                                    size="sm"
                                    className="justify-self-start"
                                    disabled={!drafts[t.ulid]}
                                >
                                    {t.reply
                                        ? 'Send another answer'
                                        : 'Send answer'}
                                </Button>
                            </form>
                        )}
                    </section>
                ))}
            </div>
        </>
    );
}
