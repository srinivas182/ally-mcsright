import { Head, router, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Scenario = { key: string; title: string; skill: string; goal: string };
type Item = {
    ulid: string;
    kind: string;
    title: string;
    score: number | null;
    finished: boolean;
    at: string;
};

/** The role-play academy (LN3): today's drill, practice conversations, rehearsals and coaching cards. */
export default function Practice({
    scenarios,
    today,
    streak,
    history,
    team,
}: {
    scenarios: Scenario[];
    today: { key: string; title: string; done: boolean };
    streak: number;
    history: Item[];
    team: { name: string; sessions: number; average: number | null }[];
}) {
    const rehearse = useForm({ kind: 'rehearsal', brief: '' });
    const coach = useForm({ kind: 'call', text: '' });

    return (
        <>
            <Head title="Practice" />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 text-base md:p-6">
                <PageHeader
                    title="Practice"
                    description="Practise real conversations with a made-up client, by typing or speaking. Only you see your practice."
                />
                <section className="grid gap-2 rounded-lg border p-4">
                    <h2 className="text-lg font-semibold">
                        Today's five-minute drill
                    </h2>
                    <p>
                        {today.title}
                        {streak > 0 && (
                            <Badge className="ml-2">
                                {streak} day{streak === 1 ? '' : 's'} in a row
                            </Badge>
                        )}
                    </p>
                    {today.done ? (
                        <p className="text-green-800">
                            Done for today. See you tomorrow!
                        </p>
                    ) : (
                        <Button
                            className="justify-self-start"
                            onClick={() =>
                                router.post('/learn/practice', {
                                    kind: 'daily',
                                })
                            }
                        >
                            Start today's drill
                        </Button>
                    )}
                </section>
                <section className="grid gap-2">
                    <h2 className="text-lg font-semibold">
                        Practise a conversation
                    </h2>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {scenarios.map((s) => (
                            <button
                                key={s.key}
                                type="button"
                                className="grid gap-1 rounded-lg border p-3 text-left hover:bg-muted/40"
                                onClick={() =>
                                    router.post('/learn/practice', {
                                        kind: 'practice',
                                        scenario: s.key,
                                    })
                                }
                            >
                                <span className="font-medium">{s.title}</span>
                                <span className="text-sm text-muted-foreground">
                                    {s.goal}
                                </span>
                            </button>
                        ))}
                    </div>
                </section>
                <section className="grid gap-2 rounded-lg border p-4">
                    <h2 className="text-lg font-semibold">
                        Rehearse a real appointment
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Describe it in a sentence or two. No names needed. The
                        AI plays that client so you can rehearse.
                    </p>
                    <form
                        className="grid gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            rehearse.post('/learn/practice');
                        }}
                    >
                        <textarea
                            aria-label="Describe the appointment"
                            rows={3}
                            className="rounded border px-3 py-2"
                            placeholder="e.g. Listing appointment tomorrow with a retired couple who think their home is worth more than recent sales."
                            value={rehearse.data.brief}
                            onChange={(e) =>
                                rehearse.setData('brief', e.target.value)
                            }
                        />
                        {rehearse.errors.brief && (
                            <p role="alert" className="text-red-700">
                                {rehearse.errors.brief}
                            </p>
                        )}
                        <Button
                            className="justify-self-start"
                            disabled={rehearse.processing}
                        >
                            Start rehearsal
                        </Button>
                    </form>
                </section>
                <section className="grid gap-2 rounded-lg border p-4">
                    <h2 className="text-lg font-semibold">
                        Get coaching on a real call or a closed deal
                    </h2>
                    <form
                        className="grid gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            coach.post('/learn/practice/coach');
                        }}
                    >
                        <span className="flex flex-wrap gap-4">
                            <label className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    className="size-5"
                                    checked={coach.data.kind === 'call'}
                                    onChange={() =>
                                        coach.setData('kind', 'call')
                                    }
                                />
                                A call I just made
                            </label>
                            <label className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    className="size-5"
                                    checked={coach.data.kind === 'debrief'}
                                    onChange={() =>
                                        coach.setData('kind', 'debrief')
                                    }
                                />
                                A deal I just closed
                            </label>
                        </span>
                        <textarea
                            aria-label="Your notes"
                            rows={5}
                            className="rounded border px-3 py-2"
                            placeholder={
                                coach.data.kind === 'call'
                                    ? 'How did the call go? What did they say, and what did you say?'
                                    : 'What went well? What was hard? What would you do differently? What would you tell a new agent?'
                            }
                            value={coach.data.text}
                            onChange={(e) =>
                                coach.setData('text', e.target.value)
                            }
                        />
                        {coach.errors.text && (
                            <p role="alert" className="text-red-700">
                                {coach.errors.text}
                            </p>
                        )}
                        <Button
                            className="justify-self-start"
                            disabled={coach.processing}
                        >
                            Get my coaching card
                        </Button>
                    </form>
                </section>
                {history.length > 0 && (
                    <section className="grid gap-1">
                        <h2 className="text-lg font-semibold">Your practice</h2>
                        {history.map((h) => (
                            <a
                                key={h.ulid}
                                href={`/learn/practice/${h.ulid}`}
                                className="flex flex-wrap items-center gap-2 rounded px-2 py-1 hover:bg-muted/40"
                            >
                                <span className="text-sm text-muted-foreground">
                                    {h.at}
                                </span>
                                <span>{h.title}</span>
                                {h.score !== null && (
                                    <Badge variant="secondary">
                                        {h.score}%
                                    </Badge>
                                )}
                                {!h.finished && (
                                    <Badge variant="outline">
                                        Not finished
                                    </Badge>
                                )}
                            </a>
                        ))}
                    </section>
                )}
                {team.length > 0 && (
                    <section className="grid gap-1">
                        <h2 className="text-lg font-semibold">
                            Your team's practice (last 30 days)
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            You see effort and average scores only, never what
                            anyone said.
                        </p>
                        <table className="text-left text-sm">
                            <thead>
                                <tr>
                                    <th className="p-1">Person</th>
                                    <th className="p-1">Sessions</th>
                                    <th className="p-1">Average</th>
                                </tr>
                            </thead>
                            <tbody>
                                {team.map((t) => (
                                    <tr key={t.name}>
                                        <td className="p-1">{t.name}</td>
                                        <td className="p-1">{t.sessions}</td>
                                        <td className="p-1">
                                            {t.average !== null
                                                ? `${t.average}%`
                                                : '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                )}
            </div>
        </>
    );
}
