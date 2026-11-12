import { Head } from '@inertiajs/react';
import { PartyPopperIcon } from 'lucide-react';

import { EmptyState } from '@/components/ui/empty-state';
import { PageHeader } from '@/components/ui/page-header';
import { Progress } from '@/components/ui/progress';
import { usd } from '@/lib/cents';

type Me = {
    plan: string | null;
    cap_cents?: number | null;
    paid_cents?: number;
    to_cap_cents?: number | null;
    capped?: boolean;
    period?: { starts_on: string; ends_on: string } | null;
    pending_deals?: number;
    pending_take_home_cents?: number;
    forecast?: string | null;
};

/** The agent's own plan: distance to cap, pending take-home and forecast. */
export default function MyPlan({ me }: { me: Me }) {
    return (
        <>
            <Head title="My plan" />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    title="My plan"
                    description={me.plan ?? undefined}
                />
                {!me.plan ? (
                    <EmptyState
                        title="No plan yet"
                        description="Your broker hasn't assigned a compensation plan."
                    />
                ) : (
                    <>
                        {me.capped && (
                            <div
                                role="status"
                                className="flex items-center gap-3 rounded-lg border border-emerald-300 bg-emerald-50 p-4 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100"
                            >
                                <PartyPopperIcon aria-hidden="true" />
                                <span className="font-medium">
                                    You've capped! From now on you keep the full
                                    split (minus any post-cap fee) until your
                                    cap year resets.
                                </span>
                            </div>
                        )}
                        {me.cap_cents ? (
                            <section className="grid gap-2 rounded-lg border p-4">
                                <p className="text-sm text-muted-foreground">
                                    Toward your cap
                                </p>
                                <p className="text-2xl font-semibold">
                                    {usd(me.paid_cents)}{' '}
                                    <span className="text-base font-normal text-muted-foreground">
                                        of {usd(me.cap_cents)}
                                    </span>
                                </p>
                                <Progress
                                    value={Math.min(
                                        100,
                                        ((me.paid_cents ?? 0) / me.cap_cents) *
                                            100,
                                    )}
                                    aria-label="Progress to cap"
                                />
                                {!me.capped && (
                                    <p className="text-sm">
                                        {usd(me.to_cap_cents)} to go.
                                    </p>
                                )}
                                {me.forecast && (
                                    <p className="text-sm font-medium">
                                        {me.forecast}
                                    </p>
                                )}
                                {me.period && (
                                    <p className="text-xs text-muted-foreground">
                                        Cap year{' '}
                                        {new Date(
                                            me.period.starts_on,
                                        ).toLocaleDateString()}{' '}
                                        –{' '}
                                        {new Date(
                                            me.period.ends_on,
                                        ).toLocaleDateString()}
                                        . It resets after that.
                                    </p>
                                )}
                            </section>
                        ) : (
                            <p className="rounded-lg border p-4 text-sm">
                                Your plan has no cap.
                            </p>
                        )}
                        <section className="grid gap-1 rounded-lg border p-4">
                            <p className="text-sm text-muted-foreground">
                                Pending deals
                            </p>
                            <p className="text-2xl font-semibold">
                                {usd(me.pending_take_home_cents)}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                Expected take-home on {me.pending_deals ?? 0}{' '}
                                deal(s) not closed yet.
                            </p>
                        </section>
                    </>
                )}
            </div>
        </>
    );
}

MyPlan.layout = { breadcrumbs: [{ title: 'My plan', href: '/my-plan' }] };
