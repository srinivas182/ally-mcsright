import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Course = {
    ulid: string;
    title: string;
    status: string;
    source: string;
    lessons: number;
    people: number;
    finished: number;
    late: number;
};
type Starter = {
    key: string;
    title: string;
    summary: string;
    minutes: number;
    lessons: number;
    added: boolean;
};
type Page = {
    ulid: string;
    title: string;
    category: string;
    required: boolean;
    status: string;
    version: number;
};
type Person = { id: number; name: string };

/** Learn › Manage training (LN1): courses, starter courses, assignments, knowledge base pages and the report. */
export default function LearnManage({
    courses,
    starters,
    articles,
    people,
}: {
    courses: Course[];
    starters: Starter[];
    articles: Page[];
    people: Person[];
}) {
    const [assigning, setAssigning] = useState<string | null>(null);
    const [picked, setPicked] = useState<number[]>([]);
    const [due, setDue] = useState('');
    const build = useForm({ topic: '', notes: '', skill: '' });

    return (
        <>
            <Head title="Manage training" />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 text-base md:p-6">
                <PageHeader
                    title="Manage training"
                    description="Write courses and office pages, assign them, and see who has finished."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="outline">
                                <a href="/learn/launch">
                                    New agents and mentors
                                </a>
                            </Button>
                            <Button asChild variant="outline">
                                <a href="/learn/manage/report.csv">
                                    Download report
                                </a>
                            </Button>
                            <Button asChild>
                                <a href="/learn/manage/courses/new">
                                    New course
                                </a>
                            </Button>
                        </div>
                    }
                />
                <section className="grid gap-2">
                    <h2 className="text-lg font-semibold">Courses</h2>
                    {courses.length === 0 && (
                        <p className="text-muted-foreground">
                            No courses yet. Add a starter course below, or write
                            your own.
                        </p>
                    )}
                    {courses.map((c) => (
                        <div
                            key={c.ulid}
                            className="grid gap-2 rounded border p-3"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <a
                                    className="font-medium text-primary underline"
                                    href={`/learn/manage/courses/${c.ulid}`}
                                >
                                    {c.title}
                                </a>
                                <Badge
                                    variant={
                                        c.status === 'published'
                                            ? 'default'
                                            : 'outline'
                                    }
                                >
                                    {c.status === 'published'
                                        ? 'Published'
                                        : 'Draft'}
                                </Badge>
                                <span className="text-sm text-muted-foreground">
                                    {c.lessons} lesson(s) · {c.finished} of{' '}
                                    {c.people} finished
                                    {c.late > 0 && ` · ${c.late} late`}
                                </span>
                                {c.status === 'published' && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="ml-auto"
                                        onClick={() => {
                                            setAssigning(c.ulid);
                                            setPicked([]);
                                        }}
                                    >
                                        Assign
                                    </Button>
                                )}
                            </div>
                            {assigning === c.ulid && (
                                <form
                                    className="grid gap-2"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        router.post(
                                            `/learn/manage/courses/${c.ulid}/assign`,
                                            {
                                                people: picked,
                                                due_on: due || null,
                                            },
                                            {
                                                preserveScroll: true,
                                                onSuccess: () =>
                                                    setAssigning(null),
                                            },
                                        );
                                    }}
                                >
                                    <span className="flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                setPicked(
                                                    people.map((p) => p.id),
                                                )
                                            }
                                        >
                                            Everyone
                                        </Button>
                                        <label className="flex items-center gap-1 text-sm">
                                            Due by
                                            <input
                                                type="date"
                                                className="rounded border px-2 py-1"
                                                value={due}
                                                onChange={(e) =>
                                                    setDue(e.target.value)
                                                }
                                            />
                                        </label>
                                    </span>
                                    <div className="grid max-h-56 gap-1 overflow-y-auto sm:grid-cols-2">
                                        {people.map((p) => (
                                            <label
                                                key={p.id}
                                                className="flex items-center gap-2"
                                            >
                                                <input
                                                    type="checkbox"
                                                    className="size-4"
                                                    checked={picked.includes(
                                                        p.id,
                                                    )}
                                                    onChange={(e) =>
                                                        setPicked(
                                                            e.target.checked
                                                                ? [
                                                                      ...picked,
                                                                      p.id,
                                                                  ]
                                                                : picked.filter(
                                                                      (x) =>
                                                                          x !==
                                                                          p.id,
                                                                  ),
                                                        )
                                                    }
                                                />
                                                {p.name}
                                            </label>
                                        ))}
                                    </div>
                                    <Button
                                        size="sm"
                                        className="justify-self-start"
                                        disabled={picked.length === 0}
                                    >
                                        Assign to {picked.length} people
                                    </Button>
                                </form>
                            )}
                        </div>
                    ))}
                </section>
                <section className="grid gap-2 rounded-lg border p-4">
                    <h2 className="text-lg font-semibold">
                        Build a course with AI
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Name the topic and, if you like, paste your own notes (a
                        policy, a script, a checklist). AI writes a short draft
                        with a quiz. Nothing reaches agents until you publish
                        it. Uses 5 RightAlly credits.
                    </p>
                    <form
                        className="grid gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            build.post('/learn/manage/build');
                        }}
                    >
                        <input
                            aria-label="Course topic"
                            className="rounded border px-3 py-2"
                            placeholder="e.g. Running a great open house"
                            value={build.data.topic}
                            onChange={(e) =>
                                build.setData('topic', e.target.value)
                            }
                        />
                        <textarea
                            aria-label="Your notes (optional)"
                            rows={4}
                            className="rounded border px-3 py-2 text-sm"
                            placeholder="Optional: paste your office's notes. The course will stick to them."
                            value={build.data.notes}
                            onChange={(e) =>
                                build.setData('notes', e.target.value)
                            }
                        />
                        {build.errors.topic && (
                            <p role="alert" className="text-red-700">
                                {build.errors.topic}
                            </p>
                        )}
                        <Button
                            className="justify-self-start"
                            disabled={
                                build.processing || build.data.topic.length < 4
                            }
                        >
                            {build.processing
                                ? 'Writing the draft…'
                                : 'Write a draft course'}
                        </Button>
                    </form>
                </section>
                <section className="grid gap-2">
                    <h2 className="text-lg font-semibold">
                        Starter courses from RightAlly
                    </h2>
                    {starters.map((s) => (
                        <div
                            key={s.key}
                            className="flex flex-wrap items-center gap-2 rounded border p-3"
                        >
                            <span className="grid">
                                <span className="font-medium">{s.title}</span>
                                <span className="text-sm text-muted-foreground">
                                    {s.summary} · {s.lessons} lessons · about{' '}
                                    {s.minutes} minutes
                                </span>
                            </span>
                            {s.added ? (
                                <Badge variant="secondary" className="ml-auto">
                                    In your library
                                </Badge>
                            ) : (
                                <Button
                                    size="sm"
                                    className="ml-auto"
                                    onClick={() =>
                                        router.post(
                                            `/learn/manage/starters/${s.key}`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Add to my library
                                </Button>
                            )}
                        </div>
                    ))}
                </section>
                <section className="grid gap-2">
                    <div className="flex items-center gap-2">
                        <h2 className="text-lg font-semibold">
                            Knowledge base pages
                        </h2>
                        <Button
                            asChild
                            size="sm"
                            variant="outline"
                            className="ml-auto"
                        >
                            <a href="/learn/manage/kb/new">New page</a>
                        </Button>
                    </div>
                    {articles.map((a) => (
                        <div
                            key={a.ulid}
                            className="flex flex-wrap items-center gap-2"
                        >
                            <a
                                className="text-primary underline"
                                href={`/learn/manage/kb/${a.ulid}`}
                            >
                                {a.title}
                            </a>
                            <span className="text-sm text-muted-foreground">
                                {a.category} · version {a.version}
                            </span>
                            {a.required && <Badge>Everyone must read</Badge>}
                            {a.status === 'draft' && (
                                <Badge variant="outline">Draft</Badge>
                            )}
                        </div>
                    ))}
                </section>
            </div>
        </>
    );
}
