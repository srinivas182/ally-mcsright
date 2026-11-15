import { Link, router } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';

export type BriefItem = {
    key: string;
    source: string;
    title: string;
    why: string;
    action: string;
    url: string;
    urgency: number;
    stakes_cents: number | null;
};

const dollars = (cents: number) =>
    (cents / 100).toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 0,
    });

const when: Record<number, string> = {
    3: 'Now',
    2: 'This week',
    1: 'Good to know',
};

/**
 * The daily brief (specs/core/09): one ranked list of what needs the person, each item with why it matters and a
 * one-click next step. Ranked by what is due now, then the commission at stake.
 */
export function DailyBrief({
    items,
    email,
}: {
    items: BriefItem[];
    email: boolean;
}) {
    return (
        <section className="grid gap-3" aria-labelledby="brief-title">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="brief-title" className="text-lg font-semibold">
                    Your brief
                </h2>
                <label className="flex items-center gap-2 text-sm text-muted-foreground">
                    <input
                        type="checkbox"
                        className="size-4"
                        checked={email}
                        onChange={(e) =>
                            router.post(
                                '/brief/email',
                                { on: e.target.checked },
                                { preserveScroll: true },
                            )
                        }
                    />
                    Email it to me at 7am on weekdays
                </label>
            </div>
            {items.length === 0 ? (
                <EmptyState
                    title="Nothing needs you right now"
                    description="New leads, replies, deadlines, problems on deals, money and licences show up here as they need you."
                />
            ) : (
                <ol className="grid gap-2">
                    {items.map((i) => (
                        <li
                            key={i.key}
                            className={`grid gap-1 rounded-lg border p-3 sm:flex sm:items-center sm:justify-between sm:gap-4 ${i.urgency === 3 ? 'border-primary/50' : ''}`}
                        >
                            <div className="grid gap-1">
                                <p className="flex flex-wrap items-center gap-2 font-medium">
                                    {i.title}
                                    <Badge
                                        variant={
                                            i.urgency === 3
                                                ? 'default'
                                                : 'outline'
                                        }
                                    >
                                        {when[i.urgency] ?? 'Later'}
                                    </Badge>
                                    {i.stakes_cents !== null && (
                                        <span className="text-sm text-muted-foreground">
                                            {dollars(i.stakes_cents)} at stake
                                        </span>
                                    )}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {i.why}
                                </p>
                            </div>
                            <Button
                                asChild
                                variant={
                                    i.urgency === 3 ? 'default' : 'outline'
                                }
                                className="justify-self-start"
                            >
                                <Link href={i.url}>{i.action}</Link>
                            </Button>
                        </li>
                    ))}
                </ol>
            )}
        </section>
    );
}
