import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Quiz = {
    place: string;
    name: string;
    questions: { key: string; text: string; choices: string[] }[];
};

type Result = {
    score: number;
    total: number;
    right: Record<string, string>;
    streak: number;
};

/** LN4 (E20): a short daily quiz on the agent's own area, from today's real MLS numbers. */
export default function Market({
    places,
    place,
    quiz,
    streak,
    result,
}: {
    places: { slug: string; name: string }[];
    place: string | null;
    quiz: Quiz | null;
    streak: number;
    result: Result | null;
}) {
    const [answers, setAnswers] = useState<Record<string, string>>({});

    return (
        <>
            <Head title="Know your market" />
            <div className="mx-auto grid w-full max-w-2xl gap-6 p-4 text-base md:p-6">
                <PageHeader
                    title="Know your market"
                    description="Five questions a day from today's MLS numbers, so you can answer clients without looking it up."
                />
                <p>
                    {streak > 0
                        ? `${streak} ${streak === 1 ? 'day' : 'days'} in a row. Keep it going.`
                        : 'Take today’s quiz to start a streak.'}
                </p>
                {places.length === 0 ? (
                    <p className="text-muted-foreground">
                        The quiz needs MLS listings. Once your brokerage has an
                        MLS connected, your areas appear here.
                    </p>
                ) : (
                    <label className="grid max-w-sm gap-1">
                        Area
                        <select
                            className="h-11 rounded-md border bg-background px-3"
                            value={place ?? ''}
                            onChange={(e) =>
                                router.get('/learn/market', {
                                    place: e.target.value,
                                })
                            }
                        >
                            {places.map((p) => (
                                <option key={p.slug} value={p.slug}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                    </label>
                )}
                {result && (
                    <p role="status" className="rounded-md border p-3 text-lg">
                        You got <strong>{result.score}</strong> of{' '}
                        {result.total} right.
                    </p>
                )}
                {quiz && (
                    <form
                        className="grid gap-5"
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.post('/learn/market', {
                                place: quiz.place,
                                answers,
                            });
                        }}
                    >
                        {quiz.questions.map((q, i) => (
                            <fieldset key={q.key} className="grid gap-2">
                                <legend className="mb-1 font-medium">
                                    {i + 1}. {q.text}
                                </legend>
                                {q.choices.map((c) => {
                                    const right = result?.right[q.key];
                                    return (
                                        <label
                                            key={c}
                                            className="flex items-center gap-2"
                                        >
                                            <input
                                                type="radio"
                                                className="size-5"
                                                name={q.key}
                                                checked={answers[q.key] === c}
                                                onChange={() =>
                                                    setAnswers({
                                                        ...answers,
                                                        [q.key]: c,
                                                    })
                                                }
                                            />
                                            {c}
                                            {right === c && (
                                                <span className="text-sm font-medium text-green-700">
                                                    (right answer)
                                                </span>
                                            )}
                                        </label>
                                    );
                                })}
                            </fieldset>
                        ))}
                        <Button className="justify-self-start">
                            Check my answers
                        </Button>
                    </form>
                )}
            </div>
        </>
    );
}
