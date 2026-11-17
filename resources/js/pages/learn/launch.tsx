import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Step = {
    key: string;
    day: number;
    title: string;
    how: string;
    who: string;
    due: string;
    done: boolean;
    late: boolean;
    can_tick: boolean;
};
type Launch = {
    id: number;
    agent: string;
    mentor: string | null;
    started_on: string;
    finished: boolean;
    steps: Step[];
};
type Skill = {
    skill: string;
    label: string;
    courses: number;
    finished: number;
    score: number | null;
    practice: number | null;
    next: { ulid: string; title: string } | null;
};

function Steps({ launch }: { launch: Launch }) {
    return (
        <ol className="grid gap-2">
            {launch.steps.map((s) => (
                <li
                    key={s.key}
                    className={`flex flex-wrap items-start gap-3 rounded border p-3 ${s.done ? 'bg-green-50/60 dark:bg-green-950/20' : ''}`}
                >
                    <span className="min-w-14 text-sm text-muted-foreground">
                        Day {s.day}
                    </span>
                    <span className="grid flex-1">
                        <span className="font-medium">
                            {s.done ? '✓ ' : ''}
                            {s.title}
                        </span>
                        <span className="text-sm text-muted-foreground">
                            {s.how}
                        </span>
                    </span>
                    {s.late && (
                        <Badge variant="destructive">
                            Late · was due {s.due}
                        </Badge>
                    )}
                    {s.can_tick && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    `/learn/launch/${launch.id}/steps/${s.key}`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Mark done
                        </Button>
                    )}
                    {!s.done && s.who === 'auto' && (
                        <span className="text-xs text-muted-foreground">
                            Ticks itself when done
                        </span>
                    )}
                </li>
            ))}
        </ol>
    );
}

