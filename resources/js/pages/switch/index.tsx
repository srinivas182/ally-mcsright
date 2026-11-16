import { Head } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';

type Connected = {
    app: string;
    key: string;
    status: string;
    brought: number;
    updated: number;
    last: string | null;
    error: string | null;
};
type Source = {
    apps: { key: string; name: string; href: string }[];
    connected: Connected[];
    total: number;
    imported_files?: number;
};
type Check = {
    key: string;
    ok: boolean;
    title: string;
    detail: string;
    href: string | null;
    fix: string | null;
};
type Step = {
    key: string;
    day: number;
    title: string;
    why: string;
    href: string;
    done: boolean;
    late: boolean;
};
type Plan = {
    started_on: string;
    day: number;
    done: number;
    total: number;
    adoption: { agents: number; signed_in: number; active_week: number };
    coach: { name: string | null; email: string | null; phone: string | null };
    steps: Step[];
} | null;

function SourceCard({
    id,
    title,
    noun,
    source,
    extra,
}: {
    id: string;
    title: string;
    noun: string;
    source: Source;
    extra: React.ReactNode;
}) {
    return (
        <section id={id} className="grid gap-3 rounded-lg border p-4">
            <div className="flex flex-wrap items-baseline gap-2">
                <h2 className="text-lg font-semibold">{title}</h2>
                <span className="text-muted-foreground">
                    {source.total.toLocaleString()} {noun} in RightAlly
                </span>
            </div>
            {source.connected.length > 0 && (
                <ul className="grid gap-2">
                    {source.connected.map((c) => (
                        <li
                            key={c.key}
                            className="flex flex-wrap items-center gap-2 rounded border p-2"
                        >
                            <strong>{c.app}</strong>
                            {c.status === 'connected' ? (
                                <Badge variant="outline">Connected</Badge>
                            ) : (
                                <Badge variant="destructive">
                                    Needs attention
                                </Badge>
                            )}
                            <span className="text-sm text-muted-foreground">
                                {c.brought.toLocaleString()} brought over
                                {c.updated > 0 &&
                                    `, ${c.updated.toLocaleString()} updated`}
                                {c.last &&
                                    ` · last ${new Date(c.last).toLocaleString()}`}
                            </span>
                            {c.error && (
                                <span className="w-full text-sm text-red-700">
                                    {c.error}
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            <div className="flex flex-wrap gap-2">
                {source.apps.map((a) => (
                    <Button key={a.key} variant="outline" size="lg" asChild>
                        <a href={a.href}>{a.name}</a>
                    </Button>
                ))}
                {extra}
            </div>
        </section>
    );
}

/**
 * Switch to RightAlly: bring people and deals over from the old apps, see the checks RightAlly runs after the move,
 * and follow the 30-day go-live plan with its coach.
 */
export default function SwitchIndex({
    overview,
    plan,
}: {
    overview: { people: Source; deals: Source; checks: Check[] };
    plan: Plan;
}) {
    const toFix = overview.checks.filter((c) => !c.ok).length;

    return (
        <>
            <Head title="Switch to RightAlly" />
            <div className="grid gap-5 p-4 text-base">
                <PageHeader
                    title="Switch to RightAlly"
                    description="Bring everything over in a weekend. Pick your old apps below; RightAlly brings the data and checks it for you."
                />

                {plan && (
                    <section className="grid gap-3 rounded-lg border p-4">
                        <div className="flex flex-wrap items-baseline gap-3">
                            <h2 className="text-lg font-semibold">
                                Your 30-day go-live plan
                            </h2>
                            <span className="text-muted-foreground">
                                Day {Math.min(plan.day, 30)} · {plan.done} of{' '}
                                {plan.total} done
                            </span>
                        </div>
                        <div
                            className="h-3 overflow-hidden rounded-full bg-muted"
                            role="progressbar"
                            aria-valuemin={0}
                            aria-valuemax={plan.total}
                            aria-valuenow={plan.done}
                            aria-label="Go-live progress"
                        >
                            <div
                                className="h-full bg-primary"
                                style={{
                                    width: `${(100 * plan.done) / plan.total}%`,
                                }}
                            />
                        </div>
                        <p className="text-sm text-muted-foreground">
                            Agents: {plan.adoption.agents} · signed in:{' '}
                            {plan.adoption.signed_in} · active this week:{' '}
                            {plan.adoption.active_week}
                        </p>
                        {plan.coach.name ? (
                            <p className="rounded bg-muted p-3">
                                Your coach: <strong>{plan.coach.name}</strong>
                                {plan.coach.email && (
                                    <>
                                        {' · '}
                                        <a
                                            className="underline"
                                            href={`mailto:${plan.coach.email}`}
                                        >
                                            {plan.coach.email}
                                        </a>
                                    </>
                                )}
                                {plan.coach.phone && (
                                    <>
                                        {' · '}
                                        <a
                                            className="underline"
                                            href={`tel:${plan.coach.phone}`}
                                        >
                                            {plan.coach.phone}
                                        </a>
                                    </>
                                )}
                            </p>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Your coach's name and contact details appear
                                here once assigned.
                            </p>
                        )}
                        <ol className="grid gap-2">
                            {plan.steps.map((s) => (
                                <li
                                    key={s.key}
                                    className={`flex flex-wrap items-center gap-3 rounded border p-3 ${s.done ? 'bg-emerald-50 dark:bg-emerald-950/30' : s.late ? 'border-amber-400' : ''}`}
                                >
                                    <span aria-hidden className="text-xl">
                                        {s.done ? '✓' : '○'}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <strong>
                                            Day {s.day}: {s.title}
                                        </strong>
                                        <span className="block text-sm text-muted-foreground">
                                            {s.why}
                                        </span>
                                    </span>
                                    {s.done ? (
                                        <Badge variant="outline">Done</Badge>
                                    ) : (
                                        <Button size="lg" asChild>
                                            <a href={s.href}>Do this</a>
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ol>
                    </section>
                )}

                <SourceCard
                    id="people"
                    title="1. Bring your people"
                    noun="people"
                    source={overview.people}
                    extra={
                        <Button variant="outline" size="lg" asChild>
                            <a href="/apps/csv_import">From a spreadsheet</a>
                        </Button>
                    }
                />
                <SourceCard
                    id="deals"
                    title="2. Bring your deals"
                    noun="deals"
                    source={overview.deals}
                    extra={
                        <Button variant="outline" size="lg" asChild>
                            <a href="/deals/bring-over-files">
                                From a file export
                            </a>
                        </Button>
                    }
                />

                <section
                    id="checks"
                    className="grid gap-3 rounded-lg border p-4"
                >
                    <h2 className="text-lg font-semibold">
                        3. Checks after the move{' '}
                        <span className="text-base font-normal text-muted-foreground">
                            {toFix === 0
                                ? '— all good'
                                : `— ${toFix} to look at`}
                        </span>
                    </h2>
                    <ul className="grid gap-2">
                        {overview.checks.map((c) => (
                            <li
                                key={c.key}
                                className={`grid gap-1 rounded border p-3 ${c.ok ? '' : 'border-amber-400 bg-amber-50 dark:bg-amber-950/30'}`}
                            >
                                <strong>
                                    {c.ok ? '✓ ' : '! '}
                                    {c.title}
                                </strong>
                                <span className="text-sm">{c.detail}</span>
                                {c.fix && c.href && (
                                    <a
                                        className="text-sm underline"
                                        href={c.href}
                                    >
                                        {c.fix}
                                    </a>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </>
    );
}
