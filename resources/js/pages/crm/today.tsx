import { Head, Link, useForm } from '@inertiajs/react';
import {
    MailIcon,
    MessageSquareIcon,
    PhoneIcon,
    SparklesIcon,
} from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';

type Pick = {
    id: number;
    ulid: string;
    name: string;
    points: number;
    reason: string;
    more: string[];
    phone: string | null;
    email: string | null;
    can_call: boolean;
    can_text: boolean;
    has_brief: boolean;
};

type Goal = { closings: number; per_day: number; done_today: number } | null;

/** The goal planner, the short way: "how many closings this year?" sets how many people to call each day. */
function GoalCard({ goal }: { goal: Goal }) {
    const form = useForm({ closings: goal ? String(goal.closings) : '' });
    const save = (
        <form
            className="flex flex-wrap items-end gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/insights/plan', { preserveScroll: true });
            }}
        >
            <label className="grid gap-1 text-sm font-medium">
                Closings I want this year
                <Input
                    type="number"
                    min={1}
                    max={1000}
                    inputMode="numeric"
                    className="w-32 text-lg"
                    value={form.data.closings}
                    onChange={(e) => form.setData('closings', e.target.value)}
                />
            </label>
            <Button size="lg" disabled={form.processing || !form.data.closings}>
                {goal ? 'Update my goal' : 'Set my goal'}
            </Button>
        </form>
    );

    return (
        <section
            id="goal"
            className="grid gap-3 rounded-lg border p-4"
            aria-labelledby="goal-title"
        >
            {goal ? (
                <>
                    <h2 id="goal-title" className="text-lg font-semibold">
                        {goal.done_today} of {goal.per_day} conversations today
                    </h2>
                    <div
                        className="h-3 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        aria-valuemin={0}
                        aria-valuemax={goal.per_day}
                        aria-valuenow={Math.min(goal.done_today, goal.per_day)}
                        aria-label="Conversations today"
                    >
                        <div
                            className="h-full bg-primary"
                            style={{
                                width: `${Math.min(100, (100 * goal.done_today) / goal.per_day)}%`,
                            }}
                        />
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Your goal is {goal.closings} closings this year. Calls,
                        texts, emails and meetings all count.
                    </p>
                    <details>
                        <summary className="cursor-pointer text-sm underline">
                            Change my goal
                        </summary>
                        <div className="pt-2">{save}</div>
                    </details>
                </>
            ) : (
                <>
                    <h2 id="goal-title" className="text-lg font-semibold">
                        What's your goal this year?
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Tell us how many closings you want. RightAlly works out
                        how many people to talk to each day and lines them up
                        here.
                    </p>
                    {save}
                </>
            )}
        </section>
    );
}

/** Who to call today and why. */
export default function Today({
    picks,
    goal = null,
}: {
    picks: Pick[];
    goal?: Goal;
}) {
    return (
        <>
            <Head title="Today's 10" />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="Today's 10"
                    description="The people most worth your time today, and why. Talk to someone and they come off the list."
                    actions={
                        picks.length > 0 && (
                            <Button asChild>
                                <Link href="/contacts/work?list=today">
                                    Start calling
                                </Link>
                            </Button>
                        )
                    }
                />
                <GoalCard goal={goal} />
                {picks.length === 0 ? (
                    <EmptyState
                        title="All done for today"
                        description="Nothing urgent. A good moment to work a keep-in-touch list."
                    />
                ) : (
                    <ol className="grid gap-3" data-tour="today-list">
                        {picks.map((p, i) => (
                            <li
                                key={p.ulid}
                                className="grid gap-2 rounded-lg border p-4"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <Link
                                        href={`/contacts/${p.ulid}`}
                                        className="text-lg font-semibold hover:underline"
                                    >
                                        {i + 1}. {p.name}
                                    </Link>
                                    {p.has_brief && (
                                        <Badge
                                            variant="secondary"
                                            className="gap-1"
                                        >
                                            <SparklesIcon
                                                aria-hidden="true"
                                                className="size-3"
                                            />{' '}
                                            Brief ready
                                        </Badge>
                                    )}
                                </div>
                                <p className="text-sm">{p.reason}</p>
                                {p.more.length > 0 && (
                                    <p className="text-xs text-muted-foreground">
                                        Also: {p.more.join(' · ')}
                                    </p>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    {p.phone && p.can_call && (
                                        <Button size="sm" asChild>
                                            <a href={`tel:${p.phone}`}>
                                                <PhoneIcon aria-hidden="true" />{' '}
                                                Call
                                            </a>
                                        </Button>
                                    )}
                                    {p.phone && p.can_text && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            asChild
                                        >
                                            <a href={`sms:${p.phone}`}>
                                                <MessageSquareIcon aria-hidden="true" />{' '}
                                                Text
                                            </a>
                                        </Button>
                                    )}
                                    {p.email && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            asChild
                                        >
                                            <a href={`mailto:${p.email}`}>
                                                <MailIcon aria-hidden="true" />{' '}
                                                Email
                                            </a>
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ol>
                )}
            </div>
        </>
    );
}

Today.layout = { breadcrumbs: [{ title: "Today's 10", href: '/today' }] };
