import { Head, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Hit = { slug: string; title: string; section?: string; summary: string };
type Request = {
    ulid: string;
    topic: string;
    subject: string;
    body: string;
    status: string;
    reply: string | null;
    created_at: string;
    replied_at: string | null;
};

/** The help centre (CH1): search, short articles by section, and "Ask us" support requests with their answers. */
export default function Help({
    q,
    results,
    sections,
    requests,
    topics,
}: {
    q: string;
    results: Hit[] | null;
    sections: Record<string, Hit[]>;
    requests: Request[];
    topics: Record<string, string>;
}) {
    const ask = useForm({ topic: 'how_to', subject: '', body: '', page: '' });

    return (
        <>
            <Head title="Help" />
            <div className="mx-auto grid w-full max-w-4xl gap-6 p-4 text-base md:p-6">
                <PageHeader
                    title="Help"
                    description="Short how-to guides, and a person to ask when you need one."
                />
                <form
                    role="search"
                    className="flex gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const value = new FormData(e.currentTarget).get('q');
                        router.get('/help', {
                            q: typeof value === 'string' ? value : '',
                        });
                    }}
                >
                    <Input
                        name="q"
                        defaultValue={q}
                        aria-label="Search help"
                        placeholder="What do you want to do? e.g. upload documents"
                        className="text-base"
                    />
                    <Button>Search</Button>
                </form>
                {results && (
                    <section aria-label="Search results" className="grid gap-2">
                        <h2 className="text-lg font-semibold">
                            {results.length > 0
                                ? `Results for “${q}”`
                                : `Nothing found for “${q}”. Try other words, or ask us below.`}
                        </h2>
                        {results.map((r) => (
                            <a
                                key={r.slug}
                                href={`/help/${r.slug}`}
                                className="rounded border p-3 hover:bg-muted/50"
                            >
                                <span className="font-medium text-primary underline">
                                    {r.title}
                                </span>
                                <span className="block text-sm text-muted-foreground">
                                    {r.summary}
                                </span>
                            </a>
                        ))}
                    </section>
                )}
                {Object.entries(sections).map(([section, list]) => (
                    <section key={section} className="grid gap-2">
                        <h2 className="text-lg font-semibold">{section}</h2>
                        <ul className="grid gap-2 sm:grid-cols-2">
                            {list.map((a) => (
                                <li key={a.slug}>
                                    <a
                                        href={`/help/${a.slug}`}
                                        className="block rounded border p-3 hover:bg-muted/50"
                                    >
                                        <span className="font-medium text-primary underline">
                                            {a.title}
                                        </span>
                                        <span className="block text-sm text-muted-foreground">
                                            {a.summary}
                                        </span>
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}
                <section
                    id="ask"
                    aria-labelledby="ask-title"
                    className="grid gap-3 rounded-lg border p-4"
                >
                    <h2 id="ask-title" className="text-lg font-semibold">
                        Ask us
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        A person at RightAlly answers by email, usually within
                        one working day. Never send passwords, card numbers or
                        bank details.
                    </p>
                    <form
                        className="grid gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            ask.post('/help/ask', {
                                preserveScroll: true,
                                onSuccess: () => ask.reset(),
                            });
                        }}
                    >
                        <label className="grid gap-1 text-sm">
                            What is it about?
                            <select
                                className="rounded border px-2 py-2 text-base"
                                value={ask.data.topic}
                                onChange={(e) =>
                                    ask.setData('topic', e.target.value)
                                }
                            >
                                {Object.entries(topics).map(([k, v]) => (
                                    <option key={k} value={k}>
                                        {v}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Short title
                            <Input
                                value={ask.data.subject}
                                onChange={(e) =>
                                    ask.setData('subject', e.target.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            What were you trying to do?
                            <textarea
                                rows={4}
                                className="rounded border px-3 py-2 text-base"
                                value={ask.data.body}
                                onChange={(e) =>
                                    ask.setData('body', e.target.value)
                                }
                            />
                        </label>
                        {Object.values(ask.errors).map((e) => (
                            <p
                                key={e}
                                role="alert"
                                className="text-sm text-red-700"
                            >
                                {e}
                            </p>
                        ))}
                        <Button
                            className="justify-self-start"
                            disabled={ask.processing}
                        >
                            Send to RightAlly support
                        </Button>
                    </form>
                </section>
                {requests.length > 0 && (
                    <section className="grid gap-2">
                        <h2 className="text-lg font-semibold">Your requests</h2>
                        {requests.map((r) => (
                            <details
                                key={r.ulid}
                                className="rounded border p-3"
                            >
                                <summary className="cursor-pointer">
                                    {r.subject}{' '}
                                    <Badge
                                        variant={
                                            r.status === 'answered'
                                                ? 'default'
                                                : 'outline'
                                        }
                                    >
                                        {r.status === 'open'
                                            ? 'Waiting for an answer'
                                            : r.status === 'answered'
                                              ? 'Answered'
                                              : 'Closed'}
                                    </Badge>
                                </summary>
                                <p className="mt-2 text-sm whitespace-pre-wrap text-muted-foreground">
                                    {r.body}
                                </p>
                                {r.reply && (
                                    <p className="mt-2 rounded bg-muted/40 p-2 whitespace-pre-wrap">
                                        {r.reply}
                                    </p>
                                )}
                                {r.status !== 'closed' && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            router.post(
                                                `/help/requests/${r.ulid}/close`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Mark as solved
                                    </Button>
                                )}
                            </details>
                        ))}
                    </section>
                )}
            </div>
        </>
    );
}
