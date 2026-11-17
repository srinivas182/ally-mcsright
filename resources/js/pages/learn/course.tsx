import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Lesson = {
    id: number;
    title: string;
    kind: string;
    html: string | null;
    embed: string | null;
    questions: { q: string; options: string[] }[] | null;
};
type Quiz = {
    lesson: number;
    score: number;
    passed: boolean;
    pass_mark: number;
    results: { correct: boolean; answer: number; why: string }[];
} | null;

/** The course player (LN1): one lesson at a time, large text, quizzes with the reasons shown after each try. */
export default function LearnCourse({
    course,
    lessons,
    enrolment,
    quiz,
}: {
    course: {
        ulid: string;
        title: string;
        summary: string | null;
        minutes: number;
        pass_mark: number;
        status: string;
    };
    lessons: Lesson[];
    enrolment: {
        done: number[];
        percent: number;
        completed: boolean;
        due_on: string | null;
    } | null;
    quiz: Quiz;
}) {
    const firstOpen =
        lessons.findIndex((l) => !enrolment?.done.includes(l.id)) ?? 0;
    const [at, setAt] = useState(
        quiz
            ? lessons.findIndex((l) => l.id === quiz.lesson)
            : Math.max(0, firstOpen),
    );
    const [answers, setAnswers] = useState<Record<number, number>>({});
    const lesson = lessons[at];
    const base = `/learn/courses/${course.ulid}`;

    return (
        <>
            <Head title={course.title} />
            <div className="mx-auto grid w-full max-w-3xl gap-4 p-4 text-base leading-relaxed md:p-6">
                <nav aria-label="Breadcrumb" className="text-sm">
                    <a href="/learn" className="text-primary underline">
                        Learn
                    </a>{' '}
                    › {course.title}
                </nav>
                <h1 className="text-2xl font-semibold">{course.title}</h1>
                {course.summary && (
                    <p className="text-muted-foreground">{course.summary}</p>
                )}
                {!enrolment ? (
                    <Button
                        className="justify-self-start"
                        onClick={() => router.post(`${base}/start`)}
                    >
                        Start this course (about {course.minutes} minutes)
                    </Button>
                ) : enrolment.completed ? (
                    <p className="flex flex-wrap items-center gap-3 rounded border border-green-300 bg-green-50 p-3 text-green-900">
                        Well done — you finished this course.
                        <a
                            className="underline"
                            href={`${base}/certificate.pdf`}
                        >
                            Download your certificate
                        </a>
                    </p>
                ) : (
                    <p className="text-sm">
                        {enrolment.percent}% done
                        {enrolment.due_on && ` · due ${enrolment.due_on}`}
                    </p>
                )}
                <ol className="flex flex-wrap gap-2" aria-label="Lessons">
                    {lessons.map((l, i) => (
                        <li key={l.id}>
                            <button
                                type="button"
                                aria-current={i === at ? 'step' : undefined}
                                onClick={() => setAt(i)}
                                className={`rounded border px-3 py-1 text-sm ${i === at ? 'bg-primary text-primary-foreground' : ''}`}
                            >
                                {i + 1}. {l.title}
                                {enrolment?.done.includes(l.id) && ' ✓'}
                            </button>
                        </li>
                    ))}
                </ol>
                {lesson && (
                    <section
                        aria-labelledby="lesson-title"
                        className="grid gap-3 rounded-lg border p-4"
                    >
                        <h2 id="lesson-title" className="text-xl font-semibold">
                            {lesson.title}
                        </h2>
                        {lesson.embed && (
                            <div className="aspect-video">
                                <iframe
                                    src={lesson.embed}
                                    title={lesson.title}
                                    className="h-full w-full rounded"
                                    sandbox="allow-scripts allow-same-origin allow-presentation"
                                    allow="fullscreen; picture-in-picture"
                                    referrerPolicy="strict-origin-when-cross-origin"
                                    loading="lazy"
                                />
                            </div>
                        )}
                        {lesson.html && (
                            <div
                                className="prose dark:prose-invert max-w-none"
                                dangerouslySetInnerHTML={{
                                    __html: lesson.html,
                                }}
                            />
                        )}
                        {lesson.questions && (
                            <form
                                className="grid gap-4"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    router.post(
                                        `${base}/lessons/${lesson.id}/quiz`,
                                        {
                                            answers: lesson.questions!.map(
                                                (_, i) => answers[i] ?? -1,
                                            ),
                                        },
                                        { preserveScroll: true },
                                    );
                                }}
                            >
                                {lesson.questions.map((q, i) => {
                                    const r =
                                        quiz?.lesson === lesson.id
                                            ? quiz.results[i]
                                            : null;
                                    return (
                                        <fieldset
                                            key={i}
                                            className="grid gap-1"
                                        >
                                            <legend className="font-medium">
                                                {i + 1}. {q.q}
                                            </legend>
                                            {q.options.map((o, j) => (
                                                <label
                                                    key={j}
                                                    className="flex items-center gap-2"
                                                >
                                                    <input
                                                        type="radio"
                                                        className="size-5"
                                                        name={`q${i}`}
                                                        checked={
                                                            answers[i] === j
                                                        }
                                                        onChange={() =>
                                                            setAnswers({
                                                                ...answers,
                                                                [i]: j,
                                                            })
                                                        }
                                                    />
                                                    {o}
                                                </label>
                                            ))}
                                            {r && (
                                                <p
                                                    className={
                                                        r.correct
                                                            ? 'text-green-800'
                                                            : 'text-red-800'
                                                    }
                                                >
                                                    {r.correct
                                                        ? 'Right. '
                                                        : `Not quite — the answer is “${q.options[r.answer]}”. `}
                                                    {r.why}
                                                </p>
                                            )}
                                        </fieldset>
                                    );
                                })}
                                {quiz?.lesson === lesson.id && (
                                    <p role="status" className="font-medium">
                                        You scored {quiz.score}%.{' '}
                                        {quiz.passed
                                            ? 'You passed.'
                                            : `You need ${quiz.pass_mark}% to pass — read the reasons above and try again.`}
                                    </p>
                                )}
                                {enrolment && (
                                    <Button className="justify-self-start">
                                        Check my answers
                                    </Button>
                                )}
                            </form>
                        )}
                        <div className="flex flex-wrap gap-2">
                            {at > 0 && (
                                <Button
                                    variant="outline"
                                    onClick={() => setAt(at - 1)}
                                >
                                    Back
                                </Button>
                            )}
                            {enrolment &&
                                lesson.kind !== 'quiz' &&
                                !enrolment.done.includes(lesson.id) && (
                                    <Button
                                        onClick={() =>
                                            router.post(
                                                `${base}/lessons/${lesson.id}/done`,
                                                {},
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () =>
                                                        setAt(
                                                            Math.min(
                                                                at + 1,
                                                                lessons.length -
                                                                    1,
                                                            ),
                                                        ),
                                                },
                                            )
                                        }
                                    >
                                        I have read this — next
                                    </Button>
                                )}
                            {at < lessons.length - 1 && (
                                <Button
                                    variant="ghost"
                                    onClick={() => setAt(at + 1)}
                                >
                                    Skip to next
                                </Button>
                            )}
                            {course.status !== 'published' && (
                                <Badge variant="outline">Draft preview</Badge>
                            )}
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}
