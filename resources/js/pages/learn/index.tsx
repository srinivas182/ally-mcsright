import { Head, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Course = {
    ulid: string;
    title: string;
    summary: string | null;
    minutes: number;
    source: string;
    enrolled: boolean;
    percent: number;
    completed: boolean;
    due_on: string | null;
    assigned: boolean;
};
type Page = {
    ulid: string;
    title: string;
    required: boolean;
    needs_ack: boolean;
    updated: string;
};
type Hit = { ulid: string; title: string; category: string; excerpt: string };

/** Learn (LN1): my courses (assigned first), the course library and the brokerage's knowledge base. */
export default function LearnIndex({
    courses,
    knowledge,
    outstanding,
    q,
    results,
    manages,
}: {
    courses: Course[];
    knowledge: Record<string, Page[]>;
    outstanding: number;
    q: string;
    results: Hit[] | null;
    manages: boolean;
}) {
    const mine = courses
        .filter((c) => c.enrolled && !c.completed)
        .sort((a, b) => (a.due_on ?? '9999').localeCompare(b.due_on ?? '9999'));
    const done = courses.filter((c) => c.completed);
    const rest = courses.filter((c) => !c.enrolled);

    const card = (c: Course) => (
        <a
            key={c.ulid}
            href={`/learn/courses/${c.ulid}`}
            className="grid gap-1 rounded-lg border p-4 hover:bg-muted/40"
        >
            <span className="flex flex-wrap items-center gap-2 text-base font-semibold">
                {c.title}
                {c.assigned && !c.completed && <Badge>Assigned</Badge>}
                {c.completed && <Badge variant="secondary">Done</Badge>}
            </span>
            {c.summary && (
                <span className="text-sm text-muted-foreground">
                    {c.summary}
                </span>
            )}
            <span className="text-sm">
                About {c.minutes} minutes
                {c.enrolled && !c.completed && ` · ${c.percent}% done`}
                {c.due_on && !c.completed && ` · due ${c.due_on}`}
            </span>
            {c.enrolled && !c.completed && (
                <span
                    className="h-2 rounded bg-muted"
                    role="progressbar"
                    aria-valuenow={c.percent}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-label={`${c.title} progress`}
                >
                    <span
                        className="block h-2 rounded bg-primary"
                        style={{ width: `${c.percent}%` }}
                    />
                </span>
            )}
        </a>
    );

    return (
        <>
            <Head title="Learn" />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 text-base md:p-6">
                <PageHeader
                    title="Learn"
                    description="Short courses and your office's how-to pages."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button asChild>
                                <a href="/learn/practice">
                                    Practice a conversation
                                </a>
                            </Button>
                            <Button asChild variant="outline">
                                <a href="/learn/launch">
                                    Launch, mentors and scorecard
                                </a>
                            </Button>
                            {manages && (
                                <Button asChild variant="outline">
                                    <a href="/learn/manage">Manage training</a>
                                </Button>
                            )}
                        </div>
                    }
                />
                {outstanding > 0 && (
                    <p
                        role="status"
                        className="rounded border border-amber-300 bg-amber-50 p-3 text-amber-900"
                    >
                        {outstanding} office page(s) need you to read and
                        confirm them. They are marked “Please read” below.
                    </p>
                )}
                {mine.length > 0 && (
                    <section className="grid gap-3">
                        <h2 className="text-lg font-semibold">My courses</h2>
                        <div className="grid gap-3 md:grid-cols-2">
                            {mine.map(card)}
                        </div>
                    </section>
                )}
                <section className="grid gap-3">
                    <h2 className="text-lg font-semibold">Course library</h2>
                    {rest.length === 0 && mine.length === 0 && (
                        <p className="text-muted-foreground">
                            No courses yet.
                            {manages &&
                                ' Add a starter course from Manage training.'}
                        </p>
                    )}
                    <div className="grid gap-3 md:grid-cols-2">
                        {rest.map(card)}
                    </div>
                </section>
                {done.length > 0 && (
                    <section className="grid gap-3">
                        <h2 className="text-lg font-semibold">Finished</h2>
                        <div className="grid gap-3 md:grid-cols-2">
                            {done.map(card)}
                        </div>
                    </section>
                )}
                <section className="grid gap-3">
                    <h2 className="text-lg font-semibold">
                        Office knowledge base
                    </h2>
                    <form
                        role="search"
                        className="flex gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            const v = new FormData(e.currentTarget).get('q');
                            router.get('/learn', {
                                q: typeof v === 'string' ? v : '',
                            });
                        }}
                    >
                        <Input
                            name="q"
                            defaultValue={q}
                            aria-label="Search the knowledge base"
                            placeholder="e.g. lockbox, title company, open house"
                        />
                        <Button>Search</Button>
                    </form>
                    {results && (
                        <ul className="grid gap-2">
                            {results.length === 0 && (
                                <li className="text-muted-foreground">
                                    Nothing found for “{q}”.
                                </li>
                            )}
                            {results.map((r) => (
                                <li key={r.ulid}>
                                    <a
                                        href={`/learn/kb/${r.ulid}`}
                                        className="font-medium text-primary underline"
                                    >
                                        {r.title}
                                    </a>
                                    <span className="block text-sm text-muted-foreground">
                                        {r.category} · {r.excerpt}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {Object.entries(knowledge).map(([cat, pages]) => (
                        <div key={cat}>
                            <h3 className="font-medium">{cat}</h3>
                            <ul className="grid gap-1">
                                {pages.map((p) => (
                                    <li
                                        key={p.ulid}
                                        className="flex items-center gap-2"
                                    >
                                        <a
                                            href={`/learn/kb/${p.ulid}`}
                                            className="text-primary underline"
                                        >
                                            {p.title}
                                        </a>
                                        {p.needs_ack && (
                                            <Badge variant="destructive">
                                                Please read
                                            </Badge>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </section>
            </div>
        </>
    );
}
