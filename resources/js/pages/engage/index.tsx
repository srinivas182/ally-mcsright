import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Brief = {
    key: string;
    title: string;
    why: string;
    action: string;
    url: string;
    urgency: number;
};
type Plan = {
    goal: { closings: number; per_day: number; done_today: number } | null;
    calls: number;
    tasks_due: number;
    tasks_late: number;
    brief: Brief[];
    drill: { title: string; done: boolean; streak: number };
    coach: {
        focus: string;
        blocks: { when: string; what: string }[];
        encouragement: string;
    } | null;
};
type CheckIn = {
    ulid: string;
    to: string;
    status: string;
    subject: string;
    body: string;
    intent: string | null;
    brief: string | null;
    suggested_reply: string | null;
    conversation: string | null;
};

function Draft({ c }: { c: CheckIn }) {
    const [subject, setSubject] = useState(c.subject);
    const [body, setBody] = useState(c.body);
    return (
        <div className="grid gap-2 rounded border p-3">
            <p className="font-medium">To {c.to}</p>
            <input
                aria-label={`Subject for ${c.to}`}
                className="rounded border px-3 py-2"
                value={subject}
                onChange={(e) => setSubject(e.target.value)}
            />
            <textarea
                aria-label={`Email to ${c.to}`}
                rows={6}
                className="rounded border px-3 py-2"
                value={body}
                onChange={(e) => setBody(e.target.value)}
            />
            <span className="flex gap-2">
                <Button
                    onClick={() =>
                        router.post(
                            `/engage/checkins/${c.ulid}/send`,
                            { subject, body },
                            { preserveScroll: true },
                        )
                    }
                >
                    Send from my Inbox
                </Button>
                <Button
                    variant="ghost"
                    onClick={() =>
                        router.post(
                            `/engage/checkins/${c.ulid}/skip`,
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    Skip
                </Button>
            </span>
        </div>
    );
}

/** Game plan (EN1): today at a glance, the coach, the database assistant and messages from the broker. */
export default function EngageIndex({
    plan,
    assistant,
    checkins,
    recognitions,
    focus,
}: {
    plan: Plan;
    assistant: {
        active: boolean;
        weekly: number;
        audience: string;
        about: string | null;
    };
    checkins: CheckIn[];
    recognitions: {
        ulid: string;
        occasion: string;
        text: string | null;
        audio: string;
        at: string;
    }[];
    focus: string;
}) {
    const settings = useForm({ ...assistant, about: assistant.about ?? '' });
    const drafts = checkins.filter((c) => c.status === 'draft');
    const replies = checkins.filter((c) => c.status === 'replied');

    return (
        <>
            <Head title="Game plan" />
            <div className="mx-auto grid w-full max-w-5xl gap-6 p-4 text-base md:p-6">
                <PageHeader
                    title="Game plan"
                    description="What today is for, and the people worth a hello this week."
                />
                <section className="grid gap-3 sm:grid-cols-4">
                    <a
                        href="/today"
                        className="grid rounded-lg border p-3 hover:bg-muted/40"
                    >
                        <span className="text-2xl font-semibold">
                            {plan.calls}
                        </span>
                        <span className="text-sm">
                            people to call (Today's 10)
                        </span>
                        {plan.goal && (
                            <span className="text-xs text-muted-foreground">
                                {plan.goal.done_today} of {plan.goal.per_day}{' '}
                                conversations done
                            </span>
                        )}
                    </a>
                    <a
                        href="/tasks"
                        className="grid rounded-lg border p-3 hover:bg-muted/40"
                    >
                        <span className="text-2xl font-semibold">
                            {plan.tasks_due}
                        </span>
                        <span className="text-sm">tasks due today</span>
                        {plan.tasks_late > 0 && (
                            <span className="text-xs text-red-700">
                                {plan.tasks_late} late
                            </span>
                        )}
                    </a>
                    <a
                        href="/learn/practice"
                        className="grid rounded-lg border p-3 hover:bg-muted/40"
                    >
                        <span className="text-sm font-medium">
                            5-minute drill
                        </span>
                        <span className="text-sm">{plan.drill.title}</span>
                        <span className="text-xs text-muted-foreground">
                            {plan.drill.done ? 'Done today' : 'Not done yet'}
                            {plan.drill.streak > 0 &&
                                ` · ${plan.drill.streak}-day streak`}
                        </span>
                    </a>
                    <div className="grid rounded-lg border p-3">
                        <span className="text-sm font-medium">Coach</span>
                        {plan.coach ? (
                            <span className="text-sm">{plan.coach.focus}</span>
                        ) : (
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        '/engage/coach',
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Coach me on today
                            </Button>
                        )}
                    </div>
                </section>
                {plan.coach && (
                    <section
                        className="grid gap-2 rounded-lg border p-4"
                        aria-label="Today's plan from your coach"
                    >
                        <h2 className="text-lg font-semibold">
                            Your day, in blocks
                        </h2>
                        <ul className="grid gap-1">
                            {plan.coach.blocks.map((b, i) => (
                                <li key={i}>
                                    <strong>{b.when}</strong> — {b.what}
                                </li>
                            ))}
                        </ul>
                        <p className="text-muted-foreground">
                            {plan.coach.encouragement}
                        </p>
                    </section>
                )}
                {plan.brief.length > 0 && (
                    <section className="grid gap-2">
                        <h2 className="text-lg font-semibold">
                            Most important first
                        </h2>
                        {plan.brief.map((b) => (
                            <a
                                key={b.key}
                                href={b.url}
                                className="flex flex-wrap items-center gap-2 rounded border p-3 hover:bg-muted/40"
                            >
                                {b.urgency === 3 && (
                                    <Badge variant="destructive">Today</Badge>
                                )}
                                <span className="font-medium">{b.title}</span>
                                <span className="text-sm text-muted-foreground">
                                    {b.why}
                                </span>
                            </a>
                        ))}
                    </section>
                )}
                {replies.length > 0 && (
                    <section className="grid gap-2">
                        <h2 className="text-lg font-semibold">
                            Replies to your check-ins
                        </h2>
                        {replies.map((c) => (
                            <div
                                key={c.ulid}
                                className={`grid gap-1 rounded border p-3 ${focus === c.ulid ? 'ring-2 ring-primary' : ''}`}
                            >
                                <p className="flex flex-wrap items-center gap-2">
                                    <strong>{c.to}</strong>
                                    {c.intent && <Badge>{c.intent}</Badge>}
                                </p>
                                {c.brief && <p>{c.brief}</p>}
                                {c.suggested_reply && (
                                    <details>
                                        <summary className="cursor-pointer text-sm">
                                            Suggested reply
                                        </summary>
                                        <p className="mt-1 text-sm whitespace-pre-wrap">
                                            {c.suggested_reply}
                                        </p>
                                    </details>
                                )}
                                {c.conversation && (
                                    <a
                                        className="text-sm text-primary underline"
                                        href={`/inbox?c=${c.conversation}`}
                                    >
                                        Answer in your Inbox
                                    </a>
                                )}
                            </div>
                        ))}
                    </section>
                )}
                <section className="grid gap-3 rounded-lg border p-4">
                    <h2 className="text-lg font-semibold">
                        Database assistant
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Each Monday it writes a few short, personal check-in
                        emails to people you have not been in touch with for 90
                        days. You read and send each one; nothing goes without
                        you. Replies come back to your Inbox, and it tells you
                        what each person wants. Email only.
                    </p>
                    <form
                        className="grid gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            settings.post('/engage/assistant', {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-5"
                                checked={settings.data.active}
                                onChange={(e) =>
                                    settings.setData('active', e.target.checked)
                                }
                            />
                            Turn on the database assistant
                        </label>
                        <span className="flex flex-wrap gap-4">
                            <label className="flex items-center gap-2">
                                Who
                                <select
                                    className="rounded border px-2 py-1"
                                    value={settings.data.audience}
                                    onChange={(e) =>
                                        settings.setData(
                                            'audience',
                                            e.target.value,
                                        )
                                    }
                                >
                                    <option value="past_clients">
                                        Past clients
                                    </option>
                                    <option value="sphere">
                                        People I know (sphere)
                                    </option>
                                    <option value="both">Both</option>
                                </select>
                            </label>
                            <label className="flex items-center gap-2">
                                Per week
                                <input
                                    type="number"
                                    min={1}
                                    max={20}
                                    className="w-20 rounded border px-2 py-1"
                                    value={settings.data.weekly}
                                    onChange={(e) =>
                                        settings.setData(
                                            'weekly',
                                            Number(e.target.value),
                                        )
                                    }
                                />
                            </label>
                        </span>
                        <input
                            aria-label="Something to mention this season (optional)"
                            className="rounded border px-3 py-2"
                            placeholder="Optional: something to mention, e.g. our office's coat drive in December"
                            value={settings.data.about}
                            onChange={(e) =>
                                settings.setData('about', e.target.value)
                            }
                        />
                        <span className="flex gap-2">
                            <Button disabled={settings.processing}>Save</Button>
                            {assistant.active && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            '/engage/assistant/draft',
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Write this week's now
                                </Button>
                            )}
                        </span>
                    </form>
                    {drafts.map((c) => (
                        <Draft key={c.ulid} c={c} />
                    ))}
                </section>
                {recognitions.length > 0 && (
                    <section className="grid gap-2">
                        <h2 className="text-lg font-semibold">
                            From your broker
                        </h2>
                        {recognitions.map((r) => (
                            <a
                                key={r.ulid}
                                href={`/engage/recognitions/${r.ulid}`}
                                className="rounded border p-3 hover:bg-muted/40"
                            >
                                <span className="font-medium">
                                    {r.occasion}
                                </span>{' '}
                                · {r.at}
                                <span className="block text-sm">{r.text}</span>
                            </a>
                        ))}
                    </section>
                )}
            </div>
        </>
    );
}
