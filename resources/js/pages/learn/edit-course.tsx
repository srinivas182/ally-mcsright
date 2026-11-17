import { Head, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Question = { q: string; options: string[]; answer: number; why: string };
type Lesson = {
    title: string;
    kind: string;
    body: string | null;
    video_url: string | null;
    questions: Question[] | null;
};

const blankQuestion: Question = {
    q: '',
    options: ['', ''],
    answer: 0,
    why: '',
};

/** Write or change a course (LN1): lessons of text, video (YouTube, Vimeo, Loom) or quiz. */
export default function EditCourse({
    course,
    lessons: initial,
}: {
    course: {
        ulid: string;
        title: string;
        summary: string | null;
        pass_mark: number;
        minutes: number;
        status: string;
    } | null;
    lessons: Lesson[];
}) {
    const form = useForm<{
        title: string;
        summary: string;
        pass_mark: number;
        minutes: number;
        lessons: Lesson[];
        publish?: boolean;
    }>({
        title: course?.title ?? '',
        summary: course?.summary ?? '',
        pass_mark: course?.pass_mark ?? 80,
        minutes: course?.minutes ?? 10,
        lessons: initial,
    });
    const lessons = form.data.lessons;
    const setLesson = (i: number, patch: Partial<Lesson>) =>
        form.setData(
            'lessons',
            lessons.map((l, j) => (j === i ? { ...l, ...patch } : l)),
        );
    const setQuestion = (i: number, k: number, patch: Partial<Question>) =>
        setLesson(i, {
            questions: (lessons[i].questions ?? []).map((q, j) =>
                j === k ? { ...q, ...patch } : q,
            ),
        });
    const save = (publish?: boolean) => {
        form.transform((d) => (publish === undefined ? d : { ...d, publish }));
        form.post(
            course
                ? `/learn/manage/courses/${course.ulid}`
                : '/learn/manage/courses',
        );
    };

    return (
        <>
            <Head title={course ? `Edit ${course.title}` : 'New course'} />
            <div className="mx-auto grid w-full max-w-3xl gap-4 p-4 text-base md:p-6">
                <PageHeader
                    title={course ? 'Edit course' : 'New course'}
                    description="Keep lessons short: one idea each. End with a quiz so people can check themselves."
                    actions={
                        course ? (
                            <Button asChild variant="outline">
                                <a href={`/learn/courses/${course.ulid}`}>
                                    Preview
                                </a>
                            </Button>
                        ) : undefined
                    }
                />
                <label className="grid gap-1">
                    Course name
                    <Input
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                    />
                </label>
                <label className="grid gap-1">
                    One-line summary
                    <Input
                        value={form.data.summary}
                        onChange={(e) =>
                            form.setData('summary', e.target.value)
                        }
                    />
                </label>
                <span className="flex flex-wrap gap-4">
                    <label className="flex items-center gap-2">
                        Minutes
                        <Input
                            type="number"
                            className="w-24"
                            value={form.data.minutes}
                            onChange={(e) =>
                                form.setData('minutes', Number(e.target.value))
                            }
                        />
                    </label>
                    <label className="flex items-center gap-2">
                        Pass mark %
                        <Input
                            type="number"
                            className="w-24"
                            value={form.data.pass_mark}
                            onChange={(e) =>
                                form.setData(
                                    'pass_mark',
                                    Number(e.target.value),
                                )
                            }
                        />
                    </label>
                </span>
                {lessons.map((l, i) => (
                    <section
                        key={i}
                        className="grid gap-2 rounded-lg border p-3"
                    >
                        <span className="flex flex-wrap items-center gap-2">
                            <strong>Lesson {i + 1}</strong>
                            <select
                                aria-label={`Lesson ${i + 1} type`}
                                className="rounded border px-2 py-1"
                                value={l.kind}
                                onChange={(e) =>
                                    setLesson(i, {
                                        kind: e.target.value,
                                        questions:
                                            e.target.value === 'quiz'
                                                ? (l.questions ?? [
                                                      { ...blankQuestion },
                                                  ])
                                                : null,
                                    })
                                }
                            >
                                <option value="text">Text</option>
                                <option value="video">Video</option>
                                <option value="quiz">Quiz</option>
                            </select>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                className="ml-auto"
                                onClick={() =>
                                    form.setData(
                                        'lessons',
                                        lessons.filter((_, j) => j !== i),
                                    )
                                }
                            >
                                Remove
                            </Button>
                        </span>
                        <Input
                            aria-label={`Lesson ${i + 1} title`}
                            placeholder="Lesson title"
                            value={l.title}
                            onChange={(e) =>
                                setLesson(i, { title: e.target.value })
                            }
                        />
                        {l.kind === 'video' && (
                            <Input
                                aria-label={`Lesson ${i + 1} video link`}
                                placeholder="https://www.youtube.com/watch?v=…"
                                value={l.video_url ?? ''}
                                onChange={(e) =>
                                    setLesson(i, { video_url: e.target.value })
                                }
                            />
                        )}
                        {l.kind !== 'quiz' && (
                            <textarea
                                aria-label={`Lesson ${i + 1} text`}
                                rows={6}
                                className="rounded border px-3 py-2 text-sm"
                                placeholder="Write the lesson. **bold**, - lists and # headings work."
                                value={l.body ?? ''}
                                onChange={(e) =>
                                    setLesson(i, { body: e.target.value })
                                }
                            />
                        )}
                        {l.kind === 'quiz' &&
                            (l.questions ?? []).map((q, k) => (
                                <fieldset
                                    key={k}
                                    className="grid gap-1 rounded border p-2"
                                >
                                    <legend className="text-sm font-medium">
                                        Question {k + 1}
                                    </legend>
                                    <Input
                                        aria-label={`Question ${k + 1}`}
                                        value={q.q}
                                        onChange={(e) =>
                                            setQuestion(i, k, {
                                                q: e.target.value,
                                            })
                                        }
                                    />
                                    {q.options.map((o, m) => (
                                        <label
                                            key={m}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <input
                                                type="radio"
                                                name={`a-${i}-${k}`}
                                                checked={q.answer === m}
                                                onChange={() =>
                                                    setQuestion(i, k, {
                                                        answer: m,
                                                    })
                                                }
                                                aria-label={`Answer ${m + 1} is right`}
                                            />
                                            <Input
                                                aria-label={`Answer ${m + 1}`}
                                                value={o}
                                                onChange={(e) =>
                                                    setQuestion(i, k, {
                                                        options: q.options.map(
                                                            (x, n) =>
                                                                n === m
                                                                    ? e.target
                                                                          .value
                                                                    : x,
                                                        ),
                                                    })
                                                }
                                            />
                                        </label>
                                    ))}
                                    <span className="flex gap-2">
                                        {q.options.length < 6 && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    setQuestion(i, k, {
                                                        options: [
                                                            ...q.options,
                                                            '',
                                                        ],
                                                    })
                                                }
                                            >
                                                Add an answer
                                            </Button>
                                        )}
                                    </span>
                                    <Input
                                        aria-label={`Why question ${k + 1} is right`}
                                        placeholder="Why the right answer is right (shown after they answer)"
                                        value={q.why}
                                        onChange={(e) =>
                                            setQuestion(i, k, {
                                                why: e.target.value,
                                            })
                                        }
                                    />
                                </fieldset>
                            ))}
                        {l.kind === 'quiz' && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="justify-self-start"
                                onClick={() =>
                                    setLesson(i, {
                                        questions: [
                                            ...(l.questions ?? []),
                                            { ...blankQuestion },
                                        ],
                                    })
                                }
                            >
                                Add a question
                            </Button>
                        )}
                    </section>
                ))}
                <Button
                    type="button"
                    variant="outline"
                    className="justify-self-start"
                    onClick={() =>
                        form.setData('lessons', [
                            ...lessons,
                            {
                                title: '',
                                kind: 'text',
                                body: '',
                                video_url: null,
                                questions: null,
                            },
                        ])
                    }
                >
                    Add a lesson
                </Button>
                {Object.values(form.errors).map((e) => (
                    <p key={e} role="alert" className="text-red-700">
                        {e}
                    </p>
                ))}
                <span className="flex flex-wrap gap-2">
                    <Button disabled={form.processing} onClick={() => save()}>
                        Save
                    </Button>
                    {course?.status !== 'published' ? (
                        <Button
                            variant="outline"
                            disabled={form.processing}
                            onClick={() => save(true)}
                        >
                            Save and publish
                        </Button>
                    ) : (
                        <Button
                            variant="ghost"
                            disabled={form.processing}
                            onClick={() => save(false)}
                        >
                            Unpublish
                        </Button>
                    )}
                </span>
            </div>
        </>
    );
}