/** Learn-IQ (LN2): the New Agent Launch, mentors, the skill scorecard, and (for the broker) launches and required training. */
export default function LearnLaunch({
    mine,
    mentoring,
    launches,
    scorecard,
    mentor,
    manages,
    agents,
    suggested,
    gates,
    courses,
}: {
    mine: Launch | null;
    mentoring: Launch[];
    launches: Launch[];
    scorecard: Skill[];
    mentor: {
        strengths: string | null;
        max_mentees: number;
        active: boolean;
    } | null;
    manages: boolean;
    agents: { id: number; name: string }[];
    suggested: {
        id: number;
        name: string;
        strengths: string | null;
        mentees: number;
    }[];
    gates: Record<string, { label: string; course: string | null }>;
    courses: { ulid: string; title: string }[];
}) {
    const offer = useForm({
        active: mentor?.active ?? true,
        strengths: mentor?.strengths ?? '',
        max_mentees: mentor?.max_mentees ?? 2,
    });
    const [agent, setAgent] = useState('');
    const [pickedMentor, setPickedMentor] = useState('');

    return (
        <>
            <Head title="New agents and mentors" />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 text-base md:p-6">
                <PageHeader
                    title="New agents and mentors"
                    description="A 30-day launch plan with a mentor, and a scorecard of what to learn next."
                />
                {mine && (
                    <section className="grid gap-2">
                        <h2 className="text-lg font-semibold">
                            Your 30-day launch
                            {mine.mentor ? ` · mentor: ${mine.mentor}` : ''}
                        </h2>
                        {mine.finished && (
                            <p className="text-green-800">
                                Finished — well done!
                            </p>
                        )}
                        <Steps launch={mine} />
                    </section>
                )}
                {mentoring.length > 0 && (
                    <section className="grid gap-3">
                        <h2 className="text-lg font-semibold">
                            People you mentor
                        </h2>
                        {mentoring.map((l) => (
                            <details
                                key={l.id}
                                className="rounded border p-3"
                                open
                            >
                                <summary className="cursor-pointer font-medium">
                                    {l.agent} · started {l.started_on}
                                </summary>
                                <div className="mt-2">
                                    <Steps launch={l} />
                                </div>
                            </details>
                        ))}
                    </section>
                )}
                <section className="grid gap-2">
                    <h2 className="text-lg font-semibold">
                        Your skill scorecard
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Only you and your broker see this. It shows what to
                        learn next.
                    </p>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {scorecard.map((s) => (
                            <div
                                key={s.skill}
                                className="grid gap-1 rounded border p-3"
                            >
                                <span className="font-medium">{s.label}</span>
                                <span className="text-sm">
                                    {s.courses === 0
                                        ? 'No courses yet'
                                        : `${s.finished} of ${s.courses} courses finished`}
                                    {s.score !== null &&
                                        ` · quiz average ${s.score}%`}
                                    {s.practice !== null &&
                                        ` · practice ${s.practice}%`}
                                </span>
                                {s.next && (
                                    <a
                                        className="text-sm text-primary underline"
                                        href={`/learn/courses/${s.next.ulid}`}
                                    >
                                        Next: {s.next.title}
                                    </a>
                                )}
                            </div>
                        ))}
                    </div>
                </section>
                <section className="grid gap-2 rounded-lg border p-4">
                    <h2 className="text-lg font-semibold">Offer to mentor</h2>
                    <form
                        className="grid gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            offer.post('/learn/mentor', {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-5"
                                checked={offer.data.active}
                                onChange={(e) =>
                                    offer.setData('active', e.target.checked)
                                }
                            />
                            I am happy to mentor new agents
                        </label>
                        <input
                            aria-label="What you are good at"
                            className="rounded border px-3 py-2"
                            placeholder="What you are good at, e.g. listings, first-time buyers"
                            value={offer.data.strengths}
                            onChange={(e) =>
                                offer.setData('strengths', e.target.value)
                            }
                        />
                        <label className="flex items-center gap-2">
                            At most
                            <select
                                className="rounded border px-2 py-1"
                                value={offer.data.max_mentees}
                                onChange={(e) =>
                                    offer.setData(
                                        'max_mentees',
                                        Number(e.target.value),
                                    )
                                }
                            >
                                {[1, 2, 3, 4, 5].map((n) => (
                                    <option key={n} value={n}>
                                        {n}
                                    </option>
                                ))}
                            </select>
                            new agents at a time
                        </label>
                        <Button
                            className="justify-self-start"
                            disabled={offer.processing}
                        >
                            Save
                        </Button>
                    </form>
                </section>
                {manages && (
                    <>
                        <section className="grid gap-2 rounded-lg border p-4">
                            <h2 className="text-lg font-semibold">
                                Start a new agent's launch
                            </h2>
                            <form
                                className="flex flex-wrap items-end gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    router.post('/learn/launch', {
                                        agent: Number(agent),
                                        mentor: pickedMentor
                                            ? Number(pickedMentor)
                                            : null,
                                    });
                                }}
                            >
                                <label className="grid gap-1 text-sm">
                                    New agent
                                    <select
                                        className="rounded border px-2 py-2"
                                        value={agent}
                                        onChange={(e) =>
                                            setAgent(e.target.value)
                                        }
                                    >
                                        <option value="">Choose…</option>
                                        {agents.map((a) => (
                                            <option key={a.id} value={a.id}>
                                                {a.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="grid gap-1 text-sm">
                                    Mentor (suggested first)
                                    <select
                                        className="rounded border px-2 py-2"
                                        value={pickedMentor}
                                        onChange={(e) =>
                                            setPickedMentor(e.target.value)
                                        }
                                    >
                                        <option value="">No mentor yet</option>
                                        {suggested
                                            .filter(
                                                (m) => String(m.id) !== agent,
                                            )
                                            .map((m) => (
                                                <option key={m.id} value={m.id}>
                                                    {m.name}
                                                    {m.strengths
                                                        ? ` — ${m.strengths}`
                                                        : ''}{' '}
                                                    ({m.mentees} now)
                                                </option>
                                            ))}
                                    </select>
                                </label>
                                <Button disabled={!agent}>
                                    Start the 30-day launch
                                </Button>
                            </form>
                        </section>
                        {launches.length > 0 && (
                            <section className="grid gap-2">
                                <h2 className="text-lg font-semibold">
                                    Launches
                                </h2>
                                {launches.map((l) => {
                                    const done = l.steps.filter(
                                        (s) => s.done,
                                    ).length;
                                    const late = l.steps.filter(
                                        (s) => s.late,
                                    ).length;
                                    return (
                                        <details
                                            key={l.id}
                                            className="rounded border p-3"
                                        >
                                            <summary className="cursor-pointer">
                                                <strong>{l.agent}</strong> ·{' '}
                                                {done} of {l.steps.length} steps
                                                {late > 0 && ` · ${late} late`}
                                                {l.mentor &&
                                                    ` · mentor ${l.mentor}`}
                                            </summary>
                                            <div className="mt-2">
                                                <Steps launch={l} />
                                            </div>
                                        </details>
                                    );
                                })}
                            </section>
                        )}
                        <section className="grid gap-2 rounded-lg border p-4">
                            <h2 className="text-lg font-semibold">
                                Training required before a tool
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Agents see which course to finish first. Owners
                                are never blocked.
                            </p>
                            {Object.entries(gates).map(([tool, g]) => (
                                <label key={tool} className="grid gap-1">
                                    <span>{g.label}</span>
                                    <select
                                        className="rounded border px-2 py-2"
                                        value={g.course ?? ''}
                                        onChange={(e) =>
                                            router.post(
                                                '/learn/manage/gates',
                                                {
                                                    tool,
                                                    course:
                                                        e.target.value || null,
                                                },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <option value="">
                                            No course required
                                        </option>
                                        {courses.map((c) => (
                                            <option key={c.ulid} value={c.ulid}>
                                                {c.title}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            ))}
                        </section>
                    </>
                )}
            </div>
        </>
    );
}
